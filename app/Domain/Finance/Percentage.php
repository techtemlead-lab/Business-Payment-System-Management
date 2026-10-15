<?php

namespace App\Domain\Finance;

use InvalidArgumentException;

final readonly class Percentage
{
    private function __construct(public int $basisPoints) {}

    public static function from(string|int $value): self
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('A percentage must have at most two decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $points = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
        if ($points > 10000) {
            throw new InvalidArgumentException('A percentage cannot exceed 100%.');
        }

        return new self($points);
    }

    public function decimal(): string
    {
        return intdiv($this->basisPoints, 100).'.'.str_pad((string) ($this->basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    public function of(int $minorUnits, string $rounding = 'reject'): int
    {
        if ($minorUnits < 0 || $minorUnits > 9999999999999) {
            throw new InvalidArgumentException('The amount is outside the supported monetary range.');
        }
        if (! in_array($rounding, ['reject', 'half_up'], true)) {
            throw new InvalidArgumentException('Choose reject or half_up rounding.');
        }
        $numerator = $minorUnits * $this->basisPoints;
        if ($rounding === 'reject' && $numerator % 10000 !== 0) {
            throw new InvalidArgumentException('This calculation needs an approved rounding rule.');
        }

        return intdiv($numerator + ($rounding === 'half_up' ? 5000 : 0), 10000);
    }
}
