<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionQualification;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The owner's rule for money figures: an order counts only after its goods arrive. A delivered
 * order counts whole, a partly delivered order counts the delivered parcels at the price charged
 * (free goods add nothing), and every other order counts nothing. All sums read these same
 * expressions, so lists, footers and reports cannot disagree.
 */
final class DeliveredMoney
{
    /** Order statuses with goods that reached the partner, whole or in part. */
    public const Statuses = CommissionQualification::DeliveredStatuses;

    /** SQL for the money value of the goods delivered on one order. */
    public static function valueSql(string $orders = 'orders'): string
    {
        return "CASE WHEN {$orders}.status = 'delivered' THEN {$orders}.total"
            ." WHEN {$orders}.status = '".MixedOutcomeDecisions::PartlyDelivered."' THEN ".self::partlyDeliveredSql($orders)
            .' ELSE 0 END';
    }

    /** SQL for how much of the paid money is for delivered goods. */
    public static function paidSql(string $orders = 'orders'): string
    {
        return "LEAST({$orders}.paid, ".self::valueSql($orders).')';
    }

    /** SQL for the units of one order line that were delivered. */
    public static function lineQuantitySql(string $orders = 'o', string $lines = 'l'): string
    {
        return "CASE WHEN {$orders}.status = 'delivered' THEN {$lines}.quantity ELSE COALESCE((SELECT SUM((item.value->>'quantity')::int)"
            .' FROM shipments AS parcel CROSS JOIN LATERAL json_array_elements(parcel.lines) AS item(value)'
            ." WHERE parcel.order_id = {$orders}.id AND parcel.status = 'delivered' AND (item.value->>'line_id')::bigint = {$lines}.id), 0) END";
    }

    /** SQL for the money value of the units of one order line that were delivered. */
    public static function lineValueSql(string $orders = 'o', string $lines = 'l'): string
    {
        return "CASE WHEN {$orders}.status = 'delivered' THEN {$lines}.total ELSE {$lines}.unit_price * ".self::lineQuantitySql($orders, $lines).' END';
    }

    /** The money value of the goods delivered on one order, as a decimal string. */
    public static function valueOf(Order $order): string
    {
        $value = DB::table('orders')->where('id', $order->getKey())->selectRaw(self::valueSql().' AS value')->value('value');

        return Money::decimal(Money::cents((string) ($value ?? '0')));
    }

    private static function partlyDeliveredSql(string $orders): string
    {
        return "COALESCE((SELECT SUM((item.value->>'quantity')::int * line.unit_price)"
            .' FROM shipments AS parcel CROSS JOIN LATERAL json_array_elements(parcel.lines) AS item(value)'
            ." INNER JOIN order_lines AS line ON line.id = (item.value->>'line_id')::bigint"
            ." WHERE parcel.order_id = {$orders}.id AND parcel.status = 'delivered' AND line.is_free IS NOT TRUE), 0)";
    }
}
