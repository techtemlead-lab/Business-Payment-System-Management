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

    public static function text(string|int|float|null $value, ?string $locale = null): string
    {
        $text = (string) ($value ?? '');

        return ($locale ?? app()->getLocale()) === 'bn'
            ? str_replace(range(0, 9), self::Bangla, $text)
            : $text;
    }

    /** The same text with Bangla digits turned into 0–9, for numbers typed on a Bangla keyboard. */
    public static function latin(string $value): string
    {
        return str_replace(self::Bangla, range(0, 9), $value);
    }
}
