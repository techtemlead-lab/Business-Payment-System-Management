<?php

namespace App\Support;

final class SpreadsheetCell
{
    /**
     * Neutralize formula injection for spreadsheet consumers that treat leading
     * =, +, -, or @ as executable formulas when opening CSV/XLSX exports.
     */
    public static function text(mixed $value): string
    {
        $text = (string) $value;
        if ($text === '') {
            return '';
        }
        $first = $text[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }
}
