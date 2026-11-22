<?php

namespace App\Support;

/**
 * The short name people see for a rank. The stored rank keys (dealer, mo, am, sm, me) never change: the top key
 * `me` is shown as "ED" (owner, 2026-11-21). Every place that builds a rank name from the key itself uses this, so
 * the top rank is never shown as "ME" by an upper-casing shortcut. The same map is in resources/js/shared/rankLabel.ts.
 */
final class RankLabel
{
    /** @var array<string, string> Stored rank key => the name people see, where it is not just the key in capitals. */
    public const Shown = ['me' => 'ED'];

    public static function of(string $key): string
    {
        $key = strtolower(trim($key));

        return self::Shown[$key] ?? strtoupper($key);
    }
}
