<?php

namespace App\Domain\Notifications;

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
     * The Bangla text of an SMS template.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function make(string $key, array $replace = []): string
    {
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
