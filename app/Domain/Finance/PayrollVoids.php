<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoing a payroll entry that was approved by mistake and not paid yet. Approval posted
 * debit payroll_expense, credit payroll_payable and payroll_deductions_payable; the void posts
 * the exact opposite, so a wrong approval no longer sits in payroll_payable for ever.
 *
 * Two people: whoever approved the entry cannot void it alone (the owner may, as everywhere),
 * and the staff member it pays never can. A voided entry frees the month, so a corrected entry
 * can be prepared again.
 */
final class PayrollVoids
{
    public const Status = 'voided';

    public function __construct(private CommissionWallet $wallets) {}

    public function void(User $actor, string $id, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $entry = DB::table('payroll_entries')->where('id', $id)->lockForUpdate()->first();
            abort_unless($entry !== null, 404);
            if ($entry->status !== 'approved' || (int) $actor->id === (int) $entry->staff_id) {
                throw ValidationException::withMessages(['payroll' => __('portal.err_payroll_void')]);
            }
            $selfApproved = (int) $actor->id === (int) $entry->approved_by;
            if ($selfApproved) {
                app(SelfApproval::class)->finishOwnStep($actor, 'payroll', __('portal.err_payroll_void_own'));
            }
            $staffId = (int) $entry->staff_id;
            $this->wallets->journal('payroll.voided.'.$id, __('portal.jr_payroll_voided', ['month' => $entry->period_month]), [
                ['account' => 'payroll_payable', 'user_id' => $staffId, 'debit' => Money::cents((string) $entry->net), 'credit' => 0],
                ['account' => 'payroll_deductions_payable', 'user_id' => $staffId, 'debit' => Money::cents((string) $entry->deduction), 'credit' => 0],
                ['account' => 'payroll_expense', 'user_id' => $staffId, 'debit' => 0, 'credit' => Money::cents((string) $entry->gross)],
            ]);
            DB::table('payroll_entries')->where('id', $id)->update(['status' => self::Status, 'updated_at' => now()]);
            DB::table('payroll_events')->insert(['payroll_id' => $id, 'actor_id' => $actor->id, 'action' => 'voided', 'reason' => $reason, 'created_at' => now()]);
            Audit::record($actor->id, 'payroll.voided', $id, ['reason' => $reason, 'gross' => (string) $entry->gross, 'net' => (string) $entry->net, 'deduction' => (string) $entry->deduction, ...($selfApproved ? ['self_approved' => true] : [])]);
        }, 3);
    }
}
