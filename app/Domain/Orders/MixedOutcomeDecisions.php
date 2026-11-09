<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Inventory\BranchOperations;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders with a mixed outcome: one parcel delivered and another received back. Such an order
 * never reaches "delivered", so nothing posts on its own. Staff talk to the dealer and decide,
 * with a required reason: commission on the delivered part only, or none at all.
 *
 * A decision is made once per order and cannot be changed here. The delivered-part choice posts
 * through CommissionPosting in the same transaction, so the decision and the money stand or fall
 * together.
 */
final class MixedOutcomeDecisions
{
    public const Action = 'order.mixed_outcome_decided';

    public const DeliveredPart = 'delivered_part';

    public const NoCommission = 'no_commission';

    public const Choices = [self::DeliveredPart, self::NoCommission];

    /** Most orders listed at a time. */
    private const ListLimit = 50;

    public function __construct(
        private readonly CommissionPosting $commissions,
        private readonly BranchOperations $branches,
    ) {}

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
        abort_unless($this->branches->mayOpenOrder($actor, $order), 403);
        DB::transaction(function () use ($actor, $order, $choice, $reason): void {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (DB::table('order_events')->where('order_id', $locked->id)->where('action', self::Action)->exists()) {
                throw ValidationException::withMessages(['decision' => __('courier_more.err_decision_already')]);
            }
            if (! $this->waiting()->where('orders.id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['decision' => __('courier_more.err_decision_not_needed')]);
            }
            $metadata = ['decision' => $choice, 'reason' => $reason];
            if ($choice === self::DeliveredPart) {
                $metadata['commission_posted'] = $this->commissions->processDeliveredPart($locked, $actor->id) !== [];
            }
            DB::table('order_events')->insert(['order_id' => $locked->id, 'actor_id' => $actor->id, 'action' => self::Action,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            Audit::record($actor->id, self::Action, $locked->id, $metadata);
            $partner = User::query()->find($locked->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $replace = ['number' => $locked->number, 'decision' => __('schemes_commission.decision_'.$choice, [], $locale), 'reason' => $reason];
            Audit::notice($locked->user_id, __('courier_more.notice_decision_title', $replace, $locale), __('courier_more.notice_decision_body', $replace, $locale));
        }, 3);
    }

    /** Delivered and received-back parcels, nothing on the road or left to send, and no decision yet. */
    private function waiting(): QueryBuilder
    {
        $parcel = fn (string $status): Closure => fn (QueryBuilder $query) => $query->selectRaw('1')->from('shipments')
            ->whereColumn('shipments.order_id', 'orders.id')->where('shipments.status', $status);

        return DB::table('orders')
            ->whereNotIn('orders.status', ['cancelled', 'rejected', 'delivered'])
            ->whereExists($parcel('delivered'))
            ->whereExists($parcel('returned'))
            ->whereNotExists($parcel('dispatched'))
            ->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('order_lines')
                ->whereColumn('order_lines.order_id', 'orders.id')->whereColumn('order_lines.dispatched', '<', 'order_lines.quantity'))
            ->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('order_events')
                ->whereColumn('order_events.order_id', 'orders.id')->where('order_events.action', self::Action));
    }
}
