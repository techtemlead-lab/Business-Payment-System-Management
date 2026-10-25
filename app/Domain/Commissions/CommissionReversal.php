<?php

namespace App\Domain\Commissions;

use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommissionReversal
{
    public function __construct(private CommissionWallet $wallets, private CommissionQualification $qualification) {}

    /** @return list<array<string, mixed>> */
    public function refund(Order $order, ?int $requested, string $reason, string $reference, ?int $actorId = null, ?string $requestKey = null): array
    {
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($order, $requested, $reason, $reference, $actorId, $requestKey): array {
            CommissionWallet::lock();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $batch = DB::table('commission_batches')->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $batch) {
                throw ValidationException::withMessages(['order' => __('portal.err_rev_none')]);
            }
            if ($requestKey !== null) {
                $byKey = DB::table('commission_refunds')->where('request_key', $requestKey)->first();
                if ($byKey) {
                    if ($byKey->order_id !== $order->id || $byKey->reference !== $reference
                        || ($requested !== null && Money::cents($byKey->amount) !== $requested) || $byKey->reason !== $reason) {
                        abort(409, __('portal.err_idem_refund_key'));
                    }

                    return DB::table('commission_reversals')->where('refund_id', $byKey->id)->get()->map(fn ($r): array => (array) $r)->all();
                }
            }
            $existing = DB::table('commission_refunds')->where('order_id', $order->id)->where('reference', $reference)->first();
            if ($existing) {
                if (($requested !== null && Money::cents($existing->amount) !== $requested) || $existing->reason !== $reason
                    || ($requestKey !== null && $existing->request_key !== null && $existing->request_key !== $requestKey)) {
                    abort(409, __('portal.err_idem_refund_ref'));
                }

                return DB::table('commission_reversals')->where('refund_id', $existing->id)->get()->map(fn ($r): array => (array) $r)->all();
            }
            $cash = Money::cents($batch->cash_basis);
            $before = Money::cents($batch->refunded_amount);
            $amount = $requested ?? $cash - $before;
            if ($amount <= 0 || $amount > $cash - $before) {
                throw ValidationException::withMessages(['refund_amount' => __('portal.err_rev_refund')]);
            }
            $after = $before + $amount;
            $id = (string) Str::uuid();
            DB::table('commission_refunds')->insert(['id' => $id, 'order_id' => $order->id, 'reference' => $reference,
                'request_key' => $requestKey, 'amount' => Money::decimal($amount), 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            $allocations = CommissionAllocation::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $journal = [];
            $recoveredTotal = 0;
            $unrecoveredTotal = 0;
            foreach ($allocations as $allocation) {
                $original = Money::cents($allocation->original_amount ?? $allocation->amount);
                $current = Money::cents($allocation->amount);
                $targetReversed = $this->proportion($original, $after, $cash);
                $reverse = $targetReversed - ($original - $current);
                if ($reverse <= 0) {
                    continue;
                }
                $sourceStatus = $allocation->status;
                $recipients = [];
                if ($allocation->user_id && in_array($sourceStatus, ['available', 'maturing'], true)) {
                    $recipients[] = ['user_id' => $allocation->user_id, 'amount' => $reverse, 'held' => $sourceStatus === 'maturing'];
                }
                $payouts = DB::table('commission_settlement_payouts')->where('allocation_id', $allocation->id)->orderBy('id')->lockForUpdate()->get();
                foreach ($payouts as $payout) {
                    $basis = Money::cents($payout->refunded_basis);
                    $target = $this->proportion(Money::cents($payout->amount), max(0, $after - $basis), $cash - $basis);
                    $delta = $target - Money::cents($payout->reversed_amount);
                    if ($delta > 0) {
                        $recipients[] = ['user_id' => $payout->user_id, 'amount' => $delta, 'held' => false];
                        DB::table('commission_settlement_payouts')->where('id', $payout->id)->update(['reversed_amount' => Money::decimal($target), 'updated_at' => now()]);
                    }
                }
                foreach (DB::table('reserve_expense_sources as s')->join('reserve_disbursements as d', 'd.id', '=', 's.disbursement_id')
                    ->where('s.allocation_id', $allocation->id)->where('d.status', 'paid')->get(['s.*']) as $expense) {
                    $basis = Money::cents($expense->refunded_basis);
                    $target = $this->proportion(Money::cents($expense->amount), max(0, $after - $basis), $cash - $basis);
                    $delta = $target - Money::cents($expense->reversed_amount);
                    if ($delta > 0) {
                        DB::table('reserve_expense_sources')->where('id', $expense->id)->update(['reversed_amount' => Money::decimal($target), 'updated_at' => now()]);
                        DB::table('reserve_expense_clawbacks')->insert(['refund_id' => $id, 'source_id' => $expense->id, 'amount' => Money::decimal($delta), 'created_at' => now(), 'updated_at' => now()]);
                        $journal[] = ['account' => 'company_reserve_clawback_receivable', 'user_id' => null, 'debit' => $delta, 'credit' => 0];
                        $unrecoveredTotal += $delta;
                    }
                }
                $ownDebt = 0;
                if ($recipients === []) {
                    DB::table('commission_reversals')->insert(['refund_id' => $id, 'allocation_id' => $allocation->id,
                        'amount' => Money::decimal($reverse), 'source_status' => $sourceStatus, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($recipients as $recipient) {
                    $result = $this->wallets->debit($recipient['user_id'], $recipient['amount'], $recipient['held']);
                    DB::table('commission_reversals')->insert(['refund_id' => $id, 'allocation_id' => $allocation->id, 'user_id' => $recipient['user_id'],
                        'amount' => Money::decimal($recipient['amount']), 'recovered' => Money::decimal($result['recovered']),
                        'unrecovered' => Money::decimal($result['unrecovered']), 'source_status' => $sourceStatus, 'created_at' => now(), 'updated_at' => now()]);
                    $journal[] = ['user_id' => $recipient['user_id'], 'account' => $recipient['held'] ? 'commission_held_liability' : 'wallet_liability',
                        'debit' => $result['recovered'], 'credit' => 0];
                    $journal[] = ['user_id' => $recipient['user_id'], 'account' => 'commission_clawback_receivable', 'debit' => $result['unrecovered'], 'credit' => 0];
                    $recoveredTotal += $result['recovered'];
                    $unrecoveredTotal += $result['unrecovered'];
                    if ($recipient['user_id'] === $allocation->user_id) {
                        $ownDebt += $result['unrecovered'];
                    }
                }
                $extraMetadata = [];
                if ($allocation->category === 'rank_incentive_pool') {
                    $consumed = DB::table('commission_settlement_payouts')->where('allocation_id', $allocation->id)
                        ->selectRaw('COALESCE(SUM(amount-reversed_amount),0) AS consumed')->value('consumed');
                    $extraMetadata['incentive_consumed'] = (string) $consumed;
                }
                $allocation->update(['original_amount' => Money::decimal($original), 'amount' => Money::decimal($current - $reverse),
                    'status' => $current === $reverse ? 'reversed' : $sourceStatus,
                    'metadata' => [...$allocation->metadata, ...$extraMetadata, 'reversal_reason' => $reason, 'reversed_at' => now()->toIso8601String(),
                        'unrecovered_deficit' => Money::decimal(Money::cents((string) ($allocation->metadata['unrecovered_deficit'] ?? '0.00')) + $ownDebt)]]);
            }
            $expenseReversed = $recoveredTotal + $unrecoveredTotal;
            if ($expenseReversed > 0) {
                $journal[] = ['user_id' => null, 'account' => 'commission_clawback_recovery', 'debit' => 0, 'credit' => $expenseReversed];
                $this->wallets->journal('commissions.reversal.'.$id, __('portal.jr_commission_reversal', ['reason' => $reason]), $journal);
            }
            DB::table('commission_batches')->where('id', $batch->id)->update(['refunded_amount' => Money::decimal($after), 'updated_at' => now()]);
            $rank = DealerRank::where('user_id', $order->user_id)->lockForUpdate()->first();
            if ($rank) {
                $rank->update(['lifetime_purchase_total' => Money::decimal(max(0, Money::cents($rank->lifetime_purchase_total) - $amount)),
                    'monthly_purchase_total' => Money::decimal($this->qualification->monthlyVolume($order->user_id, CommissionQualification::currentMonth())), 'purchase_period' => CommissionQualification::currentMonth()]);
            }
            foreach (ReferralTreePath::getUplines($order->user_id, 100) as $path) {
                $this->qualification->refreshMilestones($path->ancestor_id);
            }
            Audit::record($actorId, 'commissions.reversed', $order->id, [
                'buyer_id' => $order->user_id, 'reference' => $reference, 'request_key' => $requestKey,
                'refund_amount' => Money::decimal($amount), 'reason' => $reason,
            ]);

            return DB::table('commission_reversals')->where('refund_id', $id)->get()->map(fn ($row): array => (array) $row)->all();
        }, 3);
    }

    private function proportion(int $amount, int $part, int $whole): int
    {
        return (int) DB::scalar('SELECT ROUND(CAST(? AS NUMERIC) * CAST(? AS NUMERIC) / CAST(? AS NUMERIC))', [$amount, $part, $whole]);
    }
}
