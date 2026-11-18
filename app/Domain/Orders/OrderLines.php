<?php

namespace App\Domain\Orders;

/**
 * Shared SQL for order lines. A line's units are sent (`dispatched`), closed before sending
 * (`closed`, a cancel or "close the rest") or still to send. Every "left to send" check reads
 * this one rule so a closed unit is never sent, reserved or waited for.
 */
final class OrderLines
{
    /** SQL condition: the line still has units to send. */
    public static function unsentSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return "{$prefix}dispatched + {$prefix}closed < {$prefix}quantity";
    }

    /** SQL for the units of the line still to send. */
    public static function unsentUnitsSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return "GREATEST({$prefix}quantity - {$prefix}dispatched - {$prefix}closed, 0)";
    }
}
