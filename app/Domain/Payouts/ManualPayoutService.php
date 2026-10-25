<?php

namespace App\Domain\Payouts;

use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualPayoutService
{
    public function request(User $actor, array $data): string
    {
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $data): string {
            CommissionWallet::lock();
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->status === 'active' && in_array($actor->role, ['dealer', 'sub_dealer'], true), 403);
            $amount = Money::cents((string) $data['amount']);
            $existing = DB::table('withdrawals')->where('user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if(Money::cents($existing->amount) !== $amount || $existing->channel !== $data['channel'] || decrypt($existing->destination) !== trim($data['destination']), 409, __('portal.err_idem_withdrawal_key'));

                return $existing->id;
            }
            $minimum = app(FinanceSettings::class)->withdrawalMinimum()['amount'];
            if ($amount < Money::cents($minimum)) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_minimum', ['amount' => $minimum])]);
            }
            if (CommissionWallet::hasDebt($actor->id)) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_clawback')]);
            }
            $wallet = DB::table('wallets')->where('user_id', $actor->id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->available) < $amount) {
                throw ValidationException::withMessages(['amount' => __('portal.err_wd_insufficient')]);
            }
            $tax = app(CommissionService::class)->calculatePayoutTds((float) $data['amount']);
            $id = (string) Str::uuid();
            DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $actor->id, 'amount' => Money::decimal($amount), 'minimum_snapshot' => $minimum,
                'channel' => $data['channel'], 'destination' => encrypt(trim($data['destination'])), 'idempotency_key' => $data['idempotency_key'],
                'withheld_amount' => $tax['tds_amount'], 'net_amount' => $tax['net_amount'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('wallets')->where('id', $wallet->id)->update(['available' => Money::decimal(Money::cents($wallet->available) - $amount),
                'held' => Money::decimal(Money::cents($wallet->held) + $amount), 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'requested', 'note' => __('portal.wd_event_funds_reserved'), 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.requested', $id);

            return $id;
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
            Audit::record($actor->id, 'withdrawal.'.$decision, $id);
            $partner = User::find($w->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $decisionLabel = __('portal.wd_decision_'.$decision, [], $locale);
            Audit::notice(
                $w->user_id,
                __('portal.notice_wd_reviewed_title', [], $locale),
                __('portal.notice_wd_reviewed_body', ['decision' => $decisionLabel, 'reason' => $reason], $locale),
            );
        }, 3);
    }

    public function settle(User $actor, string $id, UploadedFile $receipt, string $reference, string $paidAt, string $note, ?int $paymentAccountId = null): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        $path = $receipt->storeAs('payout-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($actor, $id, $receipt, $path, $reference, $paidAt, $note, $paymentAccountId) {
                CommissionWallet::lock();
                $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
                abort_unless($w, 404);
                if ($w->status !== 'approved') {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_not_approved')]);
                }
                if (CommissionWallet::hasDebt($w->user_id)) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_clawback_before_pay')]);
                }
                if ($actor->id === $w->user_id || $actor->id === $w->reviewed_by) {
                    throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_payer_separation')]);
                }
                if (DB::table('withdrawals')->where('payment_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['payment_reference' => __('portal.err_wd_payment_ref_used')]);
                }
                app(PaymentAccounts::class)->recordReference($actor, $reference, 'withdrawal:'.$id, $paymentAccountId);
                DB::table('withdrawals')->where('id', $id)->update(['payment_account_id' => $paymentAccountId]);
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
                DB::table('withdrawals')->where('id', $id)->update(['status' => 'paid', 'payment_reference' => $reference, 'paid_at' => $paidAt, 'paid_by' => $actor->id, 'receipt_path' => $path, 'receipt_mime' => $receipt->getMimeType(), 'receipt_sha256' => hash_file('sha256', $receipt->getRealPath()), 'updated_at' => now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'paid', 'note' => $note, 'created_at' => now()]);
                Audit::record($actor->id, 'withdrawal.paid', $id, ['receipt_sha256' => hash_file('sha256', $receipt->getRealPath())]);
                Audit::notice(
                    $w->user_id,
                    __('portal.notice_wd_paid_title', [], $locale),
                    __('portal.notice_wd_paid_body', [], $locale),
                );
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
