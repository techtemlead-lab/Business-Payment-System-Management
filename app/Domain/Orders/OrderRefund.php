<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;

/**
 * The finance "refund" card on a paid order. It is the cancel step of {@see OrderReturns}: the
 * goods not sent yet are closed and the money typed in goes back, account money to the account
 * first and the rest by hand. Anyone but the owner's own account needs a second person to
 * confirm before money moves. An order whose goods all came back by courier gets a money-only
 * refund the same way.
 */
final class OrderRefund
{
    /** Order statuses where money is in but no goods have left the warehouse. */
    public const RefundableStatuses = ['submitted', 'held', 'approved'];

    public function __construct(private readonly OrderReturns $returns) {}

    /** Owners always may; other staff need the finance right with approval allowed. */
    public static function canRefund(User $actor): bool
    {
        return $actor->status === 'active'
            && ($actor->owner() || ($actor->allows('finance') && $actor->canAct('finance', 'approve')));
    }

    /**
     * @return array{id: int, done: bool} the request, and whether the money already moved
     */
    public function refund(User $actor, Order $order, string $amount, string $reason): array
    {
        abort_unless(self::canRefund($actor), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        $order->loadMissing('lines');
        $kind = in_array($order->status, self::RefundableStatuses, true) ? OrderReturns::KindCancel : OrderReturns::KindRefund;
        $lines = $kind === OrderReturns::KindCancel
            ? $order->lines->map(fn ($line): array => ['line_id' => (int) $line->getKey(),
                'quantity' => max(0, (int) $line->getAttribute('quantity') - (int) $line->getAttribute('dispatched') - (int) $line->getAttribute('closed'))])->values()->all()
            : [];

        return $this->returns->start($actor, $order, $kind, $lines, $reason, $amount, OrderReturns::MethodCash);
    }
}
