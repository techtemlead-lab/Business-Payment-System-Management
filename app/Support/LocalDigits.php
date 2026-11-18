<?php

namespace App\Support;

/**
 * Shows numbers in Bangla digits (০–৯) on Bangla pages, the server-side twin of
 * the portal's localizeDigits(). Use it for figures printed by Blade templates:
 * prices, counts, page numbers and years.
 */
final class LocalDigits
{
    private const Bangla = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private const Latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function text(string|int|float|null $value, ?string $locale = null): string
    {
        $text = (string) ($value ?? '');

        return ($locale ?? app()->getLocale()) === 'bn'
            ? str_replace(range(0, 9), self::Bangla, $text)
            : $text;
    }

    /**
     * An amount with lakh and crore grouping (2,50,000.00), the way the portals show it, in the
     * page's digits. Whole amounts can drop the ".00" with $decimals = 0.
     */
    public static function money(string|int|float|null $value, int $decimals = 2, ?string $locale = null): string
    {
        $number = number_format((float) ($value ?? 0), $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $number, 2), 2, '');
        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');
        if (strlen($whole) > 3) {
            $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
        }

        return self::text((($negative ? '-' : '').$whole).($fraction !== '' ? '.'.$fraction : ''), $locale);
    }

    /** The same text with Bangla digits turned into 0–9, for numbers typed on a Bangla keyboard. */
    public static function latin(string $value): string
    {
        return str_replace(self::Bangla, self::Latin, $value);
    }
}
