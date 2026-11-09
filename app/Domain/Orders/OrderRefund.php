<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionWallet;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends a dealer's money back for an order that was paid but never sent out.
 *
 * Orders are paid in full before goods leave, and stock only leaves the shelf
 * when a shipment is recorded, so a refund is allowed only before dispatch:
 * no stock has moved yet and none needs to be put back. Commission is only
 * posted on delivery, but if any commission was ever posted for the order it
 * is reversed in full through CommissionReversal.
 */
final class OrderRefund
{
    /** Order statuses where money is in but no goods have left the warehouse. */
    public const RefundableStatuses = ['submitted', 'held', 'approved'];

    public function __construct(
        private readonly CommissionReversal $reversal,
        private readonly AccountOrderPayment $accountPayment,
    ) {}

    /** Owners always may; other staff need the finance right with approval allowed. */
    public static function canRefund(User $actor): bool
    {
        return $actor->status === 'active'
            && ($actor->owner() || ($actor->allows('finance') && $actor->canAct('finance', 'approve')));
    }

    /**
     * @return array{id: string, amount: string, commissions_reversed: int}
     */
    public function refund(User $actor, Order $order, string $amount, string $reason): array
    {
        abort_unless(self::canRefund($actor), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));

        return DB::transaction(function () use ($actor, $order, $amount, $reason): array {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (DB::table('order_refunds')->where('order_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['refund' => __('portal.err_refund_already')]);
            }
            if (! in_array($locked->status, self::RefundableStatuses, true)) {
                throw ValidationException::withMessages(['refund' => __('portal.err_refund_not_allowed')]);
            }
            $paid = Money::cents($locked->paid);
            if ($paid <= 0) {
                throw ValidationException::withMessages(['refund' => __('portal.err_refund_not_paid')]);
            }
            $cents = Money::cents($amount);
            if ($cents <= 0 || $cents > $paid) {
                throw ValidationException::withMessages(['amount' => __('portal.err_refund_amount', ['amount' => Money::grouped($locked->paid)])]);
            }
            $id = (string) Str::uuid();
            // Money paid from the partner's own account goes back there first; only the rest is handed over by hand.
            $toAccount = min($cents, $this->accountPayment->paidFromAccount($locked));
            DB::table('order_refunds')->insert([
                'id' => $id, 'order_id' => $locked->id, 'user_id' => $locked->user_id, 'amount' => Money::decimal($cents),
                'paid_before' => Money::decimal($paid), 'to_account' => Money::decimal($toAccount), 'reason' => $reason, 'refunded_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($toAccount > 0) {
                $this->accountPayment->giveBack($locked, $actor, $toAccount, $id);
            }
            $reversed = [];
            if (DB::table('commission_batches')->where('order_id', $locked->id)->whereColumn('refunded_amount', '<', 'cash_basis')->exists()) {
                $reversed = $this->reversal->refund($locked, null, $reason, 'order-refund:'.$id, $actor->id);
            }
            $locked->update(['status' => 'cancelled', 'decision_reason' => $reason]);
            $metadata = ['refund_id' => $id, 'amount' => Money::decimal($cents), 'paid_before' => Money::decimal($paid), 'to_account' => Money::decimal($toAccount),
                'reason' => $reason, 'commissions_reversed' => count($reversed)];
            DB::table('order_events')->insert(['order_id' => $locked->id, 'actor_id' => $actor->id, 'action' => 'order.refunded',
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            Audit::record($actor->id, 'order.refunded', $locked->id, $metadata);
            if ($reversed === []) {
                $locale = User::find($locked->user_id)?->preferredLocale() ?? app()->getLocale();
                Audit::notice(
                    $locked->user_id,
                    __('portal.notice_order_refunded_title', [], $locale),
                    __('portal.notice_order_refunded_body', ['number' => $locked->number, 'amount' => Money::decimal($cents), 'reason' => $reason], $locale),
                );
            }

            return ['id' => $id, 'amount' => Money::decimal($cents), 'commissions_reversed' => count($reversed)];
        }, 3);
    }
}
