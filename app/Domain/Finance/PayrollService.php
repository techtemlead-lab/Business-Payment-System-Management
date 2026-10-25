<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Staff\TerritoryAccess;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PayrollService
{
    public function __construct(private TerritoryAccess $territories, private CommissionWallet $wallets, private PaymentAccounts $accounts) {}

    /** @param array<string, mixed> $data */
    public function contract(User $actor, array $data): int
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionQualification::period($data['effective_month']);

        return DB::transaction(function () use ($actor, $data): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $staff = User::findOrFail($data['staff_id']);
            if (! $staff->staff() || $staff->status !== 'active' || Money::cents($data['deduction']) > Money::cents($data['base_salary']) + Money::cents($data['allowance'])) {
                throw ValidationException::withMessages(['staff_id' => __('portal.err_payroll_contract')]);
            }
            $previous = DB::table('staff_contracts')->where('staff_id', $staff->id)->where('effective_month', $data['effective_month'])->orderByDesc('revision')->first();
            if ($previous && Money::cents($previous->base_salary) === Money::cents($data['base_salary'])
                && Money::cents($previous->allowance) === Money::cents($data['allowance']) && Money::cents($previous->deduction) === Money::cents($data['deduction'])) {
                return $previous->id;
            }
            $id = DB::table('staff_contracts')->insertGetId([...$data, 'revision' => ($previous->revision ?? 0) + 1, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'payroll.contract_created', (string) $id, ['staff_id' => $staff->id]);

            return $id;
        }, 3);
    }

    /** @return list<string> */
    public function prepare(User $actor, string $month): array
    {
        abort_unless($actor->allows('payroll'), 403);
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            throw ValidationException::withMessages(['period_month' => __('portal.err_payroll_month_open')]);
        }

        return DB::transaction(function () use ($actor, $month): array {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('payroll'), 403);
            $contracts = DB::table('staff_contracts')->where('effective_month', '<=', $month)->orderByDesc('effective_month')->orderByDesc('id')->get()->unique('staff_id');
            $ids = [];
            foreach ($contracts as $contract) {
                $staff = User::find($contract->staff_id);
                if (! $staff?->staff() || $staff->status !== 'active') {
                    continue;
                }
                $existing = DB::table('payroll_entries')->where('staff_id', $staff->id)->where('period_month', $month)->whereIn('status', ['draft', 'approved', 'paid'])->first();
                if ($existing) {
                    $ids[] = $existing->id;

                    continue;
                }
                $target = DB::table('staff_targets')->where('staff_id', $staff->id)->where('period_month', $month)->first();
                $volume = $target ? $this->territories->volume($staff->id, $month, json_decode($target->territories, true, flags: JSON_THROW_ON_ERROR)) : 0;
                $bonus = $target && $volume >= Money::cents($target->target) ? Money::cents($target->bonus) : 0;
                $gross = Money::cents($contract->base_salary) + Money::cents($contract->allowance) + $bonus;
                $deduction = Money::cents($contract->deduction);
                if ($gross <= $deduction) {
                    continue;
                }
                $id = (string) Str::uuid();
                DB::table('payroll_entries')->insert(['id' => $id, 'staff_id' => $staff->id, 'contract_id' => $contract->id,
                    'period_month' => $month, 'snapshot' => json_encode(['contract' => (array) $contract, 'target' => $target,
                        'qualifying_volume' => Money::decimal($volume), 'earned_bonus' => Money::decimal($bonus)], JSON_THROW_ON_ERROR),
                    'gross' => Money::decimal($gross), 'deduction' => Money::decimal($deduction), 'net' => Money::decimal($gross - $deduction),
                    'prepared_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->event($actor, $id, 'prepared', __('portal.payroll_event_prepared'));
                $ids[] = $id;
            }

            return $ids;
        }, 3);
    }

    public function review(User $actor, string $id, string $decision, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $entry = DB::table('payroll_entries')->where('id', $id)->lockForUpdate()->first();
            abort_unless($entry, 404);
            if ($entry->status !== 'draft' || in_array($actor->id, [$entry->staff_id, $entry->prepared_by], true)) {
                throw ValidationException::withMessages(['payroll' => __('portal.err_payroll_reviewer')]);
            }
            if ($decision === 'approved') {
                $this->wallets->journal('payroll.approved.'.$id, __('portal.jr_payroll_approved', ['month' => $entry->period_month]), [
                    ['account' => 'payroll_expense', 'user_id' => $entry->staff_id, 'debit' => Money::cents($entry->gross), 'credit' => 0],
                    ['account' => 'payroll_payable', 'user_id' => $entry->staff_id, 'debit' => 0, 'credit' => Money::cents($entry->net)],
                    ['account' => 'payroll_deductions_payable', 'user_id' => $entry->staff_id, 'debit' => 0, 'credit' => Money::cents($entry->deduction)],
                ]);
            }
            DB::table('payroll_entries')->where('id', $id)->update(['status' => $decision, 'approved_by' => $actor->id, 'updated_at' => now()]);
            $this->event($actor, $id, $decision, $reason);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function pay(User $actor, string $id, array $data, UploadedFile $receipt): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        $path = $receipt->storeAs('payroll-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($actor, $id, $data, $receipt, $path): void {
                CommissionWallet::lock();
                abort_unless($actor->fresh()->allows('finance'), 403);
                $entry = DB::table('payroll_entries')->where('id', $id)->lockForUpdate()->first();
                abort_unless($entry, 404);
                if ($entry->status !== 'approved' || in_array($actor->id, [$entry->staff_id, $entry->approved_by], true)) {
                    throw ValidationException::withMessages(['payroll' => __('portal.err_payroll_payer')]);
                }
                $this->accounts->recordReference($actor, $data['payment_reference'], 'payroll:'.$id, (int) $data['payment_account_id']);
                $amount = Money::cents($entry->net);
                $this->wallets->journal('payroll.paid.'.$id, __('portal.jr_payroll_paid'), [
                    ['account' => 'payroll_payable', 'user_id' => $entry->staff_id, 'debit' => $amount, 'credit' => 0],
                    ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => $amount],
                ]);
                DB::table('payroll_entries')->where('id', $id)->update(['status' => 'paid', 'paid_by' => $actor->id,
                    'paid_at' => $data['paid_at'], 'payment_reference' => Str::upper(trim($data['payment_reference'])),
                    'payment_account_id' => $data['payment_account_id'], 'receipt_path' => $path,
                    'receipt_hash' => hash_file('sha256', $receipt->getRealPath()), 'updated_at' => now()]);
                $this->event($actor, $id, 'paid', $data['reason']);
                $staff = User::find($entry->staff_id);
                $locale = $staff?->preferredLocale() ?? app()->getLocale();
                Audit::notice(
                    $entry->staff_id,
                    __('portal.notice_payroll_paid_title', [], $locale),
                    __('portal.notice_payroll_paid_body', [
                        'month' => $entry->period_month,
                        'amount' => Money::decimal($amount),
                    ], $locale),
                );
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function event(User $actor, string $id, string $action, string $reason): void
    {
        DB::table('payroll_events')->insert(['payroll_id' => $id, 'actor_id' => $actor->id, 'action' => $action, 'reason' => $reason, 'created_at' => now()]);
        Audit::record($actor->id, 'payroll.'.$action, $id, ['reason' => $reason]);
    }
}
