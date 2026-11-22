<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Models\CommissionAllocation;
use App\Models\PrizeAdvance;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A bike or car prize paid to a dealer or sub-dealer in advance, in an emergency (owner decision, 2026-11-22).
 *
 * Giving it: only the Super Admin. The money leaves that prize's own fund (the Bike or Car fund, then the old combined
 * fund's half for that prize) through a reserve_disbursements row (program prize_advance) and its fund sources, so the
 * fund pages and every balance see it. It never reaches the partner's wallet: the journal moves it from payout clearing
 * to the partner's prize-advance receivable.
 *
 * Settling it: when the partner later earns the same prize, the award ({@see ReserveService::review()}) first sets the
 * prize against the open advance (journal: prize expense against the receivable) and pays to the wallet, from the fund,
 * only what is left of the prize. An advance larger than the prize stays open for the rest.
 *
 * Closing it by hand: if the money comes back, or the company gives up on it, the Super Admin marks it recovered or
 * written off (bad-debt expense). Nothing closes an advance by itself, and the partner never sees any of this.
 */
final class PrizeAdvances
{
    public const Program = 'prize_advance';

    public const ReceivableAccount = 'prize_advance_receivable';

    /** The money left the company the way every hand-paid fund spend does. */
    public const PaidFromAccount = 'manual_payout_clearing';

    /** Money the partner brought back, by hand or by bank. */
    public const RecoveredToAccount = 'manual_collection_clearing';

    public const Outcomes = [PrizeAdvance::StatusRecovered, PrizeAdvance::StatusWrittenOff];

    public function __construct(private CommissionWallet $wallets, private VehiclePools $pools) {}

    /** Only the Super Admin gives, recovers or writes off an advance. */
    public static function canGive(User $user): bool
    {
        return $user->status === 'active' && $user->role === 'super_admin';
    }

    /** The owners and finance staff see advances; partners never do. */
    public static function canView(User $user): bool
    {
        return $user->status === 'active' && $user->staff() && ($user->owner() || $user->allows('finance'));
    }

    /**
     * Pays a prize in advance. A repeat with the same request key returns the first advance and moves nothing.
     *
     * @param  array{partner_id: int, kind: string, amount: string, reason: string, given_on: string, request_key: string}  $data
     */
    public function give(User $actor, array $data): PrizeAdvance
    {
        abort_unless(self::canGive($actor), 403);
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $data): PrizeAdvance {
            CommissionWallet::lock();
            abort_unless(self::canGive($actor->fresh() ?? $actor), 403);
            $earlier = PrizeAdvance::query()->where('request_key', $data['request_key'])->first();
            if ($earlier !== null) {
                abort_if((int) $earlier->partner_id !== (int) $data['partner_id'] || $earlier->kind !== $data['kind']
                    || Money::cents((string) $earlier->amount) !== Money::cents($data['amount']), 409, __('prize_advances.err_key'));

                return $earlier;
            }
            $kind = $data['kind'];
            if (! in_array($kind, VehiclePools::Programs, true)) {
                throw ValidationException::withMessages(['kind' => __('prize_advances.err_kind')]);
            }
            $amount = Money::cents($data['amount']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('prize_advances.err_amount')]);
            }
            $partner = User::query()->whereKey($data['partner_id'])->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->lockForUpdate()->first();
            if ($partner === null) {
                throw ValidationException::withMessages(['partner_id' => __('prize_advances.err_partner')]);
            }
            // The prize already asked for or paid: there is nothing left to pay in advance.
            if (DB::table('reserve_disbursements')->where('award_key', $kind.':'.$partner->id)->exists()) {
                throw ValidationException::withMessages(['partner_id' => __('prize_advances.err_already_awarded')]);
            }
            if (PrizeAdvance::query()->where('partner_id', $partner->id)->where('kind', $kind)->where('status', PrizeAdvance::StatusOpen)->exists()) {
                throw ValidationException::withMessages(['partner_id' => __('prize_advances.err_open_exists')]);
            }
            $balance = $this->pools->balances()[$kind];
            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => __('prize_advances.err_fund_short', ['balance' => Money::grouped(Money::decimal(max(0, $balance)))])]);
            }

            $disbursementId = DB::table('reserve_disbursements')->insertGetId([
                'program' => self::Program,
                'fund_key' => $kind,
                'user_id' => $partner->id,
                'amount' => Money::decimal($amount),
                'status' => 'paid',
                'request_key' => 'prize-advance:'.$data['request_key'],
                'reason' => $data['reason'],
                'spent_on' => $data['given_on'],
                'created_by' => $actor->id,
                'approved_by' => $actor->id,
                'paid_by' => $actor->id,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->drawFromPool($disbursementId, $kind, $amount);
            $advance = PrizeAdvance::query()->create([
                'partner_id' => $partner->id,
                'kind' => $kind,
                'amount' => Money::decimal($amount),
                'reason' => $data['reason'],
                'given_on' => $data['given_on'],
                'approved_by' => $actor->id,
                'status' => PrizeAdvance::StatusOpen,
                'disbursement_id' => $disbursementId,
                'request_key' => $data['request_key'],
            ]);
            $this->wallets->journal('prize_advance.given.'.$advance->id, __('prize_advances.jr_given', ['code' => (string) $partner->dealer_code, 'kind' => $this->kindWord($kind)]), [
                ['account' => self::ReceivableAccount, 'user_id' => $partner->id, 'debit' => $amount, 'credit' => 0],
                ['account' => self::PaidFromAccount, 'user_id' => null, 'debit' => 0, 'credit' => $amount],
            ]);
            Audit::record($actor->id, 'prize_advance.given', (string) $advance->id, [
                'partner_id' => $partner->id, 'dealer_code' => $partner->dealer_code, 'kind' => $kind, 'amount' => Money::decimal($amount),
                'fund_balance_before' => Money::decimal($balance), 'reason' => $data['reason'], 'given_on' => $data['given_on'],
                'disbursement_id' => $disbursementId, 'request_key' => $data['request_key'],
            ]);

            return $advance;
        }, 3);
    }

    /**
     * Sets an earned prize against the partner's open advance of the same kind. Runs inside the award's own transaction,
     * under the money lock. Returns the paisa of the prize the advance covered; the caller pays only the rest. The same
     * award asked again gets the same answer and posts nothing more.
     */
    public function settleAgainstAward(object $award, int $prize, ?int $actorId): int
    {
        if ($prize <= 0 || $award->user_id === null || ! in_array($award->program, VehiclePools::Programs, true)) {
            return 0;
        }
        $advance = PrizeAdvance::query()->where('partner_id', $award->user_id)->where('kind', $award->program)
            ->where(fn ($query) => $query->where('status', PrizeAdvance::StatusOpen)->orWhere('settled_by_award_id', $award->id))
            ->orderByRaw('settled_by_award_id IS NULL')->orderBy('id')->lockForUpdate()->first();
        if ($advance === null) {
            return 0;
        }
        if ((int) $advance->settled_by_award_id === (int) $award->id) {
            return Money::cents((string) $advance->settled_amount);
        }
        if ($advance->settled_by_award_id !== null) {
            return 0;
        }
        $outstanding = Money::cents((string) $advance->amount) - Money::cents((string) $advance->settled_amount);
        $offset = min($outstanding, $prize);
        if ($offset <= 0) {
            return 0;
        }
        $partner = User::query()->findOrFail($award->user_id);
        $this->wallets->journal('prize_advance.settled.'.$advance->id.'.'.$award->id, __('prize_advances.jr_settled', ['code' => (string) $partner->dealer_code, 'kind' => $this->kindWord($advance->kind)]), [
            ['account' => 'commission_expense', 'user_id' => null, 'debit' => $offset, 'credit' => 0],
            ['account' => self::ReceivableAccount, 'user_id' => $partner->id, 'debit' => 0, 'credit' => $offset],
        ]);
        $settled = Money::cents((string) $advance->settled_amount) + $offset;
        $advance->update([
            'settled_amount' => Money::decimal($settled),
            'settled_by_award_id' => $award->id,
            'settled_at' => now(),
            'status' => $settled === Money::cents((string) $advance->amount) ? PrizeAdvance::StatusSettled : PrizeAdvance::StatusOpen,
        ]);
        Audit::record($actorId, 'prize_advance.settled', (string) $advance->id, [
            'award_id' => $award->id, 'kind' => $advance->kind, 'prize' => Money::decimal($prize), 'set_against_advance' => Money::decimal($offset),
            'paid_to_wallet' => Money::decimal($prize - $offset), 'advance_left' => Money::decimal($outstanding - $offset),
        ]);

        return $offset;
    }

    /**
     * The Super Admin closes what is left of an open advance: the money came back (recovered, with its reference), or the
     * company gives up on it (written off to bad debt). Once only; a repeat with the same key changes nothing.
     */
    public function close(User $actor, int $advanceId, string $outcome, string $note, string $requestKey, ?string $reference = null): PrizeAdvance
    {
        abort_unless(self::canGive($actor), 403);
        abort_unless(in_array($outcome, self::Outcomes, true), 422);
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $advanceId, $outcome, $note, $requestKey, $reference): PrizeAdvance {
            CommissionWallet::lock();
            abort_unless(self::canGive($actor->fresh() ?? $actor), 403);
            $earlier = PrizeAdvance::query()->where('close_request_key', $requestKey)->first();
            if ($earlier !== null) {
                abort_unless((int) $earlier->id === $advanceId && $earlier->status === $outcome, 409, __('prize_advances.err_key'));

                return $earlier;
            }
            $advance = PrizeAdvance::query()->whereKey($advanceId)->lockForUpdate()->firstOrFail();
            $left = Money::cents((string) $advance->amount) - Money::cents((string) $advance->settled_amount);
            if ($advance->status !== PrizeAdvance::StatusOpen || $left <= 0) {
                throw ValidationException::withMessages(['advance' => __('prize_advances.err_not_open')]);
            }
            $partner = User::query()->findOrFail($advance->partner_id);
            $recovered = $outcome === PrizeAdvance::StatusRecovered;
            $this->wallets->journal('prize_advance.'.$outcome.'.'.$advance->id, __('prize_advances.jr_'.$outcome, ['code' => (string) $partner->dealer_code, 'kind' => $this->kindWord($advance->kind)]), [
                ['account' => $recovered ? self::RecoveredToAccount : ClawbackWriteOff::ExpenseAccount, 'user_id' => null, 'debit' => $left, 'credit' => 0],
                ['account' => self::ReceivableAccount, 'user_id' => $partner->id, 'debit' => 0, 'credit' => $left],
            ]);
            $advance->update([
                'status' => $outcome,
                'closed_amount' => Money::decimal($left),
                'recovery_reference' => $recovered ? $reference : null,
                'note' => $note,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'close_request_key' => $requestKey,
            ]);
            Audit::record($actor->id, 'prize_advance.'.$outcome, (string) $advance->id, [
                'partner_id' => $partner->id, 'dealer_code' => $partner->dealer_code, 'partner_status' => $partner->closed_at !== null ? 'closed' : $partner->status,
                'kind' => $advance->kind, 'amount' => Money::decimal($left), 'reason' => $note, 'reference' => $recovered ? $reference : null, 'request_key' => $requestKey,
            ]);

            return $advance;
        }, 3);
    }

    /**
     * Open advances, oldest first, optionally only of some kinds or of one partner.
     *
     * @param  list<string>|null  $kinds
     * @return list<array{id: int, partner_id: int, name: string, code: ?string, role: string, partner_active: bool, kind: string, amount: string, settled: string, left: string, given_on: string, days: int, reason: string}>
     */
    public function open(?array $kinds = null, ?int $partnerId = null): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();

        return DB::table('prize_advances as a')->join('users as u', 'u.id', '=', 'a.partner_id')
            ->where('a.status', PrizeAdvance::StatusOpen)
            ->when($kinds !== null, fn ($query) => $query->whereIn('a.kind', $kinds))
            ->when($partnerId !== null, fn ($query) => $query->where('a.partner_id', $partnerId))
            ->orderBy('a.given_on')->orderBy('a.id')
            ->get(['a.id', 'a.partner_id', 'u.name', 'u.dealer_code', 'u.role', 'u.status as partner_status', 'a.kind', 'a.amount', 'a.settled_amount', 'a.given_on', 'a.reason'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'partner_id' => (int) $row->partner_id,
                'name' => (string) $row->name,
                'code' => $row->dealer_code,
                'role' => (string) $row->role,
                'partner_active' => $row->partner_status === 'active',
                'kind' => (string) $row->kind,
                'amount' => Money::decimal(Money::cents((string) $row->amount)),
                'settled' => Money::decimal(Money::cents((string) $row->settled_amount)),
                'left' => Money::decimal(Money::cents((string) $row->amount) - Money::cents((string) $row->settled_amount)),
                'given_on' => (string) $row->given_on,
                'days' => (int) CarbonImmutable::parse((string) $row->given_on, 'Asia/Dhaka')->startOfDay()->diffInDays($today),
                'reason' => (string) $row->reason,
            ])->all();
    }

    /**
     * Advances that are no longer open, newest first.
     *
     * @return list<array{id: int, name: string, code: ?string, kind: string, amount: string, status: string, settled: string, closed: ?string, given_on: string, closed_on: ?string, note: ?string}>
     */
    public function finished(int $limit = 20): array
    {
        return DB::table('prize_advances as a')->join('users as u', 'u.id', '=', 'a.partner_id')
            ->where('a.status', '<>', PrizeAdvance::StatusOpen)
            ->orderByRaw('COALESCE(a.closed_at, a.settled_at, a.updated_at) DESC')->orderByDesc('a.id')->limit($limit)
            ->get(['a.id', 'u.name', 'u.dealer_code', 'a.kind', 'a.amount', 'a.status', 'a.settled_amount', 'a.closed_amount', 'a.given_on', 'a.closed_at', 'a.settled_at', 'a.note'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => $row->dealer_code,
                'kind' => (string) $row->kind,
                'amount' => Money::decimal(Money::cents((string) $row->amount)),
                'status' => (string) $row->status,
                'settled' => Money::decimal(Money::cents((string) $row->settled_amount)),
                'closed' => $row->closed_amount === null ? null : Money::decimal(Money::cents((string) $row->closed_amount)),
                'given_on' => (string) $row->given_on,
                'closed_on' => ($row->closed_at ?? $row->settled_at) === null ? null : CarbonImmutable::parse((string) ($row->closed_at ?? $row->settled_at), 'UTC')->toIso8601String(),
                'note' => $row->note,
            ])->all();
    }

    /**
     * What the give form needs: each prize's set amount and what its fund holds now.
     *
     * @return array<string, array{award: ?string, balance: string}>
     */
    public function fundFigures(): array
    {
        $figures = [];
        foreach ($this->pools->summary() as $kind => $pool) {
            $figures[$kind] = ['award' => $pool['award'], 'balance' => $pool['balance']];
        }

        return $figures;
    }

    /** The prize's name in a journal line. */
    private function kindWord(string $kind): string
    {
        return (string) __('prize_advances.kind_'.$kind);
    }

    /**
     * Takes the amount from the prize's own fund rows first, then from the old combined rows (their half for this prize),
     * oldest first, as an award does. Runs under the money lock.
     */
    private function drawFromPool(int $disbursementId, string $kind, int $amount): void
    {
        $left = $amount;
        foreach ($this->poolRows($kind) as $fund) {
            $take = min($left, $this->pools->remainingFor($fund, $kind));
            if ($take <= 0) {
                continue;
            }
            DB::table('reserve_expense_sources')->insert([
                'disbursement_id' => $disbursementId,
                'allocation_id' => $fund->id,
                'amount' => Money::decimal($take),
                'refunded_basis' => DB::table('commission_batches')->where('order_id', $fund->order_id)->value('refunded_amount') ?? '0.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $left -= $take;
            if ($left === 0) {
                return;
            }
        }
        throw ValidationException::withMessages(['amount' => __('prize_advances.err_fund_short', ['balance' => Money::grouped(Money::decimal($amount - $left))])]);
    }

    /** @return Collection<int, CommissionAllocation> */
    private function poolRows(string $kind): Collection
    {
        return CommissionAllocation::query()->where('category', VehiclePools::Category)
            ->whereRaw("(metadata->>'vehicle_pool' IS NULL OR metadata->>'vehicle_pool' = ?)", [$kind])
            ->where('status', '!=', 'settled')->where('amount', '>', 0)
            ->orderByRaw("(metadata->>'vehicle_pool' IS NULL)")->orderBy('id')->lockForUpdate()->get();
    }
}
