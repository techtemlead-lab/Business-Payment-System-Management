<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Orders that were sent but never marked delivered. Commission is only posted when staff
 * mark an order delivered, so an order stuck here means a dealer is waiting for money.
 * This class only reads: it never changes an order, a shipment or any money.
 */
final class OverdueDeliveries
{
    /** An order counts as overdue once its latest shipment is more than this many Dhaka days old. */
    public const Days = 7;

    /** The deliveries list filter value (?status=overdue_delivery). */
    public const Filter = 'overdue_delivery';

    /** Order states that have goods on the road and are not yet marked delivered. */
    public const Statuses = ['dispatched', 'partially_dispatched'];

    /**
     * Staff who may see and act on the list: anyone with the orders or inventory permission.
     */
    public static function visibleTo(User $viewer): bool
    {
        return $viewer->allows('orders') || $viewer->allows('inventory');
    }

    /**
     * Today's date in business time (Asia/Dhaka), used to count whole days since sending.
     */
    public static function today(): string
    {
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));

        return CarbonImmutable::now($timezone)->toDateString();
    }

    /**
     * SQL for the Dhaka calendar days since the order's latest shipment was created.
     * Bind {@see today()} then the business timezone, in that order.
     */
    public static function daysSinceSentSql(): string
    {
        return "(CAST(? AS date) - DATE((SELECT MAX(shipments.created_at) FROM shipments WHERE shipments.order_id = orders.id) AT TIME ZONE 'UTC' AT TIME ZONE ?))";
    }

    /**
     * SQL condition that is true for an overdue order. Bind {@see bindings()}.
     */
    public static function conditionSql(): string
    {
        return "(orders.status IN ('".implode("', '", self::Statuses)."') AND ".self::daysSinceSentSql().' > '.self::Days.' AND '.self::notSettledByReturnSql().')';
    }

    /**
     * A parcel received back ends the wait: after one, only another parcel still on the road
     * keeps the order overdue.
     */
    private static function notSettledByReturnSql(): string
    {
        return "(NOT EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'returned')"
            ." OR EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'dispatched'))";
    }

    /**
     * @return list<string>
     */
    public static function bindings(): array
    {
        return [self::today(), (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'))];
    }

    /**
     * Limit an order query to overdue orders.
     */
    public function apply(Builder $query): Builder
    {
        return $query->whereRaw(self::conditionSql(), self::bindings());
    }

    /**
     * Add the latest shipment's courier, tracking number and days since it was sent to each
     * row, as sub-selects on the list query itself so the page runs no extra queries.
     */
    public function withShipmentDetails(Builder $query): Builder
    {
        $latest = fn (string $column): string => "(SELECT shipments.{$column} FROM shipments WHERE shipments.order_id = orders.id ORDER BY shipments.created_at DESC, shipments.id DESC LIMIT 1)";

        return $query->select('orders.*')
            ->selectRaw($latest('courier').' AS last_courier')
            ->selectRaw($latest('tracking').' AS last_tracking')
            ->selectRaw(self::daysSinceSentSql().' AS days_since_sent', self::bindings());
    }

    /**
     * How many orders are overdue right now.
     */
    public function count(): int
    {
        return $this->apply(Order::query())->count();
    }
}
