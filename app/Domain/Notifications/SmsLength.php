<?php

namespace App\Domain\Notifications;

/**
 * How many SMS parts a text takes. Bangla is Unicode: 70 characters fit one part and each part of a
 * longer text carries 67. A text that is only plain English letters and digits is 160 and 153.
 */
final class SmsLength
{
    /**
     * @return array{characters: int, parts: int, per_part: int, unicode: bool}
     */
    public static function of(string $text): array
    {
        $unicode = preg_match('/^[\x20-\x7E\n\r]*$/D', $text) !== 1;
        // The count is in UTF-16 units, the way a phone counts: a letter outside the basic plane takes two.
        $characters = $text === '' ? 0 : intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
        $single = $unicode ? 70 : 160;
        $perPart = $unicode ? 67 : 153;
        $parts = $characters === 0 ? 0 : ($characters <= $single ? 1 : (int) ceil($characters / $perPart));

        return ['characters' => $characters, 'parts' => $parts, 'per_part' => $parts > 1 ? $perPart : $single, 'unicode' => $unicode];
    }
}
