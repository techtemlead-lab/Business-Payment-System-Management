<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders with a mixed outcome: one parcel delivered and another received back. Staff talk to
 * the dealer and record what should happen to the commission, with a required reason.
 *
 * This only records the decision. It never reads or moves commission. An order like this
 * never reaches "delivered", so no commission was posted for it, and the existing commission
 * reversal cannot run without a posted commission batch.
 */
final class MixedOutcomeDecisions
{
    public const Action = 'order.mixed_outcome_decided';

    public const Choices = ['returned_part', 'whole_sale'];

    /** Most orders listed at a time. */
    private const ListLimit = 50;

    /**
     * @param  Builder  $orderIds  the order ids this reader may see
     * @return list<object>
     */
    public function pending(Builder $orderIds): array
    {
        return $this->waiting()
            ->whereIn('orders.id', clone $orderIds)
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->orderBy('orders.updated_at')
            ->limit(self::ListLimit)
            ->get(['orders.id as order_id', 'orders.number', 'users.name as partner'])
            ->all();
    }

    public function decide(User $actor, Order $order, string $choice, string $reason): void
    {
        abort_unless($actor->allows('order_review'), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        DB::transaction(function () use ($actor, $order, $choice, $reason): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (DB::table('order_events')->where('order_id', $locked->id)->where('action', self::Action)->exists()) {
                throw ValidationException::withMessages(['decision' => __('courier_more.err_decision_already')]);
            }
            if (! $this->waiting()->where('orders.id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['decision' => __('courier_more.err_decision_not_needed')]);
            }
            $metadata = ['decision' => $choice, 'reason' => $reason];
            DB::table('order_events')->insert(['order_id' => $locked->id, 'actor_id' => $actor->id, 'action' => self::Action,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            Audit::record($actor->id, self::Action, $locked->id, $metadata);
            $partner = User::query()->find($locked->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $replace = ['number' => $locked->number, 'decision' => __('courier_more.decision_'.$choice, [], $locale), 'reason' => $reason];
            Audit::notice($locked->user_id, __('courier_more.notice_decision_title', $replace, $locale), __('courier_more.notice_decision_body', $replace, $locale));
        }, 3);
    }

    /** Delivered and received-back parcels, none still on the road, and no decision yet. */
    private function waiting(): QueryBuilder
    {
        $parcel = fn (string $status): Closure => fn (QueryBuilder $query) => $query->selectRaw('1')->from('shipments')
            ->whereColumn('shipments.order_id', 'orders.id')->where('shipments.status', $status);

        return DB::table('orders')
            ->whereExists($parcel('delivered'))
            ->whereExists($parcel('returned'))
            ->whereNotExists($parcel('dispatched'))
            ->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('order_events')
                ->whereColumn('order_events.order_id', 'orders.id')->where('order_events.action', self::Action));
    }
}
