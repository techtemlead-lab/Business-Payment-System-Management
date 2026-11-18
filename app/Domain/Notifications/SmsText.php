<?php

namespace App\Domain\Notifications;

use App\Support\TakaText;

/**
 * The language rule for every SMS the app sends. Greenweb suspends accounts that
 * send non-OTP SMS in English or Banglish and asks for OTP in Bangla too, so the
 * text is always built in Bangla, whatever language the recipient or the person
 * doing the action has chosen. Screen text and portal notices are not affected.
 */
final class SmsText
{
    public const Locale = 'bn';

    /**
     * The Bangla text of an SMS template. A plain `amount` ("12000000.00") is written the one shared
     * way (MA-18): Bangla digits with lakh/crore grouping, no ".00", and words from one lakh up, so
     * "৳:amount" reads "৳১,২০,০০,০০০ (১ কোটি ২০ লাখ টাকা)". Templates put the ৳ sign themselves.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function make(string $key, array $replace = []): string
    {
        if (isset($replace['amount']) && preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', (string) $replace['amount']) === 1) {
            $replace['amount'] = TakaText::afterSign((string) $replace['amount'], self::Locale);
        }

        return (string) __($key, $replace, self::Locale);
    }

    /** The Bangla text of a word placed inside an SMS, or the fallback when it has no translation. */
    public static function label(string $key, string $fallback): string
    {
        $label = self::make($key);

        return $label === $key ? $fallback : $label;
    }

    /** True when the text has at least one Bengali letter. Bengali digits or the ৳ sign alone do not count. */
    public static function hasBanglaLetters(string $text): bool
    {
        return preg_match('/(?=\p{Bengali})\p{L}/u', $text) === 1;
    }
}
