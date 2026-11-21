<?php

namespace App\Domain\Finance;

use App\Models\PrizeAdvance;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money that has waited longer than the owner's limit (30 days unless changed) for a person to act on it
 * (owner decision, 2026-11-21). It feeds the readiness check `stale_holdings`, the daily owner notice and the
 * "Old money" list on the readiness page. Read-only: nothing here moves money.
 *
 * Kinds: commission held or pending for a partner who is not active; money left in a closed account; withdrawals
 * approved but not paid; withdrawals still waiting for a decision (or on hold); company fund spends not finished;
 * payment claims not checked; money a closed or suspended partner still owes from a refund (clawback); and a bike or
 * car prize paid in advance to a partner who is no longer active.
 */
final class StaleHoldings
{
    public const SettingKey = 'stale_holding_days';

    public const DefaultDays = 30;

    public const MaximumDays = 365;

    /** Every kind, in the order the list and the notice show them. */
    public const Kinds = ['held_commission', 'closed_balance', 'withdrawal_approved_unpaid', 'withdrawal_waiting', 'fund_spend_waiting', 'payment_claim_waiting', 'clawback_unrecovered', 'prize_advance_open'];

    /** @return array{days: int, version: int} */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['days' => (int) ($stored['days'] ?? self::DefaultDays), 'version' => (int) ($row->version ?? 0)];
    }

    public function days(): int
    {
        return max(1, $this->settings()['days']);
    }

    public static function canEdit(User $user): bool
    {
        return $user->status === 'active' && $user->owner() && $user->canAct('finance', 'edit');
    }

    public function save(User $actor, int $days, int $version): void
    {
        DB::transaction(function () use ($actor, $days, $version): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && self::canEdit($fresh), 403);
            $before = $this->settings();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            if ($days < 1 || $days > self::MaximumDays) {
                throw ValidationException::withMessages(['days' => __('money_core.stale_err_days', ['max' => (string) self::MaximumDays])]);
            }
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode(['days' => $days], JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'finance.stale_holding_days_changed', self::SettingKey, ['before' => ['days' => $before['days']], 'after' => ['days' => $days]]);
        }, 3);
    }

    /** True when anything has waited longer than the limit. */
    public function exist(): bool
    {
        return $this->items() !== [];
    }

    /**
     * Everything older than the limit, kind by kind, oldest first within a kind.
     *
     * @return list<array{kind: string, key: string, name: string, code: ?string, user_id: ?int, amount: string, since: string, days: int, href: string}>
     */
    public function items(): array
    {
        $cutoff = CarbonImmutable::now()->subDays($this->days());
        $items = [];
        foreach ([
            'held_commission' => $this->heldCommission($cutoff),
            'closed_balance' => $this->closedBalances($cutoff),
            'withdrawal_approved_unpaid' => $this->withdrawals($cutoff, 'approved'),
            'withdrawal_waiting' => $this->withdrawals($cutoff, 'requested'),
            'fund_spend_waiting' => $this->fundSpends($cutoff),
            'payment_claim_waiting' => $this->paymentClaims($cutoff),
            'clawback_unrecovered' => $this->clawbacks($cutoff),
            'prize_advance_open' => $this->prizeAdvances($cutoff),
        ] as $kind => $rows) {
            foreach ($rows as $row) {
                $since = CarbonImmutable::parse((string) $row->since, 'UTC');
                $items[] = [
                    'kind' => $kind,
                    'key' => $kind.':'.$row->ref,
                    'name' => (string) ($row->name ?? ''),
                    'code' => $row->code ?? null,
                    'user_id' => isset($row->user_id) ? (int) $row->user_id : null,
                    'amount' => Money::decimal(FundCatalog::centsOf($row->amount)),
                    'since' => $since->toIso8601String(),
                    'days' => (int) $since->diffInDays(now()),
                    'href' => (string) $row->href,
                ];
            }
        }

        return $items;
    }

    /**
     * Count and total per kind, only for kinds that have something.
     *
     * @param  list<array{kind: string, amount: string}>|null  $items
     * @return list<array{kind: string, count: int, amount: string}>
     */
    public function summary(?array $items = null): array
    {
        $byKind = [];
        foreach ($items ?? $this->items() as $item) {
            $byKind[$item['kind']] ??= ['kind' => $item['kind'], 'count' => 0, 'cents' => 0];
            $byKind[$item['kind']]['count']++;
            $byKind[$item['kind']]['cents'] += Money::cents($item['amount']);
        }
        $summary = [];
        foreach (self::Kinds as $kind) {
            if (isset($byKind[$kind])) {
                $summary[] = ['kind' => $kind, 'count' => $byKind[$kind]['count'], 'amount' => Money::decimal($byKind[$kind]['cents'])];
            }
        }

        return $summary;
    }

    /**
     * Everything the readiness page needs for the "Old money" list.
     *
     * @return array{days: int, version: int, default: int, can_edit: bool, can_write_off: bool, items: list<array<string, mixed>>, summary: list<array{kind: string, count: int, amount: string}>}
     */
    public function report(User $viewer): array
    {
        $items = $this->items();

        return [...$this->settings(), 'days' => $this->days(), 'default' => self::DefaultDays, 'can_edit' => self::canEdit($viewer),
            'can_write_off' => ClawbackWriteOff::canUse($viewer), 'items' => $items, 'summary' => $this->summary($items)];
    }

    /** Commission held or waiting for a partner who is not active, one line per partner. */
    private function heldCommission(CarbonImmutable $cutoff): iterable
    {
        return DB::table('commission_allocations as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.status', ['held', 'pending'])->where('u.status', '<>', 'active')->where('a.amount', '>', 0)->where('a.created_at', '<=', $cutoff)
            ->groupBy('u.id', 'u.name', 'u.dealer_code')->orderByRaw('MIN(a.created_at)')
            ->selectRaw("u.id AS ref, u.id AS user_id, u.name, u.dealer_code AS code, SUM(a.amount) AS amount, MIN(a.created_at) AS since, '/accounts/' || u.id::text AS href")
            ->get();
    }

    /** Closed accounts whose wallet still holds spendable money. */
    private function closedBalances(CarbonImmutable $cutoff): iterable
    {
        return DB::table('users as u')->join('wallets as w', 'w.user_id', '=', 'u.id')
            ->whereNotNull('u.closed_at')->where('u.status', '<>', 'active')->where('w.available', '>', 0)->where('u.closed_at', '<=', $cutoff)
            ->orderBy('u.closed_at')
            ->selectRaw("u.id AS ref, u.id AS user_id, u.name, u.dealer_code AS code, w.available AS amount, u.closed_at AS since, CASE WHEN u.role = 'sub_dealer' THEN '/sub-dealers?status=closed' ELSE '/accounts?status=closed' END AS href")
            ->get();
    }

    /** Withdrawals approved but not paid, or still waiting for a decision (on hold included). */
    private function withdrawals(CarbonImmutable $cutoff, string $status): iterable
    {
        $since = $status === 'approved' ? 'COALESCE(w.approved_at, w.updated_at)' : 'w.created_at';

        return DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('w.status', $status)->whereRaw("{$since} <= ?", [$cutoff])->orderByRaw($since)
            ->selectRaw("w.id::text AS ref, u.id AS user_id, u.name, u.dealer_code AS code, w.amount, {$since} AS since, '/withdrawals/' || w.id::text AS href")
            ->get();
    }

    /**
     * Bike or car prizes paid in advance to a partner who is no longer active: the prize can no longer settle them, so
     * the owner has to mark the money recovered or written off. An active partner's advance waits for his prize.
     */
    private function prizeAdvances(CarbonImmutable $cutoff): iterable
    {
        return DB::table('prize_advances as a')->join('users as u', 'u.id', '=', 'a.partner_id')
            ->where('a.status', PrizeAdvance::StatusOpen)->where('u.status', '<>', 'active')->where('a.given_on', '<=', $cutoff->toDateString())
            ->orderBy('a.given_on')->orderBy('a.id')
            ->selectRaw("a.id::text AS ref, u.id AS user_id, u.name, u.dealer_code AS code, a.amount - a.settled_amount AS amount, a.given_on::timestamp AS since, '/treasury/prize-advances' AS href")
            ->get();
    }

    /** Company fund spends and fund payouts asked for or approved but never paid. */
    private function fundSpends(CarbonImmutable $cutoff): iterable
    {
        return DB::table('reserve_disbursements as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->whereIn('r.status', ['requested', 'approved'])->where('r.created_at', '<=', $cutoff)->orderBy('r.created_at')
            ->selectRaw('r.id::text AS ref, u.id AS user_id, COALESCE(r.payee_name, u.name, r.program) AS name, u.dealer_code AS code, r.amount, r.created_at AS since, '
                ."CASE WHEN r.fund_key IS NOT NULL THEN '/treasury/funds/' || r.fund_key ELSE '/operations/reserve-payouts' END AS href")
            ->get();
    }

    /** Payments partners said they made that nobody has checked. */
    private function paymentClaims(CarbonImmutable $cutoff): iterable
    {
        return DB::table('payment_claims as c')->join('orders as o', 'o.id', '=', 'c.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('c.status', 'pending')->where('c.created_at', '<=', $cutoff)->orderBy('c.created_at')
            ->selectRaw("c.id::text AS ref, u.id AS user_id, u.name, u.dealer_code AS code, c.amount, c.created_at AS since, '/orders/' || o.id::text AS href")
            ->get();
    }

    /** What a closed or suspended partner still owes from a refund, by the ledger, since their last new debt. */
    private function clawbacks(CarbonImmutable $cutoff): iterable
    {
        $owed = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.account', ClawbackWriteOff::ReceivableAccount)->whereNotNull('l.user_id')->groupBy('l.user_id')
            ->selectRaw('l.user_id, SUM(l.debit - l.credit) AS owed, MAX(e.created_at) FILTER (WHERE l.debit > 0) AS last_debt');

        return DB::query()->fromSub($owed, 'd')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.status', '<>', 'active')->where('d.owed', '>', 0)->where('d.last_debt', '<=', $cutoff)->orderBy('d.last_debt')
            ->selectRaw("u.id AS ref, u.id AS user_id, u.name, u.dealer_code AS code, d.owed AS amount, d.last_debt AS since, '/operations/financial-readiness?view=holdings' AS href")
            ->get();
    }
}
