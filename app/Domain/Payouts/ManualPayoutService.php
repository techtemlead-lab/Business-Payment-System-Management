<?php

namespace App\Domain\Payouts;

use App\Domain\Accounts\PartnerProfileLock;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\FraudAnomalyDetector;
use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\WithdrawalSecurityWait;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use App\Support\TakaText;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualPayoutService
{
    /**
     * 'second_person' when the staff member viewing approved this payment, so someone else
     * must record it; otherwise null. The owner records a payment they approved themselves.
     */
    public function payerState(object $withdrawal, User $viewer): ?string
    {
        if ($withdrawal->status !== 'approved' || (int) $withdrawal->reviewed_by !== (int) $viewer->id) {
            return null;
        }

        return app(SelfApproval::class)->ownerFinishesAlone($viewer) ? null : 'second_person';
    }

    /**
     * CR-16: true when this request is at or above the owner's limit and the viewer is not an
     * owner, so only an owner may approve it (staff may still turn it down).
     */
    public function waitsForOwner(object $withdrawal, User $viewer): bool
    {
        return $withdrawal->status === 'requested'
            && app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Withdrawal, (string) $withdrawal->amount)
            && ! OwnerApprovalLimits::ownerDecides($viewer);
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
        // Outside the transaction below, so the record of a refusal is kept. A replay of a request that
        // already went through is not refused: it just returns the existing one.
        $replay = DB::table('withdrawals')->where('user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->exists();
        if (! $replay) {
            app(WithdrawalMethods::class)->refuseOff((string) ($data['channel'] ?? ''));
            app(WithdrawalSecurityWait::class)->refuseWhileWaiting($actor);
        }

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
            // Owner decision D12: money is paid only to a partner whose National ID is on file.
            if ($this->needsNidBeforeFirstWithdrawal($actor)) {
                throw ValidationException::withMessages(['amount' => __('payouts.err_nid_first')]);
            }
            $wallet = DB::table('wallets')->where('user_id', $actor->id)->lockForUpdate()->first();
            $available = $wallet ? Money::cents($wallet->available) : 0;
            if ($available < $amount) {
                throw ValidationException::withMessages(['amount' => __('payouts.err_over_balance', ['balance' => TakaText::amount(Money::decimal($available))])]);
            }
            app(PayoutLimits::class)->refuseOverChannelLimit((string) $data['channel'], $amount, $available);
            $tax = app(MonthlyPayoutTax::class)->forWithdrawal($actor->id, $amount);
            $id = (string) Str::uuid();
            DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $actor->id, 'amount' => Money::decimal($amount), 'minimum_snapshot' => $minimum,
                'channel' => $data['channel'], 'destination' => encrypt(trim($data['destination'])), 'idempotency_key' => $data['idempotency_key'],
                'withheld_amount' => $tax['tds_amount'], 'net_amount' => $tax['net_amount'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('wallets')->where('id', $wallet->id)->update(['available' => Money::decimal($available - $amount),
                'held' => Money::decimal(Money::cents($wallet->held) + $amount), 'updated_at' => now()]);
            $risk = app(FraudAnomalyDetector::class)->analyzeWithdrawal($actor, (float) $data['amount']);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'requested', 'note' => __('portal.wd_event_funds_reserved'), 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.requested', $id, [
                'risk_score' => $risk['score'],
                'risk_level' => $risk['risk_level'],
                'risk_flags' => $risk['flags'],
            ]);
            Audit::notice($actor->id, __('portal.notice_user_withdrawal_title'), __('portal.notice_user_withdrawal_body', ['amount' => TakaText::afterSign(Money::decimal($amount))]), 'withdrawal.requested');
            // CR-16: a request at or above the owner's limit waits for an owner; smaller ones go to finance staff.
            $title = __('portal.notice_admin_withdrawal_title');
            $body = __('portal.notice_admin_withdrawal_body', [
                'name' => $actor->name,
                'amount' => TakaText::afterSign(Money::decimal($amount)),
                'channel' => (string) $data['channel'],
            ]);
            if (app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Withdrawal, Money::decimal($amount))) {
                Audit::notifyOwners(['finance'], $title, $body, $actor->id);
            } else {
                Audit::notifyStaffFirst(['finance'], $title, $body, $actor->id);
            }

            return ['id' => $id, 'created' => true];
        }, 3);
    }

    /**
     * True when the partner has never been paid and has no National ID on file (typed on the account
     * or an approved ID paper). Partners already paid once keep withdrawing as before.
     */
    public function needsNidBeforeFirstWithdrawal(User $partner): bool
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            return false;
        }
        if (trim((string) $partner->nid) !== '') {
            return false;
        }
        // The NID or the birth-certificate number is taken when the account is opened (client, 2026-11-20).
        if (trim((string) (PartnerProfileLock::profileOf($partner)['birth_certificate_number'] ?? '')) !== '') {
            return false;
        }
        if (DB::table('withdrawals')->where('user_id', $partner->id)->whereIn('status', ['approved', 'paid'])->exists()) {
            return false;
        }

        return ! DB::table('kyc_documents')->where('user_id', $partner->id)->whereIn('kind', FinanceSettings::ApprovedIdKinds)->where('review_status', 'approved')->exists();
    }

    /**
     * Approves or rejects a withdrawal. Approving re-checks, under a lock on the partner's account,
     * that the partner may be paid now (D4). `$payerId` names the finance person who will pay it.
     */
    public function review(User $actor, string $id, string $decision, string $reason, ?int $payerId = null): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $decision, $reason, $payerId) {
            CommissionWallet::lock();
            // Partner row first, then the withdrawal: the same order as suspending a partner.
            $partner = $this->lockPartnerOf($id);
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($w !== null, 404);
            if ($w->status !== 'requested' && ! ($w->status === 'approved' && $decision === 'rejected')) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_already_reviewed')]);
            }
            if ((int) $actor->id === (int) $w->user_id) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_self_review')]);
            }
            if ($decision === 'approved') {
                $this->refuseUnpayable($w, $partner);
                if (CommissionWallet::hasDebt($w->user_id)) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_clawback_before_approve')]);
                }
                $limit = app(PayoutLimits::class)->channelLimit((string) $w->channel);
                if ($limit !== null && Money::cents($w->amount) > $limit) {
                    throw ValidationException::withMessages(['withdrawal' => __('payouts.err_approve_over_limit', [
                        'channel' => __('portal.pay_channel_'.strtolower((string) $w->channel)),
                        'limit' => TakaText::amount(Money::decimal($limit)),
                    ])]);
                }
                app(OwnerApprovalLimits::class)->refuseUnlessOwnerMayApprove($actor, OwnerApprovalLimits::Withdrawal, (string) $w->amount, 'withdrawal');
                if ($payerId !== null && ! in_array($payerId, array_column($this->eligiblePayers($actor), 'id'), true)) {
                    throw ValidationException::withMessages(['payer_id' => __('payouts.err_payer_not_allowed')]);
                }
            }
            $wallet = DB::table('wallets')->where('user_id', $w->user_id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->held) - Money::cents($wallet->commission_held) < Money::cents($w->amount)) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_held_mismatch')]);
            }
            if ($decision === 'rejected') {
                DB::table('wallets')->where('id', $wallet->id)->update(['held' => Money::decimal(Money::cents($wallet->held) - Money::cents($w->amount)), 'updated_at' => now()]);
                app(CommissionWallet::class)->credit($w->user_id, Money::cents($w->amount));
            }
            DB::table('withdrawals')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reason' => $reason, 'updated_at' => now(),
                ...($decision === 'approved' ? ['approved_at' => now(), 'payer_id' => $payerId] : ['on_hold_at' => null])]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => $decision, 'note' => $reason, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.'.$decision, $id, [
                'user_id' => $w->user_id, 'amount' => $w->amount, 'previous_status' => $w->status, 'reason' => $reason,
                'reviewer_role' => $actor->role,
                ...($decision === 'approved' ? ['payer_id' => $payerId] : []),
                ...(app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Withdrawal, (string) $w->amount) ? ['owner_limit' => true] : []),
            ]);
            $locale = $partner->preferredLocale();
            // The staff reason stays in the office record; the partner reads one plain sentence in their own language (B4).
            Audit::notice(
                $w->user_id,
                __('portal.notice_wd_reviewed_title', [], $locale),
                __($decision === 'approved' ? 'qa_fixes.notice_wd_approved_body' : 'qa_fixes.notice_wd_rejected_body', [], $locale),
                'withdrawal.'.$decision,
            );
        }, 3);
    }

    /**
     * The partner takes back a withdrawal nobody has approved yet; the money returns to their balance.
     */
    public function cancel(User $partner, string $id): void
    {
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($partner, $id): void {
            CommissionWallet::lock();
            User::whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($w && (int) $w->user_id === (int) $partner->id, 404);
            if ($w->status !== 'requested') {
                throw ValidationException::withMessages(['withdrawal' => __('payouts.err_cancel_not_pending')]);
            }
            $wallet = DB::table('wallets')->where('user_id', $w->user_id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->held) - Money::cents($wallet->commission_held) < Money::cents($w->amount)) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_held_mismatch')]);
            }
            DB::table('wallets')->where('id', $wallet->id)->update(['held' => Money::decimal(Money::cents($wallet->held) - Money::cents($w->amount)), 'updated_at' => now()]);
            app(CommissionWallet::class)->credit($w->user_id, Money::cents($w->amount));
            DB::table('withdrawals')->where('id', $id)->update(['status' => 'cancelled', 'on_hold_at' => null, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $partner->id, 'event' => 'cancelled', 'note' => __('payouts.event_cancelled_by_partner'), 'created_at' => now()]);
            Audit::record($partner->id, 'withdrawal.cancelled', $id, ['user_id' => $w->user_id, 'amount' => $w->amount]);
            Audit::notice($partner->id, __('payouts.notice_cancelled_title'), __('payouts.notice_cancelled_body', ['amount' => TakaText::amount($w->amount)]), 'withdrawal.cancelled');
        }, 3);
    }

    /**
     * Names who pays an approved withdrawal. Any finance person may take a payout over for themselves.
     */
    public function assignPayer(User $actor, string $id, int $payerId): void
    {
        abort_unless($actor->allows('finance'), 403);
        DB::transaction(function () use ($actor, $id, $payerId): void {
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($w !== null, 404);
            if ($w->status !== 'approved') {
                throw ValidationException::withMessages(['payer_id' => __('portal.err_wd_not_approved')]);
            }
            $allowed = array_column($this->eligiblePayers($actor, (int) $w->reviewed_by), 'id');
            if (! in_array($payerId, $allowed, true)) {
                throw ValidationException::withMessages(['payer_id' => __('payouts.err_payer_not_allowed')]);
            }
            DB::table('withdrawals')->where('id', $id)->update(['payer_id' => $payerId, 'updated_at' => now()]);
            $payer = User::find($payerId);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'payer_named', 'note' => (string) $payer?->name, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.payer_named', $id, ['payer_id' => $payerId, 'previous_payer_id' => $w->payer_id]);
        }, 3);
    }

    /**
     * Finance people who may pay a withdrawal: active staff with finance work, except the person who
     * approved it (a second person pays), unless that person is the owner, who finishes alone.
     *
     * @return list<array{id: int, name: string}>
     */
    public function eligiblePayers(User $viewer, ?int $approverId = null): array
    {
        $approverId ??= (int) $viewer->id;
        $ownerAlone = app(SelfApproval::class)->ownerFinishesAlone(User::find($approverId) ?? $viewer);

        return User::query()->where('status', 'active')->staffAccounts()->orderBy('name')->get()
            ->filter(fn (User $staff): bool => $staff->allows('finance') && ($ownerAlone || (int) $staff->id !== $approverId))
            ->map(fn (User $staff): array => ['id' => (int) $staff->id, 'name' => (string) $staff->name])
            ->values()->all();
    }

    /**
     * Marks an approved withdrawal as paid by hand and issues its numbered payout receipt.
     * Cash needs the name of the person who took the money; every other method needs the
     * transaction reference (plus bank and account, or the wallet number) and the transfer proof.
     * With `$receiptLater` the proof may follow: the payout is recorded and stays on the
     * "receipt to attach" list until someone attaches it.
     *
     * @param  array{method: string, bank_name?: ?string, account_number?: ?string, wallet_number?: ?string, received_by?: ?string}  $payout
     * @return string The new receipt number.
     */
    public function settle(User $actor, string $id, array $payout, ?UploadedFile $receipt, ?string $reference, string $paidAt, string $note, ?int $paymentAccountId = null, bool $receiptLater = false): string
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        $isCash = $payout['method'] === 'cash';
        $reference = ($reference === null || trim($reference) === '') ? null : $reference;
        $receiptDue = ! $isCash && $receipt === null && $receiptLater;
        if (! $isCash && ($reference === null || ($receipt === null && ! $receiptDue))) {
            throw ValidationException::withMessages([$reference === null ? 'payment_reference' : 'receipt' => __('portal.err_payout_details_needed')]);
        }
        if ($reference !== null) {
            PayoutReceiptCheck::refuseBadReference($payout['method'], $reference);
        }
        if ($receipt !== null) {
            PayoutReceiptCheck::refuseBlankImage($receipt);
        }
        $path = $receipt?->storeAs('payout-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        $issued = null;
        try {
            DB::transaction(function () use ($actor, $id, $payout, $isCash, $receipt, $path, $reference, $paidAt, $note, $paymentAccountId, $receiptDue, &$issued) {
                CommissionWallet::lock();
                $partner = $this->lockPartnerOf($id);
                $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
                abort_unless($w !== null, 404);
                if ($w->status !== 'approved') {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_not_approved')]);
                }
                $this->refuseUnpayable($w, $partner);
                PayoutReceiptCheck::refuseOtherDestination($payout, (string) $w->channel, (string) decrypt($w->destination));
                if (CommissionWallet::hasDebt($w->user_id)) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_clawback_before_pay')]);
                }
                if ((int) $actor->id === (int) $w->user_id) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_payer_separation')]);
                }
                $selfApproved = (int) $actor->id === (int) $w->reviewed_by;
                if ($selfApproved) {
                    app(SelfApproval::class)->finishOwnStep($actor, 'withdrawal', __('portal.err_wd_payer_separation'));
                }
                if ($reference !== null && PayoutReceiptCheck::referenceUsed($reference)) {
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
                $locale = $partner->preferredLocale();
                $entry = (string) Str::uuid();
                DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'manual-payout:'.$id, 'description' => __('portal.wd_journal_manual_payout', [], $locale), 'actor_id' => $actor->id, 'created_at' => now()]);
                DB::table('journal_lines')->insert([['entry_id' => $entry, 'user_id' => $w->user_id, 'account' => 'wallet_liability', 'debit' => $w->amount, 'credit' => '0.00'], ['entry_id' => $entry, 'user_id' => null, 'account' => 'manual_payout_clearing', 'debit' => '0.00', 'credit' => $w->net_amount ?? $w->amount], ['entry_id' => $entry, 'user_id' => null, 'account' => 'payout_withholding_payable', 'debit' => '0.00', 'credit' => $w->withheld_amount]]);
                $issued = app(PayoutReceipts::class)->issue($actor, $w, $payout, $reference, $paidAt);
                $proofHash = $receipt ? hash_file('sha256', $receipt->getRealPath()) : null;
                DB::table('withdrawals')->where('id', $id)->update(['status' => 'paid', 'payment_reference' => $reference ?? $issued['number'], 'paid_at' => $paidAt, 'paid_by' => $actor->id,
                    'receipt_path' => $path, 'receipt_mime' => $receipt?->getMimeType(), 'receipt_sha256' => $proofHash, 'receipt_due' => $receiptDue, 'updated_at' => now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'paid', 'note' => $note, 'created_at' => now()]);
                if ($receiptDue) {
                    DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'receipt_due', 'note' => __('payouts.event_receipt_due', [], $locale), 'created_at' => now()]);
                }
                Audit::record($actor->id, 'withdrawal.paid', $id, [
                    'user_id' => $w->user_id, 'amount' => $w->amount, 'net_amount' => $w->net_amount ?? $w->amount,
                    'withheld_amount' => $w->withheld_amount, 'payment_reference' => $reference,
                    'method' => $payout['method'], 'payout_receipt' => $issued['number'],
                    'receipt_sha256' => $proofHash,
                    ...($receiptDue ? ['receipt_due' => true] : []),
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

    /**
     * Attaches the transfer proof to a payout that was recorded with "receipt later".
     */
    public function attachReceipt(User $actor, string $id, UploadedFile $receipt): void
    {
        abort_unless($actor->allows('finance'), 403);
        PayoutReceiptCheck::refuseBlankImage($receipt);
        $path = $receipt->storeAs('payout-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($actor, $id, $receipt, $path): void {
                $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
                abort_unless($w !== null, 404);
                if ($w->status !== 'paid' || ! $w->receipt_due) {
                    throw ValidationException::withMessages(['receipt' => __('payouts.err_receipt_not_due')]);
                }
                $hash = hash_file('sha256', $receipt->getRealPath());
                DB::table('withdrawals')->where('id', $id)->update(['receipt_path' => $path, 'receipt_mime' => $receipt->getMimeType(), 'receipt_sha256' => $hash, 'receipt_due' => false, 'updated_at' => now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'receipt_attached', 'note' => __('payouts.flash_receipt_attached'), 'created_at' => now()]);
                Audit::record($actor->id, 'withdrawal.receipt_attached', $id, ['receipt_sha256' => $hash]);
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /**
     * Why this withdrawal may not be approved or paid right now, or null when nothing stands in the way:
     * the partner is suspended or closed, the request is on hold, or the partner changed their phone or
     * password inside the security wait.
     */
    public function unpayableReason(object $withdrawal, User $partner): ?string
    {
        if ($partner->status !== 'active' || $partner->closed_at !== null) {
            return __('payouts.err_partner_not_active');
        }
        if ($withdrawal->on_hold_at !== null) {
            return __('payouts.err_on_hold');
        }
        $opensAt = app(WithdrawalSecurityWait::class)->opensAt($partner);
        if ($opensAt !== null) {
            return __('payouts.err_security_wait', ['time' => $opensAt->timezone('Asia/Dhaka')->locale(app()->getLocale())->isoFormat('D MMM YYYY, h:mm A')]);
        }

        return null;
    }

    /**
     * The facts finance checks before approving or paying (MA-07). Opening them shows the full
     * payout number, so the opening is logged like the detail page.
     *
     * @return array<string, mixed>
     */
    public function reviewFacts(User $viewer, string $id): array
    {
        abort_unless($viewer->allows('finance'), 403);
        $w = DB::table('withdrawals')->where('id', $id)->first();
        abort_unless($w !== null, 404);
        $partner = User::findOrFail($w->user_id);
        $wallet = DB::table('wallets')->where('user_id', $w->user_id)->first();
        $others = DB::table('withdrawals')->where('user_id', $w->user_id)->where('id', '<>', $id)->whereIn('status', WithdrawalHolds::OpenStatuses)
            ->selectRaw('COUNT(*) AS open_count, COALESCE(SUM(amount), 0) AS open_amount')->first();
        $limit = app(PayoutLimits::class)->channelLimit((string) $w->channel);
        $changed = $partner->credentials_changed_at ? CarbonImmutable::parse($partner->credentials_changed_at) : null;
        Audit::record($viewer->id, 'withdrawal.destination_viewed', $id);

        return [
            'partner' => [
                'name' => $partner->name, 'code' => $partner->dealer_code, 'phone' => $partner->phone,
                'status' => $partner->closed_at !== null ? 'closed' : $partner->status,
                'nid_on_file' => trim((string) $partner->nid) !== '' || DB::table('kyc_documents')->where('user_id', $partner->id)->whereIn('kind', FinanceSettings::ApprovedIdKinds)->where('review_status', 'approved')->exists(),
                'tin_on_file' => trim((string) $partner->tin) !== '',
            ],
            'balance' => ['available' => $wallet->available ?? '0.00', 'held' => $wallet->held ?? '0.00'],
            'credentials_changed_at' => $changed?->toIso8601String(),
            'changed_near_request' => $changed !== null && $changed->greaterThan(CarbonImmutable::parse($w->created_at)->subDay()),
            'other_open' => ['count' => (int) ($others->open_count ?? 0), 'amount' => Money::decimal(Money::cents((string) ($others->open_amount ?? '0')))],
            'destination' => decrypt($w->destination),
            'channel_limit' => $limit === null ? null : Money::decimal($limit),
            'over_channel_limit' => $limit !== null && Money::cents($w->amount) > $limit,
            'blocker' => $w->status === 'requested' || $w->status === 'approved' ? $this->unpayableReason($w, $partner) : null,
            'history' => DB::table('withdrawals')->where('user_id', $w->user_id)->where('id', '<>', $id)->orderByDesc('created_at')->limit(5)
                ->get(['id', 'amount', 'status', 'channel', 'created_at', 'paid_at'])->all(),
            'payers' => $this->eligiblePayers($viewer, $w->status === 'approved' ? (int) $w->reviewed_by : null),
            'amount_words' => TakaText::words($w->amount, null, true),
        ];
    }

    /** Locks the account of the partner who asked for this withdrawal. */
    private function lockPartnerOf(string $withdrawalId): User
    {
        $userId = DB::table('withdrawals')->where('id', $withdrawalId)->value('user_id');
        abort_unless($userId !== null, 404);

        return User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    /** @throws ValidationException */
    private function refuseUnpayable(object $withdrawal, User $partner): void
    {
        $reason = $this->unpayableReason($withdrawal, $partner);
        if ($reason !== null) {
            throw ValidationException::withMessages(['withdrawal' => $reason]);
        }
    }
}
