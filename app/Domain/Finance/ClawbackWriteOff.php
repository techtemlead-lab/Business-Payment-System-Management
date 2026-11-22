<?php

namespace App\Domain\Finance;

use App\Domain\Accounts\PartnerAccountDeletion;
use App\Domain\Commissions\CommissionWallet;
use App\Models\CommissionAllocation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money a closed or suspended partner still owes from a refund (the commission they had already been paid).
 *
 * The owner decided (2026-11-21) it is never written off by itself. The Super Admin may write it off, one partner
 * at a time, with a reason: a balanced journal moves the debt from the partner's clawback receivable to the
 * bad-debt expense account, the debt records are set to nothing owed (so money that reaches the partner later is
 * not taken for it again), and an audit entry keeps every number. A repeated request with the same key changes nothing.
 */
final class ClawbackWriteOff
{
    public const ReceivableAccount = 'commission_clawback_receivable';

    /** Expense account for debts the company gave up on (see AccountingStatements::AccountClasses). */
    public const ExpenseAccount = 'bad_debt_expense';

    public const AuditAction = 'commission.clawback_written_off';

    public static function canUse(User $user): bool
    {
        return $user->status === 'active' && $user->role === 'super_admin';
    }

    /** What the partner owes by the ledger, in paisa. */
    public function owed(int $userId): int
    {
        $owed = (string) DB::table('journal_lines')->where('account', self::ReceivableAccount)->where('user_id', $userId)->selectRaw('COALESCE(SUM(debit - credit), 0) AS owed')->value('owed');

        return FundCatalog::centsOf($owed);
    }

    /**
     * @param  string  $expectedAmount  The amount the owner was shown; a different debt now is refused.
     * @return array{amount: string, replayed: bool}
     */
    public function writeOff(User $actor, User $partner, string $reason, string $requestKey, string $expectedAmount): array
    {
        abort_unless(self::canUse($actor) && PartnerAccountDeletion::isPartner($partner), 403);
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $partner, $reason, $requestKey, $expectedAmount): array {
            CommissionWallet::lockPartners($partner->id);
            abort_unless(self::canUse($actor->fresh() ?? $actor), 403);
            $earlier = DB::table('audit_events')->where('action', self::AuditAction)->where('metadata->request_key', $requestKey)->first();
            if ($earlier !== null) {
                abort_unless($earlier->subject === (string) $partner->id && (int) $earlier->actor_id === $actor->id, 409, __('money_core.closed_balance_err_key'));

                return ['amount' => (string) json_decode((string) $earlier->metadata, true, flags: JSON_THROW_ON_ERROR)['amount'], 'replayed' => true];
            }
            $locked = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'active') {
                throw ValidationException::withMessages(['clawback' => __('money_core.writeoff_err_active')]);
            }
            $amount = $this->owed($locked->id);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['clawback' => __('money_core.writeoff_err_nothing')]);
            }
            if ($amount !== Money::cents($expectedAmount)) {
                throw ValidationException::withMessages(['clawback' => __('money_core.writeoff_err_changed', ['amount' => Money::grouped(Money::decimal($amount))])]);
            }

            // The debt records behind the ledger: refund lines still unrecovered, and older rows that keep the debt on themselves.
            $reversals = DB::table('commission_reversals')->where('user_id', $locked->id)->where('unrecovered', '>', 0)->orderBy('id')->lockForUpdate()->get(['id', 'allocation_id', 'unrecovered']);
            $withReversal = DB::table('commission_reversals')->where('user_id', $locked->id)->pluck('allocation_id')->map(fn ($id): int => (int) $id)->all();
            $deficits = CommissionAllocation::query()->where('user_id', $locked->id)->whereNotNull('metadata->unrecovered_deficit')->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (CommissionAllocation $row): bool => Money::cents((string) ($row->metadata['unrecovered_deficit'] ?? '0.00')) > 0);
            $recorded = $reversals->sum(fn (object $row): int => Money::cents((string) $row->unrecovered))
                + $deficits->reject(fn (CommissionAllocation $row): bool => in_array($row->id, $withReversal, true))
                    ->sum(fn (CommissionAllocation $row): int => Money::cents((string) $row->metadata['unrecovered_deficit']));
            if ($recorded !== $amount) {
                throw ValidationException::withMessages(['clawback' => __('money_core.writeoff_err_records', ['ledger' => Money::grouped(Money::decimal($amount)), 'records' => Money::grouped(Money::decimal($recorded))])]);
            }

            $eventKey = 'clawback_write_off.'.$locked->id.'.'.$requestKey;
            app(CommissionWallet::class)->journal($eventKey, __('money_core.jr_clawback_write_off', ['code' => (string) $locked->dealer_code]), [
                ['account' => self::ExpenseAccount, 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                ['account' => self::ReceivableAccount, 'user_id' => $locked->id, 'debit' => 0, 'credit' => $amount],
            ]);
            $deficitAmounts = $deficits->mapWithKeys(fn (CommissionAllocation $row): array => [(string) $row->id => (string) $row->metadata['unrecovered_deficit']])->all();
            foreach ($reversals as $row) {
                DB::table('commission_reversals')->where('id', $row->id)->update(['unrecovered' => '0.00', 'updated_at' => now()]);
            }
            foreach ($deficits as $row) {
                $row->update(['metadata' => [...$row->metadata, 'unrecovered_deficit' => '0.00', 'written_off_deficit' => $row->metadata['unrecovered_deficit'], 'written_off_at' => now()->toIso8601String()]]);
            }
            Audit::record($actor->id, self::AuditAction, (string) $locked->id, [
                'request_key' => $requestKey,
                'amount' => Money::decimal($amount),
                'reason' => $reason,
                'journal_event_key' => $eventKey,
                'dealer_code' => $locked->dealer_code,
                'partner_status' => $locked->closed_at !== null ? 'closed' : $locked->status,
                'reversals' => $reversals->mapWithKeys(fn (object $row): array => [(string) $row->id => (string) $row->unrecovered])->all(),
                'allocation_deficits' => $deficitAmounts,
            ]);

            return ['amount' => Money::decimal($amount), 'replayed' => false];
        }, 3);
    }
}
