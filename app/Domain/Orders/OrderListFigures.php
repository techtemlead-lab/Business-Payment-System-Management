<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\Money;

/**
 * The extra figures every order list shows beside an order: its MRP, what is left to pay and the
 * buyer's address. The Orders page and the dashboard's Orders tab read them from here so the two
 * never differ. Free scheme goods add nothing to the MRP, as on the invoice.
 */
final class OrderListFigures
{
    /** Sum of MRP x quantity of the order's priced lines, as a SQL expression for a query on `orders`. */
    public const MrpSql = '(SELECT COALESCE(SUM(order_lines.mrp * order_lines.quantity), 0) FROM order_lines WHERE order_lines.order_id = orders.id AND NOT order_lines.is_free)';

    /** What is still to pay. A refused or cancelled order owes nothing. */
    public static function toPay(Order $order): string
    {
        if (in_array($order->status, ['rejected', 'cancelled'], true)) {
            return Money::decimal(0);
        }
        $left = Money::cents((string) $order->total) - Money::cents((string) $order->getAttribute('due_credit')) - Money::cents((string) $order->paid);

        return Money::decimal(max(0, $left));
    }

    /**
     * The buyer's address from the names saved on the account, smallest place first
     * ("Village, Upazila, District, Division"). Never reads the database.
     */
    public static function addressLine(?User $buyer): ?string
    {
        if (! $buyer instanceof User) {
            return null;
        }
        $saved = $buyer->getAttribute('profile');
        $profile = is_array($saved) ? $saved : [];
        $parts = array_values(array_unique(array_filter(array_map(
            static fn (mixed $part): string => trim((string) $part),
            [$profile['village'] ?? null, $profile['union'] ?? null, $profile['upazila'] ?? null, $buyer->getAttribute('district'), $buyer->getAttribute('division')],
        ), static fn (string $part): bool => $part !== '')));

        return $parts === [] ? null : implode(', ', $parts);
    }
}
