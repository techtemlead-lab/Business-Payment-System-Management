<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function cents(string|int $value): int
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,11}(?:\.\d{1,2})?$/D', $value)) {
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

    /** @deprecated Use SalaryCalculator; the monthly purchase tiers were replaced by a flat 3%. */
    public static function salaryTier(int $purchases): int
    {
        if ($purchases < 0) {
            throw new InvalidArgumentException('Negative purchase amount.');
        }

        return 3;
    }
}
