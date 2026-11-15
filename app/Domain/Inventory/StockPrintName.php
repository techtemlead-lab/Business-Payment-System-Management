<?php

namespace App\Domain\Inventory;

/**
 * Splits a product name such as "Syrup Ulica (সিরাপ ইউলিকা) 100 ML" into the name and its pack
 * size, so the printed stock list shows the size in its own column and not twice.
 */
final class StockPrintName
{
    /**
     * A size written at the very end of the name: 10g, 30Tab, 250 ML, 3x10's, (30gm), 30Cap-30 Cap,
     * or one set apart with a dash. The character before the number must not be a letter or digit,
     * so "B12 Tab" is a name and not a size.
     */
    private const TrailingSize = '/[\s\-–]*\(?\s*(?<![\p{L}\p{N}])(?<size>\d+(?:[.,]\d+)?\s*(?:x\s*\d+(?:[.,]\d+)?\s*(?:\'?s)?|(?:ml|mls|ltr|l|gm|gms|gram|grams|g|kg|mg|tab|tabs|tablet|tablets|cap|caps|capsule|capsules|pcs|pc|pack|sachet)\b(?:\s*[-–]\s*\d+\s*(?:ml|gm|g|tab|tabs|cap|caps)\b)?))\s*\)?\s*$/iu';

    /**
     * @param  ?string  $savedSize  The pack size written on the product, if any; it wins over the name.
     * @return array{0: string, 1: ?string} The name without its size, and the pack size.
     */
    public static function split(string $name, ?string $savedSize): array
    {
        $name = trim($name);
        $saved = trim((string) $savedSize);
        $found = preg_match(self::TrailingSize, $name, $match, PREG_OFFSET_CAPTURE) === 1;
        if (! $found) {
            return [$name, $saved !== '' ? $saved : null];
        }

        $withoutSize = rtrim(substr($name, 0, $match[0][1]), " \t-–");
        $foundSize = trim($match['size'][0]);
        // A name that is only a size keeps its words.
        if ($withoutSize === '' || preg_match('/\p{L}/u', $withoutSize) !== 1) {
            return [$name, $saved !== '' ? $saved : null];
        }
        if ($saved === '') {
            return [$withoutSize, $foundSize];
        }

        // With a saved size the name loses its trailing size only when it says the same thing.
        return [self::same($saved, $foundSize) ? $withoutSize : $name, $saved];
    }

    private static function same(string $first, string $second): bool
    {
        $plain = static fn (string $size): string => (string) preg_replace('/[\s()\-–]+/u', '', mb_strtolower($size));

        return $plain($first) === $plain($second);
    }
}
