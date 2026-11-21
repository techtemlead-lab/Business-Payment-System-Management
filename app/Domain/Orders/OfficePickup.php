<?php

namespace App\Domain\Orders;

use App\Models\Order;

/**
 * Goods the partner collects at the office instead of a courier parcel.
 *
 * An order says how it is delivered in `orders.delivery_method`: "courier" or "office". The
 * shipment record has no separate "pickup" field: a hand-over at the office is saved with the
 * courier name "Office pickup" (staff may also have typed the Bangla words), so that is what
 * counts as a pickup on a shipment.
 */
final class OfficePickup
{
    /** The courier name saved when the partner collects the goods at the office. */
    public const Courier = 'Office pickup';

    /** The order is sent by courier (every order before the choice existed). */
    public const MethodCourier = 'courier';

    /** The partner collects the goods at the office. */
    public const MethodOffice = 'office';

    /** @var list<string> */
    public const Methods = [self::MethodCourier, self::MethodOffice];

    public static function matches(?string $courier): bool
    {
        $words = mb_strtolower(trim((string) $courier));
        if ($words === '') {
            return false;
        }
        if (str_contains($words, 'অফিস')) {
            return true;
        }

        return str_contains($words, 'office') && (str_contains($words, 'pick') || str_contains($words, 'collect') || str_contains($words, 'taken'));
    }

    /** Whether the partner collects this order's goods at the office. */
    public static function isPickupOrder(Order $order): bool
    {
        return $order->getAttribute('delivery_method') === self::MethodOffice;
    }
}
