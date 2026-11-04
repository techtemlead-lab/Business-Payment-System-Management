<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function cents(string|int $value): int
    {
        $value = (string) $value;
        // Single columns are decimal(14,2). Company totals add many of those rows, so allow
        // 16 digits. 16 digits times 100 still fits in a signed 64-bit integer.
        if (! preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }
        [$whole,$fraction] = array_pad(explode('.', $value), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Negative monetary amount.');
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format a decimal amount the way Bangladeshi businesses write it:
     * lakh/crore grouping with two decimals, e.g. "12,34,567.50".
     */
    public static function grouped(string|int $value): string
    {
        $decimal = self::decimal(self::cents((string) $value));
        [$whole, $fraction] = explode('.', $decimal);
        if (strlen($whole) > 3) {
            $lastThree = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest.','.$lastThree;
        }

        return $whole.'.'.$fraction;
    }

    /** @deprecated Use SalaryCalculator; the monthly purchase tiers were replaced by a flat 3%. */
    public static function salaryTier(int $purchases): int
    {
        if ($purchases < 0) {
            throw new InvalidArgumentException('Negative purchase amount.');
        }

        return 3;
    }
}
