<?php

namespace App\Domain\Orders;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The number printed as "Invoice No" on every new order: MNHC-{YYYYMMDD}-{sequence}, for example MNHC-20261127-100001.
 * The date is the order date in Bangladesh time (madina.business_timezone) and the sequence starts again at 100001
 * every day. Because the whole date is in the number, a number is never used twice. The sequence comes from one row
 * per day (order_number_days) that is updated in the caller's transaction: two orders placed at the same moment queue
 * on that row, and an order that is rolled back gives its number back.
 * Orders placed before this rule keep their stored number (MN-261121-... and so on); the lists and the search match
 * inside the number, so "20261127" finds a day, "100001" finds every order with that sequence and the full number
 * finds one order.
 */
final class OrderNumbers
{
    public const Prefix = 'MNHC-';

    public const FirstOfDay = 100001;

    public const Pattern = '/^MNHC-[0-9]{8}-[0-9]+$/';

    /** The business day (Bangladesh time) a moment falls on, as Y-m-d. Now when no moment is given. */
    public static function day(?CarbonInterface $at = null): string
    {
        return ($at ?? now())->copy()->setTimezone((string) config('madina.business_timezone'))->format('Y-m-d');
    }

    /** Takes the next number of the order day. Call it inside the order's transaction. */
    public static function next(?CarbonInterface $at = null): string
    {
        $day = self::day($at);
        $row = DB::selectOne(
            'INSERT INTO order_number_days (day, last_value) VALUES (?, ?) ON CONFLICT (day) DO UPDATE SET last_value = order_number_days.last_value + 1 RETURNING last_value',
            [$day, self::FirstOfDay],
        );

        return self::Prefix.str_replace('-', '', $day).'-'.$row->last_value;
    }
}
