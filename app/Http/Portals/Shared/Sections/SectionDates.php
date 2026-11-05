<?php

namespace App\Http\Portals\Shared\Sections;

use Carbon\CarbonImmutable;

/**
 * Date filters read from the query string by the partner money screens and the
 * full report download.
 */
final class SectionDates
{
    /**
     * The value when it is a Y-m-d date, otherwise null.
     */
    public static function optionalDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * UTC bounds for whole business days: the start of `$from` and the start of the day after `$to`.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function businessDayBounds(?string $from, ?string $to): array
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $fromUtc = $from
            ? CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc()
            : null;
        $toUtc = $to
            ? CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->addDay()->startOfDay()->utc()
            : null;

        return [$fromUtc, $toUtc];
    }
}
