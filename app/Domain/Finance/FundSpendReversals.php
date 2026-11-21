<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The correction for a paid company fund spend whose money came back (paid by mistake, refunded
 * by the payee). The spend stays paid with its own journal, so the record of what happened is
 * kept; this posts the spend's journal again with debit and credit swapped
 * (fund.spend_reversed.{id}), gives the amount back to the fund (the allocation rows it drew on,
 * or the fund's own figure for the company share, clinic and owners' funds) and writes the reason.
 *
 * Two people: the same approvers as the spend; whoever approved it cannot correct it alone (the
 * owner may). A spend partly taken back by an order refund is left to the technical team.
 */
final class FundSpendReversals
{
    public function reverse(User $actor, int $id, string $reason, string $returnedOn): void
    {
        abort_unless(FundSpending::canManage($actor), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $reason, $returnedOn): void {
            CommissionWallet::lock();
            $row = DB::table('reserve_disbursements')->where('id', $id)->where('program', FundSpending::Program)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_unless(FundSpending::approverOf((string) $row->fund_key, (string) $row->amount)($actor->fresh()), 403);
            if ($row->status !== 'paid' || DB::table('fund_spend_reversals')->where('disbursement_id', $id)->exists()) {
                throw ValidationException::withMessages(['spend' => __('funds.err_reverse_not_paid')]);
            }
            $selfApproved = (int) $row->approved_by === (int) $actor->id;
            if ($selfApproved) {
                app(SelfApproval::class)->finishOwnStep($actor, 'spend', __('funds.err_reverse_own'));
            }
            if (DB::table('reserve_expense_sources')->where('disbursement_id', $id)->where('reversed_amount', '>', 0)->exists()) {
                throw ValidationException::withMessages(['spend' => __('funds.err_reverse_partly_taken_back')]);
            }
            $spend = DB::table('journal_entries')->where('event_key', 'fund.spend.'.$id)->first();
            if ($spend === null) {
                throw ValidationException::withMessages(['spend' => __('funds.err_reverse_not_paid')]);
            }
            $lines = DB::table('journal_lines')->where('entry_id', $spend->id)->get(['user_id', 'account', 'debit', 'credit']);
            $entry = (string) Str::uuid();
            DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'fund.spend_reversed.'.$id, 'description' => mb_substr((string) __('funds.jr_spend_reversed', ['payee' => (string) $row->payee_name]), 0, 255), 'actor_id' => $actor->id, 'created_at' => now()]);
            DB::table('journal_lines')->insert($lines->map(fn (object $line): array => [
                'entry_id' => $entry, 'user_id' => $line->user_id, 'account' => $line->account, 'debit' => (string) $line->credit, 'credit' => (string) $line->debit,
            ])->all());
            // Allocation funds read amount - reversed_amount, so the money is back in the rows it came from.
            DB::table('reserve_expense_sources')->where('disbursement_id', $id)->update(['reversed_amount' => DB::raw('amount'), 'updated_at' => now()]);
            DB::table('fund_spend_reversals')->insert(['disbursement_id' => $id, 'amount' => Money::decimal(Money::cents((string) $row->amount)), 'returned_on' => $returnedOn,
                'reason' => $reason, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'fund.spend_reversed', (string) $id, ['fund' => $row->fund_key, 'amount' => (string) $row->amount, 'returned_on' => $returnedOn, 'reason' => $reason, ...($selfApproved ? ['self_approved' => true] : [])]);
        }, 3);
    }

    /** Cents given back per fund, for the funds whose spends are counted directly. */
    public static function reversedByFund(): array
    {
        return DB::table('fund_spend_reversals as v')->join('reserve_disbursements as r', 'r.id', '=', 'v.disbursement_id')
            ->groupBy('r.fund_key')->selectRaw('r.fund_key, SUM(v.amount) AS total')->pluck('total', 'fund_key')
            ->map(fn ($total): int => Money::cents((string) $total))->all();
    }
}
