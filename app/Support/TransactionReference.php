<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A transaction reference typed by a person (TrxID, bank slip number): at least 6 characters,
 * and Bangla digits count as ordinary digits, so "১২৩৪৫৬" and "123456" are the same reference.
 */
final class TransactionReference
{
    public const MinLength = 6;

    public const MinRule = 'min:6';

    /** Trims the typed references and stores their digits as 0-9, before validation sees them. */
    public static function tidy(Request $request, string ...$fields): void
    {
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $request->merge([$field => trim(LocalDigits::latin($value))]);
            }
        }
    }

    /** @return array<string, string> The plain refusal for each named reference field. */
    public static function messages(string ...$fields): array
    {
        $messages = [];
        foreach ($fields as $field) {
            $messages[$field.'.min'] = __('portal.err_reference_min', ['min' => self::MinLength]);
        }

        return $messages;
    }
}
