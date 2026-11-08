<?php

namespace App\Domain\Payouts;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\FraudAnomalyDetector;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualPayoutService
{
    /**
     * Whether the person viewing may record this approved payment: 'alone' when they approved
     * it themselves and nobody else could pay, 'second_person' when someone else must, otherwise null.
     */
    public function payerState(object $withdrawal, User $viewer): ?string
    {
        if ($withdrawal->status !== 'approved' || (int) $withdrawal->reviewed_by !== (int) $viewer->id) {
            return null;
        }

        return app(SelfApproval::class)->isAvailable($viewer, [$withdrawal->user_id, $withdrawal->reviewed_by]) ? 'alone' : 'second_person';
    }

    /** @param array<string, mixed> $data */
    public function request(User $actor, array $data): string
    {
        return $this->submit($actor, $data)['id'];
    }

    /**
     * Creates the withdrawal request, or returns the existing one when the same
     * idempotency key is sent again. `created` is false for such a replay, so
     * callers can skip side effects (like the partner SMS) that already happened.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, created: bool}
     */
    public function submit(User $actor, array $data): array
    {
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $data): array {
            CommissionWallet::lock();
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->status === 'active' && in_array($actor->role, ['dealer', 'sub_dealer'], true), 403);
            $amount = Money::cents((string) $data['amount']);
            $existing = DB::table('withdrawals')->where('user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if(Money::cents($existing->amount) !== $amount || $existing->channel !== $data['channel'] || decrypt($existing->destination) !== trim($data['destination']), 409, __('portal.err_idem_withdrawal_key'));

                return ['id' => $existing->id, 'created' => false];
            }
            $minimum = app(FinanceSettings::class)->withdrawalMinimum()['amount'];
            if ($amount < Money::cents($minimum)) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_minimum', ['amount' => LocalDigits::text(Money::grouped($minimum))])]);
            }
            if (CommissionWallet::hasDebt($actor->id)) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_clawback')]);
            }
            if (app(FinanceSettings::class)->needsKycBeforeWithdrawal($actor)) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_kyc_first')]);
            }
            $wallet = DB::table('wallets')->where('user_id', $actor->id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->available) < $amount) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_insufficient')]);
            }
            $tax = app(MonthlyPayoutTax::class)->forWithdrawal($actor->id, $amount);
            $id = (string) Str::uuid();
            DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $actor->id, 'amount' => Money::decimal($amount), 'minimum_snapshot' => $minimum,
                'channel' => $data['channel'], 'destination' => encrypt(trim($data['destination'])), 'idempotency_key' => $data['idempotency_key'],
                'withheld_amount' => $tax['tds_amount'], 'net_amount' => $tax['net_amount'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('wallets')->where('id', $wallet->id)->update(['available' => Money::decimal(Money::cents($wallet->available) - $amount),
                'held' => Money::decimal(Money::cents($wallet->held) + $amount), 'updated_at' => now()]);
            $risk = app(FraudAnomalyDetector::class)->analyzeWithdrawal($actor, (float) $data['amount']);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'requested', 'note' => __('portal.wd_event_funds_reserved'), 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.requested', $id, [
                'risk_score' => $risk['score'],
                'risk_level' => $risk['risk_level'],
                'risk_flags' => $risk['flags'],
            ]);
            Audit::notice($actor->id, __('portal.notice_user_withdrawal_title'), __('portal.notice_user_withdrawal_body', ['amount' => LocalDigits::text(Money::grouped(Money::decimal($amount)))]), 'withdrawal.requested');
            Audit::notifyAdminsAndStaff(
                ['finance'],
                __('portal.notice_admin_withdrawal_title'),
                __('portal.notice_admin_withdrawal_body', [
                    'name' => $actor->name,
                    'amount' => Money::decimal($amount),
                    'channel' => (string) $data['channel'],
                ]),
                $actor->id
            );

            return ['id' => $id, 'created' => true];
        }, 3);
    }

    public function review(User $actor, string $id, string $decision, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $decision, $reason) {
            CommissionWallet::lock();
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($w, 404);
            if ($w->status !== 'requested' && ! ($w->status === 'approved' && $decision === 'rejected')) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_already_reviewed')]);
            }
            if ($actor->id === $w->user_id) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_self_review')]);
            }
            if ($decision === 'approved' && CommissionWallet::hasDebt($w->user_id)) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_clawback_before_approve')]);
            }
            $wallet = DB::table('wallets')->where('user_id', $w->user_id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->held) - Money::cents($wallet->commission_held) < Money::cents($w->amount)) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_held_mismatch')]);
            }
            if ($decision === 'rejected') {
                DB::table('wallets')->where('id', $wallet->id)->update(['held' => Money::decimal(Money::cents($wallet->held) - Money::cents($w->amount)), 'updated_at' => now()]);
                app(CommissionWallet::class)->credit($w->user_id, Money::cents($w->amount));
            }
            DB::table('withdrawals')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reason' => $reason, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => $decision, 'note' => $reason, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.'.$decision, $id, [
                'user_id' => $w->user_id, 'amount' => $w->amount, 'previous_status' => $w->status, 'reason' => $reason,
            ]);
            $partner = User::find($w->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $decisionLabel = __('portal.wd_decision_'.$decision, [], $locale);
            Audit::notice(
                $w->user_id,
                __('portal.notice_wd_reviewed_title', [], $locale),
                __('portal.notice_wd_reviewed_body', ['decision' => $decisionLabel, 'reason' => $reason], $locale),
                'withdrawal.'.$decision,
            );
        }, 3);
    }

    /**
     * Marks an approved withdrawal as paid by hand and issues its numbered payout receipt.
     * Cash needs the name of the person who took the money; every other method needs the
     * transaction reference (plus bank and account, or the wallet number) and the transfer proof.
     *
     * @param  array{method: string, bank_name?: ?string, account_number?: ?string, wallet_number?: ?string, received_by?: ?string}  $payout
     * @return string The new receipt number.
     */
    public function settle(User $actor, string $id, array $payout, ?UploadedFile $receipt, ?string $reference, string $paidAt, string $note, ?int $paymentAccountId = null, bool $confirmedAlone = false): string
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        $isCash = $payout['method'] === 'cash';
        $reference = ($reference === null || trim($reference) === '') ? null : $reference;
        if (! $isCash && ($reference === null || $receipt === null)) {
            throw ValidationException::withMessages([$reference === null ? 'payment_reference' : 'receipt' => __('portal.err_payout_details_needed')]);
        }
        $path = $receipt?->storeAs('payout-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        $issued = null;
        try {
            DB::transaction(function () use ($actor, $id, $payout, $isCash, $receipt, $path, $reference, $paidAt, $note, $paymentAccountId, $confirmedAlone, &$issued) {
                CommissionWallet::lock();
                $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
                abort_unless($w, 404);
                if ($w->status !== 'approved') {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_not_approved')]);
                }
                if (CommissionWallet::hasDebt($w->user_id)) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_clawback_before_pay')]);
                }
                if ($actor->id === $w->user_id) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_payer_separation')]);
                }
                $selfApproved = $actor->id === $w->reviewed_by;
                if ($selfApproved) {
                    app(SelfApproval::class)->authorizeAlone($actor, 'withdrawal', __('portal.err_wd_payer_separation'), $confirmedAlone, $note, [$w->user_id, $w->reviewed_by], receiptNeeded: true, hasReceipt: $receipt !== null);
                }
                if ($reference !== null && DB::table('withdrawals')->where('payment_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['payment_reference' => __('portal.err_wd_payment_ref_used')]);
                }
                // Cash is handed over at the office, not sent from a company payout account.
                if ($reference !== null && ! $isCash) {
                    app(PaymentAccounts::class)->recordReference($actor, $reference, 'withdrawal:'.$id, $paymentAccountId);
                    DB::table('withdrawals')->where('id', $id)->update(['payment_account_id' => $paymentAccountId]);
                }
                $wallet = DB::table('wallets')->where('user_id', $w->user_id)->lockForUpdate()->first();
                if (! $wallet || Money::cents($wallet->held) - Money::cents($wallet->commission_held) < Money::cents($w->amount)) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_held_settle_mismatch')]);
                }
                DB::table('wallets')->where('id', $wallet->id)->update(['held' => Money::decimal(Money::cents($wallet->held) - Money::cents($w->amount)), 'updated_at' => now()]);
                $partner = User::find($w->user_id);
                $locale = $partner?->preferredLocale() ?? app()->getLocale();
                $entry = (string) Str::uuid();
                DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'manual-payout:'.$id, 'description' => __('portal.wd_journal_manual_payout', [], $locale), 'actor_id' => $actor->id, 'created_at' => now()]);
                DB::table('journal_lines')->insert([['entry_id' => $entry, 'user_id' => $w->user_id, 'account' => 'wallet_liability', 'debit' => $w->amount, 'credit' => '0.00'], ['entry_id' => $entry, 'user_id' => null, 'account' => 'manual_payout_clearing', 'debit' => '0.00', 'credit' => $w->net_amount ?? $w->amount], ['entry_id' => $entry, 'user_id' => null, 'account' => 'payout_withholding_payable', 'debit' => '0.00', 'credit' => $w->withheld_amount]]);
                $issued = app(PayoutReceipts::class)->issue($actor, $w, $payout, $reference, $paidAt);
                $proofHash = $receipt ? hash_file('sha256', $receipt->getRealPath()) : null;
                DB::table('withdrawals')->where('id', $id)->update(['status' => 'paid', 'payment_reference' => $reference ?? $issued['number'], 'paid_at' => $paidAt, 'paid_by' => $actor->id,
                    'receipt_path' => $path, 'receipt_mime' => $receipt?->getMimeType(), 'receipt_sha256' => $proofHash, 'updated_at' => now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'paid', 'note' => $note, 'created_at' => now()]);
                Audit::record($actor->id, 'withdrawal.paid', $id, [
                    'user_id' => $w->user_id, 'amount' => $w->amount, 'net_amount' => $w->net_amount ?? $w->amount,
                    'withheld_amount' => $w->withheld_amount, 'payment_reference' => $reference,
                    'method' => $payout['method'], 'payout_receipt' => $issued['number'],
                    'receipt_sha256' => $proofHash,
                    ...($selfApproved ? ['self_approved' => true] : []),
                ]);
                Audit::notice(
                    $w->user_id,
                    __('portal.notice_wd_paid_title', [], $locale),
                    __('portal.notice_wd_paid_receipt_body', ['number' => $issued['number']], $locale),
                    'withdrawal.paid',
                );
            }, 3);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return $issued['number'];
    }
}
