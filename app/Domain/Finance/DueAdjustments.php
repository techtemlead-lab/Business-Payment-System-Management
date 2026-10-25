<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DueAdjustments
{
    /** @return Collection<int, object> */
    public function history(Order $order): Collection
    {
        return DB::table('due_adjustments as a')->join('users as c', 'c.id', '=', 'a.created_by')->leftJoin('users as r', 'r.id', '=', 'a.reviewed_by')
            ->where('a.order_id', $order->id)->orderBy('a.id')->get(['a.id', 'a.reference', 'a.kind', 'a.amount', 'a.status', 'a.reason', 'a.review_reason',
                'a.before_outstanding', 'a.after_outstanding', 'a.created_at', 'a.reviewed_at', 'c.name as prepared_by', 'r.name as reviewed_by']);
    }

    /** @param array<string, mixed> $data */
    public function request(User $actor, Order $order, array $data): int
    {
        return DB::transaction(function () use ($actor, $order, $data): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'create'), 403);
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $reference = Str::upper(trim($data['reference']));
            $existing = DB::table('due_adjustments')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_if($existing->order_id !== $order->id || $existing->kind !== $data['kind'] || $existing->reference !== $reference
                    || Money::cents($existing->amount) !== Money::cents($data['amount']) || $existing->reason !== $data['reason'], 409, __('portal.err_idem_adjustment_key'));

                return $existing->id;
            }
            if (DB::table('due_adjustments')->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages(['reference' => __('portal.err_due_ref_exists')]);
            }
            $this->validate($order, $data['kind'], Money::cents($data['amount']));
            $id = DB::table('due_adjustments')->insertGetId(['order_id' => $order->id, 'kind' => $data['kind'], 'amount' => Money::decimal(Money::cents($data['amount'])),
                'request_key' => $data['request_key'], 'reference' => $reference, 'reason' => $data['reason'], 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'dues.adjustment_requested', (string) $id, ['order_id' => $order->id, 'reference' => $reference, 'kind' => $data['kind'], 'amount' => $data['amount'], 'reason' => $data['reason']]);

            return $id;
        }, 3);
    }

    public function review(User $actor, int $id, string $decision, string $reason): void
    {
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'approve'), 403);
            $adjustment = DB::table('due_adjustments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($adjustment, 404);
            $order = Order::whereKey($adjustment->order_id)->lockForUpdate()->firstOrFail();
            if (in_array($actor->id, [$adjustment->created_by, $order->user_id], true)) {
                throw ValidationException::withMessages(['adjustment' => __('portal.err_due_separate_reviewer')]);
            }
            if (! in_array($decision, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => __('portal.err_due_decision')]);
            }
            if ($adjustment->status !== 'requested') {
                abort_unless($adjustment->status === $decision && $adjustment->review_reason === $reason, 409, __('portal.err_adj_already_reviewed'));

                return;
            }
            $before = $order->outstanding();
            $after = $before;
            $amount = Money::cents($adjustment->amount);
            if ($decision === 'approved') {
                $this->validate($order, $adjustment->kind, $amount);
                $credit = $adjustment->kind === 'credit';
                $order->update(['due_credit' => Money::decimal(Money::cents($order->due_credit) + ($credit ? $amount : -$amount))]);
                $after = $order->outstanding();
                app(CommissionWallet::class)->journal('dues.adjustment.'.$id, 'Receivable adjustment '.$adjustment->reference, [
                    ['user_id' => $order->user_id, 'account' => 'sales_allowances', 'debit' => $credit ? $amount : 0, 'credit' => $credit ? 0 : $amount],
                    ['user_id' => $order->user_id, 'account' => $order->is_opening_balance ? 'opening_receivables' : 'customer_receivable_adjustments', 'debit' => $credit ? 0 : $amount, 'credit' => $credit ? $amount : 0],
                ]);
            }
            DB::table('due_adjustments')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->id, 'review_reason' => $reason,
                'before_outstanding' => $before, 'after_outstanding' => $after, 'reviewed_at' => now(), 'updated_at' => now()]);
            $metadata = ['adjustment_id' => $id, 'reference' => $adjustment->reference, 'kind' => $adjustment->kind, 'amount' => $adjustment->amount,
                'before_outstanding' => $before, 'after_outstanding' => $after, 'reason' => $reason];
            DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => 'dues.adjustment_'.$decision, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            Audit::record($actor->id, 'dues.adjustment_'.$decision, (string) $id, $metadata);
            $partner = User::find($order->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $decisionLabel = __('portal.due_decision_'.$decision, [], $locale);
            Audit::notice(
                $order->user_id,
                __('portal.notice_due_adjustment_title', ['decision' => $decisionLabel], $locale),
                __('portal.notice_due_adjustment_body', [
                    'number' => $order->number,
                    'reference' => $adjustment->reference,
                    'amount' => $after,
                ], $locale),
            );
        }, 3);
    }

    private function validate(Order $order, string $kind, int $amount): void
    {
        if (! in_array($kind, ['credit', 'debit_correction'], true) || $amount < 1
            || in_array($order->status, ['cancelled', 'rejected', 'submitted', 'held'], true)) {
            throw ValidationException::withMessages(['adjustment' => __('portal.err_due_invalid')]);
        }
        $maximum = $kind === 'credit' ? Money::cents($order->outstanding()) : Money::cents($order->due_credit);
        if ($amount > $maximum) {
            throw ValidationException::withMessages(['amount' => $kind === 'credit'
                ? __('portal.err_due_credit_exceeds')
                : __('portal.err_due_debit_restore_only')]);
        }
    }
}
