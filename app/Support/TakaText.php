<?php

namespace App\Support;

/**
 * One way to write a taka amount everywhere (SMS, bell, emails, pages), so nobody misreads
 * a big figure ten times too big or small. Lakh/crore grouping, Bangla digits on Bangla
 * pages, and a short reading in words for amounts from one lakh up.
 * The TypeScript twin is resources/js/shared/takaText.ts.
 */
final class TakaText
{
    private const Lakh = 100000;

    /**
     * Taka sign plus the lakh/crore grouped amount, e.g. "৳1,23,45,678" or "৳1,234.50".
     * Whole-taka amounts drop the ".00".
     *
     * @param  string|int  $decimal  A decimal string such as "12000000.00" or "300000".
     * @param  string|null  $locale  'bn' gives Bangla digits; null uses the app locale.
     */
    public static function amount(string|int $decimal, ?string $locale = null): string
    {
        $grouped = Money::grouped($decimal);
        if (str_ends_with($grouped, '.00')) {
            $grouped = substr($grouped, 0, -3);
        }

        return '৳'.LocalDigits::text($grouped, $locale);
    }

    /**
     * Short reading in words for amounts of one lakh or more, rounded down to a whole lakh
     * ("30 crore taka", "1 crore 20 lakh taka"). Returns null below one lakh.
     *
     * @param  string|int  $decimal  A decimal string such as "12000000.00" or "300000".
     * @param  string|null  $locale  'bn' writes Bangla words and digits; null uses the app locale.
     * @param  bool  $exact  Write every part down to the paisa ("1 lakh 50 thousand taka") instead of rounding down to a whole lakh; for approval screens.
     */
    public static function words(string|int $decimal, ?string $locale = null, bool $exact = false): ?string
    {
        $cents = Money::cents($decimal);
        if ($exact) {
            return self::exactWords($cents, $locale);
        }
        $totalLakh = intdiv(intdiv($cents, 100), self::Lakh);
        if ($totalLakh < 1) {
            return null;
        }

        $bangla = ($locale ?? app()->getLocale()) === 'bn';
        $crore = intdiv($totalLakh, 100);
        $lakh = $totalLakh % 100;

        $parts = [];
        if ($crore > 0) {
            $parts[] = self::group((string) $crore).($bangla ? ' কোটি' : ' crore');
        }
        if ($lakh > 0) {
            $parts[] = $lakh.($bangla ? ' লাখ' : ' lakh');
        }
        $text = implode(' ', $parts).($bangla ? ' টাকা' : ' taka');

        return LocalDigits::text($text, $locale);
    }

    /**
     * The amount followed by its reading in words when there is one,
     * e.g. "৳৩০,০০,০০,০০০ (৩০ কোটি টাকা)".
     *
     * @param  string|int  $decimal  A decimal string such as "12000000.00" or "300000".
     * @param  string|null  $locale  'bn' gives Bangla digits and words; null uses the app locale.
     */
    public static function withWords(string|int $decimal, ?string $locale = null): string
    {
        $words = self::words($decimal, $locale);
        $amount = self::amount($decimal, $locale);

        return $words === null ? $amount : $amount.' ('.$words.')';
    }

    /**
     * withWords() without the leading ৳, for message templates that already write "৳:amount"
     * (SMS, bell notices), e.g. "৩০,০০,০০,০০০ (৩০ কোটি টাকা)".
     *
     * @param  string|int  $decimal  A decimal string such as "12000000.00" or "300000".
     * @param  string|null  $locale  'bn' gives Bangla digits and words; null uses the app locale.
     */
    public static function afterSign(string|int $decimal, ?string $locale = null): string
    {
        $written = self::withWords($decimal, $locale);

        // Not ltrim(): it strips bytes, and the ৳ sign shares its first bytes with Bangla digits.
        return str_starts_with($written, '৳') ? substr($written, strlen('৳')) : $written;
    }

    /** Every part of the amount, e.g. "1 lakh 50 thousand taka"; null below one lakh. */
    private static function exactWords(int $cents, ?string $locale): ?string
    {
        $whole = intdiv($cents, 100);
        if ($whole < self::Lakh) {
            return null;
        }
        $bangla = ($locale ?? app()->getLocale()) === 'bn';
        $units = [
            [intdiv($whole, 10000000), $bangla ? 'কোটি' : 'crore'],
            [intdiv($whole, 100000) % 100, $bangla ? 'লাখ' : 'lakh'],
            [intdiv($whole, 1000) % 100, $bangla ? 'হাজার' : 'thousand'],
            [$whole % 1000, ''],
        ];
        $parts = [];
        foreach ($units as [$count, $name]) {
            if ($count > 0) {
                $parts[] = trim($count.' '.$name);
            }
        }
        $text = implode(' ', $parts).($bangla ? ' টাকা' : ' taka');
        if ($cents % 100 > 0) {
            $text .= ' '.($cents % 100).($bangla ? ' পয়সা' : ' paisa');
        }

        return LocalDigits::text($text, $locale);
    }

    /** Lakh/crore grouping of a whole number written in digits: "12345678" becomes "1,23,45,678". */
    private static function group(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        return (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
    }
}
