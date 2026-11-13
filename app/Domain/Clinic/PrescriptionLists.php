<?php

namespace App\Domain\Clinic;

/**
 * The short lists a prescriber writes one row at a time: tests to have done, foods to eat and
 * foods to avoid. The same limits and tidying apply on a prescription and on a food template.
 */
final class PrescriptionLists
{
    /** Most rows one list may hold. */
    public const MaxItems = 30;

    /** Most letters in one row. */
    public const MaxItemLength = 160;

    /**
     * Validation rules for one list field.
     *
     * @return array<string, string>
     */
    public static function rules(string $field): array
    {
        return [
            $field => 'nullable|array|max:'.self::MaxItems,
            $field.'.*' => 'nullable|string|max:'.self::MaxItemLength,
        ];
    }

    /**
     * Trims every row, drops blank rows and keeps a repeat once whatever its letter case.
     * An empty list is stored as null.
     *
     * @return list<string>|null
     */
    public static function clean(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $rows = [];
        foreach ($raw as $row) {
            $text = is_scalar($row) ? trim((string) $row) : '';
            if ($text !== '') {
                $rows[mb_strtolower($text)] ??= $text;
            }
        }

        return $rows === [] ? null : array_values($rows);
    }
}
