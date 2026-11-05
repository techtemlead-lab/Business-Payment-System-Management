<?php

namespace App\Support;

use Throwable;

/**
 * An exception message that is safe to write to the log. HTTP client errors
 * repeat the full request URL, and gateway URLs carry API keys, phone numbers,
 * login codes and birth registration numbers in their query strings.
 */
final class RedactedError
{
    public static function message(Throwable $exception): string
    {
        return self::text($exception::class.': '.$exception->getMessage());
    }

    public static function text(string $text): string
    {
        // Drop every query string from URLs.
        $text = (string) preg_replace('~(https?://[^\s?#"\'<>]+)\?[^\s"\'<>]*~i', '$1?[redacted]', $text);
        // Drop credentials written into a URL as user:password@host.
        $text = (string) preg_replace('~(https?://)[^/\s:@]+:[^/\s@]+@~i', '$1[redacted]@', $text);
        // Mask Bangladeshi mobile numbers outside URLs, keeping the last two digits.
        $text = (string) preg_replace('~(?<!\d)(?:\+?88)?01[3-9]\d{6}(\d{2})(?!\d)~', '01*******$1', $text);

        return mb_strimwidth($text, 0, 500, '…');
    }
}
