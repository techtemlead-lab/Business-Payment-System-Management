<?php

namespace App\Domain\Orders;

/**
 * The shipment record has no separate "pickup" field. Staff record a pickup by typing "Office pickup"
 * (or the Bangla words) as the courier name, so that is what counts as a pickup.
 */
final class OfficePickup
{
    /** The courier name saved when the partner collects the goods at the office. */
    public const Courier = 'Office pickup';

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
}
