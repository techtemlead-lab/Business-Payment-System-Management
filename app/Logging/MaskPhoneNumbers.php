<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

/**
 * Log channel tap: hides Bangladeshi mobile numbers in every log line, keeping only the
 * last 3 digits (01712345678 → ********678). Applies to the message, the context and the
 * extra data, at any depth, so a partner's phone number never sits in a log file in full.
 */
class MaskPhoneNumbers
{
    /** A Bangladeshi mobile number, with or without the 88 / +88 country code. */
    private const Pattern = '/(?<!\d)(?:\+?88)?01[3-9]\d{8}(?!\d)/';

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                message: self::mask($record->message),
                context: self::maskArray($record->context),
                extra: self::maskArray($record->extra),
            ));
        }
    }

    public static function mask(string $text): string
    {
        return (string) preg_replace_callback(self::Pattern, function (array $match): string {
            $digits = preg_replace('/\D/', '', $match[0]) ?? '';

            return str_repeat('*', max(0, strlen($digits) - 3)).substr($digits, -3);
        }, $text);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function maskArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::mask($value);
            } elseif (is_array($value)) {
                $values[$key] = self::maskArray($value);
            }
        }

        return $values;
    }
}
