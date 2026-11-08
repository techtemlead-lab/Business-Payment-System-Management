<?php

namespace App\Domain\Integrations;

use App\Domain\Orders\ShipmentTracking;
use Illuminate\Support\Str;

/**
 * The owner's own reading of a courier's tracking answer, for couriers whose wording is not
 * published clearly. Each line says "courier's words = our step", such as "Received at point = in_transit".
 * A line the courier never sends does nothing; a courier word with no line falls back to the
 * usual reading of the status.
 */
final class CourierStatusMap
{
    /**
     * @return array<string, string> The courier's words in lower case, and the step they mean.
     */
    public static function parse(string $text): array
    {
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $pair = self::pair($line);
            if ($pair !== null) {
                $map[self::key($pair[0])] = $pair[1];
            }
        }

        return $map;
    }

    /**
     * @return list<int> Line numbers (from 1) that are written but cannot be read.
     */
    public static function invalidLines(string $text): array
    {
        $invalid = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            if (trim($line) !== '' && self::pair($line) === null) {
                $invalid[] = $index + 1;
            }
        }

        return $invalid;
    }

    /**
     * @param  array<string, string>  $map
     */
    public static function stepFor(array $map, ?string $raw): ?string
    {
        return $raw === null ? null : ($map[self::key($raw)] ?? null);
    }

    /**
     * Reads the courier's status from the place the owner named, such as "success.trackingStatus.0".
     * When that place holds a list of named steps with dates, the latest filled step on the map wins.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $map
     */
    public static function statusFrom(array $payload, string $path, array $map): ?string
    {
        $found = data_get($payload, $path);
        if (is_string($found) && trim($found) !== '') {
            return trim($found);
        }
        if (! is_array($found)) {
            return null;
        }
        $latest = null;
        foreach ($found as $step => $value) {
            if (is_string($step) && filled($value) && isset($map[self::key($step)])) {
                $latest = $step;
            }
        }

        return $latest;
    }

    /** @return array{0: string, 1: string}|null */
    private static function pair(string $line): ?array
    {
        if (! str_contains($line, '=')) {
            return null;
        }
        [$words, $step] = array_map('trim', explode('=', $line, 2));

        return $words !== '' && in_array($step, ShipmentTracking::Statuses, true) ? [$words, $step] : null;
    }

    private static function key(string $words): string
    {
        return Str::lower((string) preg_replace('/\s+/', ' ', trim($words)));
    }
}
