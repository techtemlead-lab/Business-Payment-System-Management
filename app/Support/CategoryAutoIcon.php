<?php

namespace App\Support;

/**
 * Deterministic category glyph + accent color when no admin image is configured.
 */
final class CategoryAutoIcon
{
    /**
     * @return array{key: string, label: string, hue: int}
     */
    public static function forCategory(string $name): array
    {
        $normalized = mb_strtolower(trim($name));
        $key = self::glyphKey($normalized);
        $hue = self::hueFromName($normalized !== '' ? $normalized : 'category');

        return [
            'key' => $key,
            'label' => $name !== '' ? mb_substr($name, 0, 1) : 'M',
            'hue' => $hue,
        ];
    }

    private static function glyphKey(string $normalized): string
    {
        return match (true) {
            str_contains($normalized, 'ayurved') || str_contains($normalized, 'herb') || str_contains($normalized, 'unani') || str_contains($normalized, 'botanic') => 'leaf',
            str_contains($normalized, 'syrup') || str_contains($normalized, 'juice') || str_contains($normalized, 'drink') => 'bottle',
            str_contains($normalized, 'oil') || str_contains($normalized, 'ghee') => 'droplet',
            str_contains($normalized, 'superfood') || str_contains($normalized, 'food') || str_contains($normalized, 'honey') || str_contains($normalized, 'supplement') => 'bowl',
            str_contains($normalized, 'cosmetic') || str_contains($normalized, 'skin') || str_contains($normalized, 'beauty') || str_contains($normalized, 'care') => 'sparkle',
            str_contains($normalized, 'medicine') || str_contains($normalized, 'pharma') || str_contains($normalized, 'capsule') => 'capsule',
            str_contains($normalized, 'agri') || str_contains($normalized, 'seed') || str_contains($normalized, 'plant') => 'seedling',
            str_contains($normalized, 'water') => 'water',
            str_contains($normalized, 'furniture') || str_contains($normalized, 'home') => 'home',
            str_contains($normalized, 'service') => 'handshake',
            default => 'tag',
        };
    }

    private static function hueFromName(string $normalized): int
    {
        $hash = crc32($normalized);

        return (int) abs($hash % 360);
    }
}
