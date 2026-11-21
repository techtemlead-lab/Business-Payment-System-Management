<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Paying on money the company only holds for someone else: tax withheld from partner payouts
 * (ManualPayoutService credits `payout_withholding_payable`) and deductions kept from salaries
 * (PayrollService credits `payroll_deductions_payable`). Nothing else ever debits these two
 * accounts, so without this step they only grow.
 *
 * One person records the payment (amount, date, receipt number); a second person checks it and
 * only then the journal posts: debit the payable, credit `manual_payout_clearing`, the same
 * account every other company payment (payroll, fund spends) is paid from. The owner may finish
 * a payment they recorded themselves, as everywhere else ({@see SelfApproval}).
 */
final class PayableRemittances
{
    /** @var array<string, string> Kind => the journal account it pays down. */
    public const Accounts = [
        'withheld_tax' => 'payout_withholding_payable',
        'payroll_deductions' => 'payroll_deductions_payable',
    ];

    /** The account company payments are paid from. */
    public const PaidFromAccount = 'manual_payout_clearing';

    public function __construct(private CommissionWallet $wallets) {}

    public static function canManage(User $user): bool
    {
        return $user->status === 'active' && $user->allows('finance');
    }

    /**
     * Records a payment waiting for the second person. The same request key twice returns the
     * first record; the same key with other details is refused.
     *
     * @param  array{kind: string, amount: string, paid_on: string, reference?: ?string, note: string, request_key: string}  $data
     */
    public function request(User $actor, array $data): int
    {
        abort_unless(self::canManage($actor), 403);
        abort_unless(isset(self::Accounts[$data['kind']]), 422);
        $reference = $this->reference($data['reference'] ?? null);

        return DB::transaction(function () use ($actor, $data, $reference): int {
            CommissionWallet::lock();
            abort_unless(self::canManage($actor->fresh()), 403);
            $old = DB::table('payable_remittances')->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_if($old->kind !== $data['kind'] || Money::cents((string) $old->amount) !== Money::cents($data['amount']) || (int) $old->created_by !== (int) $actor->id, 409, __('accounting.err_payable_repeat_key'));

                return (int) $old->id;
            }
            $amount = Money::cents($data['amount']);
            if ($amount <= 0 || $amount > $this->figures($data['kind'])['available']) {
                throw ValidationException::withMessages(['amount' => __('accounting.err_payable_too_much')]);
            }
            $this->assertReferenceFree($data['kind'], $reference);
            $id = DB::table('payable_remittances')->insertGetId([
                'kind' => $data['kind'],
                'amount' => Money::decimal($amount),
                'paid_on' => $data['paid_on'],
                'reference' => $reference,
                'note' => trim($data['note']),
                'request_key' => $data['request_key'],
                'status' => 'requested',
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'payable.remittance_requested', (string) $id, ['kind' => $data['kind'], 'amount' => Money::decimal($amount), 'paid_on' => $data['paid_on'], 'reference' => $reference]);

            return $id;
        }, 3);
    }

    /** The second person approves (the journal posts) or refuses a recorded payment. */
    public function review(User $actor, int $id, string $decision, string $reason): void
    {
        abort_unless(self::canManage($actor), 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        if ($decision === 'approved') {
            CommissionWallet::requireActive();
        }
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            abort_unless(self::canManage($actor->fresh()), 403);
            $row = DB::table('payable_remittances')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->status !== 'requested') {
                throw ValidationException::withMessages(['remittance' => __('accounting.err_payable_already_decided')]);
            }
            $selfApproved = (int) $row->created_by === (int) $actor->id;
            if ($selfApproved) {
                app(SelfApproval::class)->finishOwnStep($actor, 'remittance', __('accounting.err_payable_own'));
            }
            if ($decision === 'approved') {
                $amount = Money::cents((string) $row->amount);
                if ($amount > $this->figures($row->kind)['balance']) {
                    throw ValidationException::withMessages(['remittance' => __('accounting.err_payable_too_much')]);
                }
                $this->wallets->journal('payable.remittance.'.$row->id, __('accounting.jr_payable_'.$row->kind, ['reference' => $row->reference ?? '—']), [
                    ['account' => self::Accounts[$row->kind], 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                    ['account' => self::PaidFromAccount, 'user_id' => null, 'debit' => 0, 'credit' => $amount],
                ]);
            }
            DB::table('payable_remittances')->where('id', $id)->update([
                'status' => $decision === 'approved' ? 'paid' : 'rejected',
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, $decision === 'approved' ? 'payable.remittance_approved' : 'payable.remittance_rejected', (string) $id, [
                'kind' => $row->kind, 'amount' => (string) $row->amount, 'reference' => $row->reference, 'reason' => $reason, ...($selfApproved ? ['self_approved' => true] : []),
            ]);
        }, 3);
    }

    /** Whoever recorded a payment, or an owner, may withdraw it before it is checked. */
    public function cancel(User $actor, int $id, string $reason): void
    {
        abort_unless(self::canManage($actor), 403);
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            $row = DB::table('payable_remittances')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->status !== 'requested' || ((int) $row->created_by !== (int) $actor->id && ! $actor->owner())) {
                throw ValidationException::withMessages(['remittance' => __('accounting.err_payable_cancel')]);
            }
            DB::table('payable_remittances')->where('id', $id)->update(['status' => 'cancelled', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
            Audit::record($actor->id, 'payable.remittance_cancelled', (string) $id, ['kind' => $row->kind, 'amount' => (string) $row->amount, 'reason' => $reason]);
        }, 3);
    }

    /**
     * What is owed now, what is already recorded and waiting for the second person, what may still
     * be recorded, and since when the oldest unpaid amount has been owed (money paid on counts
     * against the oldest amounts first).
     *
     * @return array{balance: int, pending: int, available: int, oldest_unpaid_at: ?string, oldest_unpaid_days: ?int}
     */
    public function figures(string $kind): array
    {
        $account = self::Accounts[$kind];
        $totals = DB::table('journal_lines')->where('account', $account)->selectRaw('COALESCE(SUM(credit), 0) AS credit, COALESCE(SUM(debit), 0) AS debit')->first();
        $balance = Money::cents((string) $totals->credit) - Money::cents((string) $totals->debit);
        $pending = Money::cents((string) DB::table('payable_remittances')->where('kind', $kind)->where('status', 'requested')->sum('amount'));
        $oldest = null;
        if ($balance > 0) {
            $credits = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
                ->where('l.account', $account)->where('l.credit', '>', 0)
                ->selectRaw('e.created_at, SUM(l.credit) OVER (ORDER BY e.created_at, l.id) AS running');
            $oldest = DB::query()->fromSub($credits, 'c')->where('running', '>', Money::decimal(Money::cents((string) $totals->debit)))->orderBy('running')->value('created_at');
        }
        $oldestAt = $oldest !== null ? CarbonImmutable::parse((string) $oldest) : null;

        return [
            'balance' => $balance,
            'pending' => $pending,
            'available' => $balance - $pending,
            'oldest_unpaid_at' => $oldestAt?->toIso8601String(),
            'oldest_unpaid_days' => $oldestAt !== null ? (int) floor($oldestAt->diffInDays(now(), true)) : null,
        ];
    }

    /**
     * Payables whose oldest unpaid amount is older than the owner's limit (MADINA_PAYABLE_STALE_DAYS,
     * 45 days by default), for the Money tasks list and the office home.
     *
     * @return array{count: int, amount: string, days: int}
     */
    public function stale(): array
    {
        $days = self::staleDays();
        $count = 0;
        $amount = 0;
        foreach (array_keys(self::Accounts) as $kind) {
            $figures = $this->figures($kind);
            if ($figures['oldest_unpaid_days'] !== null && $figures['oldest_unpaid_days'] > $days) {
                $count++;
                $amount += $figures['balance'];
            }
        }

        return ['count' => $count, 'amount' => Money::decimal($amount), 'days' => $days];
    }

    public static function staleDays(): int
    {
        return max(1, (int) config('madina.payable_stale_days', 45));
    }

    /**
     * Month by month (Dhaka time): how much was held back, how much was paid on, and what was still
     * owed at the month's end, newest month first.
     *
     * @return list<array{month: string, withheld: string, remitted: string, still_payable: string}>
     */
    public function monthly(string $kind, int $months = 12): array
    {
        $account = self::Accounts[$kind];
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.account', $account)
            ->selectRaw("to_char((e.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Dhaka', 'YYYY-MM') AS month, SUM(l.credit) AS credit, SUM(l.debit) AS debit")
            ->groupBy('month')->orderBy('month')->get();
        $running = 0;
        $result = [];
        foreach ($rows as $row) {
            $running += Money::cents((string) $row->credit) - Money::cents((string) $row->debit);
            $result[] = ['month' => (string) $row->month, 'withheld' => Money::decimal(Money::cents((string) $row->credit)), 'remitted' => Money::decimal(Money::cents((string) $row->debit)), 'still_payable' => Money::decimal($running)];
        }

        return array_slice(array_reverse($result), 0, $months);
    }

    /**
     * Everything the accounting page shows for these payables.
     *
     * @return array{kinds: list<array<string, mixed>>, waiting: list<array<string, mixed>>, stale_days: int}
     */
    public function page(User $viewer): array
    {
        $kinds = [];
        foreach (array_keys(self::Accounts) as $kind) {
            $figures = $this->figures($kind);
            $kinds[] = [
                'kind' => $kind,
                'balance' => Money::decimal($figures['balance']),
                'pending' => Money::decimal($figures['pending']),
                'available' => Money::decimal(max(0, $figures['available'])),
                'oldest_unpaid_at' => $figures['oldest_unpaid_at'],
                'oldest_unpaid_days' => $figures['oldest_unpaid_days'],
                'months' => $this->monthly($kind),
            ];
        }
        $waiting = DB::table('payable_remittances as r')->join('users as maker', 'maker.id', '=', 'r.created_by')
            ->where('r.status', 'requested')->orderBy('r.id')
            ->get(['r.id', 'r.kind', 'r.amount', 'r.paid_on', 'r.reference', 'r.note', 'r.created_by', 'r.created_at', 'maker.name as maker_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'kind' => $row->kind, 'amount' => (string) $row->amount, 'paid_on' => (string) $row->paid_on,
                'reference' => $row->reference, 'note' => $row->note, 'maker_name' => $row->maker_name, 'created_at' => (string) $row->created_at,
                'is_mine' => (int) $row->created_by === (int) $viewer->id,
            ])->all();

        return ['kinds' => $kinds, 'waiting' => $waiting, 'stale_days' => self::staleDays()];
    }

    private function assertReferenceFree(string $kind, ?string $reference): void
    {
        if ($reference !== null && DB::table('payable_remittances')->where('kind', $kind)->whereIn('status', ['requested', 'paid'])->whereRaw('UPPER(reference) = ?', [$reference])->exists()) {
            throw ValidationException::withMessages(['reference' => __('accounting.err_payable_reference_used')]);
        }
    }

    private function reference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : Str::upper($reference);
    }
}
