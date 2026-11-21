<?php

namespace App\Domain\Payouts;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\TakaText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Money came back" for a paid withdrawal: the transfer bounced or went to a wrong number, and the
 * money is back in the company account.
 *
 * One finance person records it and a second confirms it (the owner finishes alone). A withdrawal
 * at or above the owner's withdrawal limit needs an owner in one of the two steps. Confirming writes
 * the reverse of the payout journal (payout clearing and the tax withheld back, the partner's
 * wallet owed again), puts the money in the partner's wallet through the normal wallet credit (which
 * first pays off any debt the partner has), and sets the withdrawal to "returned". The payout
 * receipt and the proof stay as they were. A suspended or closed partner gets the money in the
 * wallet too; the usual rules still stop them from withdrawing it.
 */
final class PayoutReturns
{
    public const Status = 'returned';

    /** Journal key of the return; one per withdrawal, so it can never be written twice. */
    public const EventPrefix = 'manual-payout-return:';

    /**
     * The first step. Returns true when it was carried out at once (the owner acting alone).
     */
    public function request(User $actor, string $id, string $note): bool
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $id, $note): bool {
            CommissionWallet::lock();
            $partner = $this->lockPartnerOf($id);
            $w = $this->lockedWithdrawal($id);
            $this->refuseUnlessPaid($w);
            if ($w->return_requested_by !== null) {
                throw ValidationException::withMessages(['withdrawal' => __('payouts.err_return_already_recorded')]);
            }
            if ((int) $actor->id === (int) $w->user_id) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_self_review')]);
            }
            $this->refuseWithoutPayoutJournal($w);
            DB::table('withdrawals')->where('id', $id)->update(['return_requested_by' => $actor->id, 'return_requested_at' => now(), 'return_note' => $note, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'return_requested', 'note' => $note, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.return_requested', $id, ['user_id' => $w->user_id, 'amount' => $w->amount, 'note' => $note]);
            if (app(SelfApproval::class)->ownerFinishesAlone($actor)) {
                $this->carryOut($actor, $this->lockedWithdrawal($id), $partner, $note, true);

                return true;
            }
            $title = fn (string $locale): string => __('payouts.notice_return_second_title', [], $locale);
            $body = fn (string $locale): string => __('payouts.notice_return_second_body', ['name' => $partner->name, 'amount' => TakaText::amount($w->amount), 'by' => $actor->name], $locale);
            if (app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Withdrawal, (string) $w->amount) && ! OwnerApprovalLimits::ownerDecides($actor)) {
                Audit::notifyOwners(['finance'], $title, $body, $actor->id);
            } else {
                Audit::notifyStaffFirst(['finance'], $title, $body, $actor->id);
            }

            return false;
        }, 3);
    }

    /** The second person checks the first step and carries it out. */
    public function confirm(User $actor, string $id, string $note): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $note): void {
            CommissionWallet::lock();
            $partner = $this->lockPartnerOf($id);
            $w = $this->lockedWithdrawal($id);
            $this->refuseUnlessPaid($w);
            if ($w->return_requested_by === null) {
                throw ValidationException::withMessages(['withdrawal' => __('payouts.err_return_not_waiting')]);
            }
            if ((int) $w->return_requested_by === (int) $actor->id) {
                throw ValidationException::withMessages(['withdrawal' => __('payouts.err_return_same_person')]);
            }
            if ((int) $actor->id === (int) $w->user_id) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_self_review')]);
            }
            if ($this->secondMustBeOwner($w)) {
                app(OwnerApprovalLimits::class)->refuseUnlessOwnerMayApprove($actor, OwnerApprovalLimits::Withdrawal, (string) $w->amount, 'withdrawal');
            }
            $this->carryOut($actor, $w, $partner, $note, false);
        }, 3);
    }

    /** Takes back a "money came back" step nobody has confirmed (recorded by mistake). Moves no money. */
    public function cancel(User $actor, string $id, string $note): void
    {
        abort_unless($actor->allows('finance'), 403);
        DB::transaction(function () use ($actor, $id, $note): void {
            $w = $this->lockedWithdrawal($id);
            $this->refuseUnlessPaid($w);
            if ($w->return_requested_by === null) {
                throw ValidationException::withMessages(['withdrawal' => __('payouts.err_return_not_waiting')]);
            }
            DB::table('withdrawals')->where('id', $id)->update(['return_requested_by' => null, 'return_requested_at' => null, 'return_note' => null, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'return_cancelled', 'note' => $note, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.return_cancelled', $id, [
                'user_id' => $w->user_id, 'amount' => $w->amount, 'requested_by' => $w->return_requested_by, 'request_note' => $w->return_note, 'note' => $note,
            ]);
        }, 3);
    }

    /**
     * What this viewer may do about money coming back on this withdrawal: 'record' (paid, nothing
     * recorded yet), 'confirm' (someone else recorded it), 'wait_second' (the viewer recorded it),
     * 'wait_owner' (it needs an owner and the viewer is not one), or null.
     */
    public function state(object $withdrawal, User $viewer): ?string
    {
        if ($withdrawal->status !== 'paid' || ! $viewer->allows('finance')) {
            return null;
        }
        if ($withdrawal->return_requested_by === null) {
            return 'record';
        }
        if ((int) $withdrawal->return_requested_by === (int) $viewer->id) {
            return 'wait_second';
        }

        return $this->secondMustBeOwner($withdrawal) && ! OwnerApprovalLimits::ownerDecides($viewer) ? 'wait_owner' : 'confirm';
    }

    /** A withdrawal at or above the owner's limit needs an owner in one of the two steps. */
    private function secondMustBeOwner(object $withdrawal): bool
    {
        return app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Withdrawal, (string) $withdrawal->amount)
            && ! OwnerApprovalLimits::ownerDecides(User::find($withdrawal->return_requested_by));
    }

    private function carryOut(User $actor, object $w, User $partner, string $note, bool $selfApproved): void
    {
        $amount = Money::cents((string) $w->amount);
        $withheld = Money::cents((string) ($w->withheld_amount ?? '0.00'));
        $net = $w->net_amount !== null ? Money::cents((string) $w->net_amount) : $amount;
        if ($net + $withheld !== $amount) {
            throw new \LogicException('Withdrawal amounts do not add up.');
        }
        $locale = $partner->preferredLocale();
        $entry = (string) Str::uuid();
        // The reverse of the payout journal: payout clearing and the tax withheld come back, the wallet is owed again.
        DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => self::EventPrefix.$w->id, 'description' => __('payouts.journal_returned', [], $locale), 'actor_id' => $actor->id, 'created_at' => now()]);
        $lines = [['entry_id' => $entry, 'user_id' => null, 'account' => 'manual_payout_clearing', 'debit' => Money::decimal($net), 'credit' => '0.00']];
        if ($withheld > 0) {
            $lines[] = ['entry_id' => $entry, 'user_id' => null, 'account' => 'payout_withholding_payable', 'debit' => Money::decimal($withheld), 'credit' => '0.00'];
        }
        $lines[] = ['entry_id' => $entry, 'user_id' => $w->user_id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => Money::decimal($amount)];
        DB::table('journal_lines')->insert($lines);
        // The normal wallet credit: a debt the partner owes from a refund is paid off first, with its own journal.
        app(CommissionWallet::class)->credit((int) $w->user_id, $amount);
        DB::table('withdrawals')->where('id', $w->id)->update(['status' => self::Status, 'returned_by' => $actor->id, 'returned_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawal_events')->insert(['withdrawal_id' => $w->id, 'actor_id' => $actor->id, 'event' => 'returned', 'note' => $note, 'created_at' => now()]);
        Audit::record($actor->id, 'withdrawal.returned', $w->id, [
            'user_id' => $w->user_id, 'amount' => $w->amount, 'net_amount' => Money::decimal($net), 'withheld_amount' => Money::decimal($withheld),
            'payment_reference' => $w->payment_reference, 'requested_by' => $w->return_requested_by, 'request_note' => $w->return_note, 'note' => $note,
            'partner_status' => $partner->closed_at !== null ? 'closed' : $partner->status,
            ...($selfApproved ? ['self_approved' => true] : []),
        ]);
        Audit::notice(
            (int) $w->user_id,
            __('payouts.notice_returned_title', [], $locale),
            __('payouts.notice_returned_body', ['amount' => TakaText::amount($w->amount)], $locale),
        );
    }

    private function lockedWithdrawal(string $id): object
    {
        $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
        abort_unless($w !== null, 404);

        return $w;
    }

    /** Locks the account of the partner who asked for this withdrawal (partner first, as everywhere). */
    private function lockPartnerOf(string $withdrawalId): User
    {
        $userId = DB::table('withdrawals')->where('id', $withdrawalId)->value('user_id');
        abort_unless($userId !== null, 404);

        return User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    /** @throws ValidationException */
    private function refuseUnlessPaid(object $w): void
    {
        if ($w->status === self::Status) {
            throw ValidationException::withMessages(['withdrawal' => __('payouts.err_already_returned')]);
        }
        if ($w->status !== 'paid') {
            throw ValidationException::withMessages(['withdrawal' => __('payouts.err_return_not_paid')]);
        }
    }

    /**
     * The books must show the payout before it can be reversed: the manual-payout journal took
     * exactly this amount from the partner's wallet.
     *
     * @throws ValidationException
     */
    private function refuseWithoutPayoutJournal(object $w): void
    {
        $taken = DB::table('journal_entries as e')->join('journal_lines as l', 'l.entry_id', '=', 'e.id')
            ->where('e.event_key', 'manual-payout:'.$w->id)->where('l.account', 'wallet_liability')->where('l.user_id', $w->user_id)
            ->sum('l.debit');
        if (Money::cents((string) $taken) !== Money::cents((string) $w->amount)) {
            throw ValidationException::withMessages(['withdrawal' => __('payouts.err_return_no_payout_record')]);
        }
    }
}
