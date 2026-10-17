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
                abort_if(Money::cents($existing->amount) !== $amount || $existing->channel !== $data['channel'] || decrypt($existing->destination) !== trim($data['destination']), 409, 'Request key already used with different withdrawal data.');

                return $existing->id;
            }
            $minimum = app(FinanceSettings::class)->withdrawalMinimum()['amount'];
            if ($amount < Money::cents($minimum)) {
                throw ValidationException::withMessages(['amount' => 'Minimum withdrawal is '.$minimum.' BDT.']);
            }
            if (CommissionWallet::hasDebt($actor->id)) {
                throw ValidationException::withMessages(['amount' => 'Resolve prior commission clawbacks before requesting another withdrawal.']);
            }
            $wallet = DB::table('wallets')->where('user_id', $actor->id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->available) < $amount) {
                throw ValidationException::withMessages(['amount' => 'Insufficient available earnings.']);
            }
            $tax = app(CommissionService::class)->calculatePayoutTds((float) $data['amount']);
            $id = (string) Str::uuid();
            DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $actor->id, 'amount' => Money::decimal($amount), 'minimum_snapshot' => $minimum,
                'channel' => $data['channel'], 'destination' => encrypt(trim($data['destination'])), 'idempotency_key' => $data['idempotency_key'],
                'withheld_amount' => $tax['tds_amount'], 'net_amount' => $tax['net_amount'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('wallets')->where('id', $wallet->id)->update(['available' => Money::decimal(Money::cents($wallet->available) - $amount),
                'held' => Money::decimal(Money::cents($wallet->held) + $amount), 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'requested', 'note' => 'Funds reserved for company review.', 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.requested', $id);

            return $id;
        }, 3);
    }

    public function review(User $actor, string $id, string $decision, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        if (! config('madina.financial_activation')) {
            throw ValidationException::withMessages(['policy' => 'Payout recording is inactive until an approved financial policy and opening balances are verified.']);
        }
        DB::transaction(function () use ($actor, $id, $decision, $reason) {
            CommissionWallet::lock();
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($w, 404);
            if ($w->status !== 'requested' && ! ($w->status === 'approved' && $decision === 'rejected')) {
                throw ValidationException::withMessages(['withdrawal' => 'This request has already been reviewed.']);
            }
            if ($actor->id === $w->user_id) {
                throw ValidationException::withMessages(['withdrawal' => 'You cannot review your own withdrawal.']);
            }
            if ($decision === 'approved' && CommissionWallet::hasDebt($w->user_id)) {
                throw ValidationException::withMessages(['withdrawal' => 'Outstanding commission clawbacks must be reconciled before payout approval. Reject this unpaid request to release its funds against the debt.']);
            }
            $wallet = DB::table('wallets')->where('user_id', $w->user_id)->lockForUpdate()->first();
            if (! $wallet || Money::cents($wallet->held) - Money::cents($wallet->commission_held) < Money::cents($w->amount)) {
                throw ValidationException::withMessages(['withdrawal' => 'Reserved funds do not reconcile. Review the ledger first.']);
            }
            if ($decision === 'rejected') {
                DB::table('wallets')->where('id', $wallet->id)->update(['held' => Money::decimal(Money::cents($wallet->held) - Money::cents($w->amount)), 'updated_at' => now()]);
                app(CommissionWallet::class)->credit($w->user_id, Money::cents($w->amount));
            }
            DB::table('withdrawals')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reason' => $reason, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => $decision, 'note' => $reason, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.'.$decision, $id);
            Audit::notice($w->user_id, 'Withdrawal reviewed', 'Your withdrawal was '.$decision.'. '.$reason);
        }, 3);
    }

    public function settle(User $actor, string $id, UploadedFile $receipt, string $reference, string $paidAt, string $note, ?int $paymentAccountId = null): void
    {
        abort_unless($actor->allows('finance'), 403);
        if (! config('madina.financial_activation')) {
            throw ValidationException::withMessages(['policy' => 'Payout recording is inactive until an approved financial policy and opening balances are verified.']);
        }
        $path = $receipt->storeAs('payout-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($actor, $id, $receipt, $path, $reference, $paidAt, $note, $paymentAccountId) {
                CommissionWallet::lock();
                $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
                abort_unless($w, 404);
                if ($w->status !== 'approved') {
                    throw ValidationException::withMessages(['withdrawal' => 'Only an approved request can be recorded as paid.']);
                }
                if (CommissionWallet::hasDebt($w->user_id)) {
                    throw ValidationException::withMessages(['withdrawal' => 'Reconcile outstanding commission clawbacks before recording payment.']);
                }
                if ($actor->id === $w->user_id || $actor->id === $w->reviewed_by) {
                    throw ValidationException::withMessages(['withdrawal' => 'Payment recording needs a different authorized staff member from the reviewer.']);
                }
                if (DB::table('withdrawals')->where('payment_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['payment_reference' => 'This payment reference is already recorded.']);
                }
                app(PaymentAccounts::class)->recordReference($actor, $reference, 'withdrawal:'.$id, $paymentAccountId);
                DB::table('withdrawals')->where('id', $id)->update(['payment_account_id' => $paymentAccountId]);
                $wallet = DB::table('wallets')->where('user_id', $w->user_id)->lockForUpdate()->first();
                if (! $wallet || Money::cents($wallet->held) - Money::cents($wallet->commission_held) < Money::cents($w->amount)) {
                    throw ValidationException::withMessages(['withdrawal' => 'Reserved funds do not reconcile.']);
                }
                DB::table('wallets')->where('id', $wallet->id)->update(['held' => Money::decimal(Money::cents($wallet->held) - Money::cents($w->amount)), 'updated_at' => now()]);
                $entry = (string) Str::uuid();
                DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'manual-payout:'.$id, 'description' => 'Manual payout recorded with receipt', 'actor_id' => $actor->id, 'created_at' => now()]);
                DB::table('journal_lines')->insert([['entry_id' => $entry, 'user_id' => $w->user_id, 'account' => 'wallet_liability', 'debit' => $w->amount, 'credit' => '0.00'], ['entry_id' => $entry, 'user_id' => null, 'account' => 'manual_payout_clearing', 'debit' => '0.00', 'credit' => $w->net_amount ?? $w->amount], ['entry_id' => $entry, 'user_id' => null, 'account' => 'payout_withholding_payable', 'debit' => '0.00', 'credit' => $w->withheld_amount]]);
                DB::table('withdrawals')->where('id', $id)->update(['status' => 'paid', 'payment_reference' => $reference, 'paid_at' => $paidAt, 'paid_by' => $actor->id, 'receipt_path' => $path, 'receipt_mime' => $receipt->getMimeType(), 'receipt_sha256' => hash_file('sha256', $receipt->getRealPath()), 'updated_at' => now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'paid', 'note' => $note, 'created_at' => now()]);
                Audit::record($actor->id, 'withdrawal.paid', $id, ['receipt_sha256' => hash_file('sha256', $receipt->getRealPath())]);
                Audit::notice($w->user_id, 'Manual payment recorded', 'Your withdrawal payment and receipt are available in withdrawal history.');
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
