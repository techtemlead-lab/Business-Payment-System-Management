<?php

namespace App\Support;

/**
 * Amount in words for invoice footers (BDT), in English or Bangla, using the
 * lakh / crore system Bangladeshi businesses write on cheques and invoices.
 */
final class AmountInWords
{
    /** Bangla words for 0–99; Bangla numbers below one hundred are irregular. */
    private const BanglaUnderHundred = [
        'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
        'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
        'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'উনত্রিশ',
        'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'উনচল্লিশ',
        'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'উনপঞ্চাশ',
        'পঞ্চাশ', 'একান্ন', 'বায়ান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'উনষাট',
        'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'উনসত্তর',
        'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'উনআশি',
        'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'উননব্বই',
        'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
    ];

    /**
     * @param  string|null  $locale  "bn" for Bangla; defaults to the current app locale.
     */
    public static function taka(string|int $amount, ?string $locale = null): string
    {
        $cents = is_int($amount) ? $amount : Money::cents((string) $amount);
        $cents = abs($cents);
        $taka = intdiv($cents, 100);
        $poisha = $cents % 100;
        $locale ??= app()->getLocale();

        if (str_starts_with((string) $locale, 'bn')) {
            $words = self::bangla($taka).' টাকা';
            if ($poisha > 0) {
                $words .= ' '.self::bangla($poisha).' পয়সা';
            }

            return $words.' মাত্র';
        }

        $words = self::english($taka).' Taka';
        if ($poisha > 0) {
            $words .= ' and '.self::english($poisha).' Poisha';
        }

        return $words.' only';
    }

    private static function bangla(int $number): string
    {
        if ($number < 100) {
            return self::BanglaUnderHundred[$number];
        }

        $parts = [];
        $crore = intdiv($number, 10000000);
        $number %= 10000000;
        $lakh = intdiv($number, 100000);
        $number %= 100000;
        $thousand = intdiv($number, 1000);
        $number %= 1000;
        $hundred = intdiv($number, 100);
        $rest = $number % 100;

        if ($crore > 0) {
            $parts[] = self::bangla($crore).' কোটি';
        }
        if ($lakh > 0) {
            $parts[] = self::BanglaUnderHundred[$lakh].' লাখ';
        }
        if ($thousand > 0) {
            $parts[] = self::BanglaUnderHundred[$thousand].' হাজার';
        }
        if ($hundred > 0) {
            $parts[] = self::BanglaUnderHundred[$hundred].'শত';
        }
        if ($rest > 0) {
            $parts[] = self::BanglaUnderHundred[$rest];
        }

        return implode(' ', $parts);
    }

    private static function english(int $number): string
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
            // Very large amounts: "One Hundred Twenty Crore" stays readable.
            $parts[] = self::english($crore).' Crore';
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
