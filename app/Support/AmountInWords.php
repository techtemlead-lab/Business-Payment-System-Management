<?php

namespace App\Support;

/** English amount-in-words for invoice footers (BDT). */
final class AmountInWords
{
    public static function taka(string|int $amount): string
    {
        $cents = is_int($amount) ? $amount : Money::cents((string) $amount);
        if ($cents < 0) {
            $cents = abs($cents);
        }
        $taka = intdiv($cents, 100);
        $poisha = $cents % 100;

        $words = self::integer($taka).' Taka';
        if ($poisha > 0) {
            $words .= ' and '.self::integer($poisha).' Poisha';
        }

        return $words.' only';
    }

    private static function integer(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $chunk = function (int $n) use ($ones, $tens): string {
            $parts = [];
            if ($n >= 100) {
                $parts[] = $ones[intdiv($n, 100)].' Hundred';
                $n %= 100;
            }
            if ($n >= 20) {
                $parts[] = $tens[intdiv($n, 10)].($n % 10 !== 0 ? ' '.$ones[$n % 10] : '');
            } elseif ($n > 0) {
                $parts[] = $ones[$n];
            }

            return trim(implode(' ', $parts));
        };

        $parts = [];
        $crore = intdiv($number, 10000000);
        $number %= 10000000;
        $lakh = intdiv($number, 100000);
        $number %= 100000;
        $thousand = intdiv($number, 1000);
        $number %= 1000;

        if ($crore > 0) {
            $parts[] = $chunk($crore).' Crore';
        }
        if ($lakh > 0) {
            $parts[] = $chunk($lakh).' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = $chunk($thousand).' Thousand';
        }
        if ($number > 0) {
            $parts[] = $chunk($number);
        }

        return trim(implode(' ', $parts));
    }
}
