<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\ProductSizeGroups;
use App\Models\Product;
use App\Support\Money;
use InvalidArgumentException;

/**
 * The rows of the printed product list: one row for every product that is live (on sale, not
 * archived, deleted or switched off), grouped by category (a product with no category comes
 * last), then by name. It shows no quantities and no totals: it is the product list with prices
 * and discounts, the same for every branch. Nothing here changes stock.
 */
final class ProductListRows
{
    /**
     * @return array{
     *     rows: list<array{product_id: int, serial: int, name: string, pack_size: ?string, category: ?string, group: ?string, mrp: ?string, dealer_discount: ?string, sub_dealer_discount: ?string, image: string, usage: ?string, efficacy: ?string}>
     * }
     */
    public function build(): array
    {
        $entries = [];
        foreach (Product::query()->where('active', true)->get(['id', 'name', 'strength', 'category', 'pack_size_en', 'pack_size_bn', 'mrp', 'dealer_price', 'sub_dealer_price', 'description', 'dose_bn', 'dose_en', 'group_bn', 'group_en', 'efficacy_bn', 'efficacy_en', 'image_path']) as $product) {
            [$name, $packSize] = StockPrintName::split($product->registerName(), ProductSizeGroups::label($product));
            $category = trim((string) $product->category);
            $entries[] = ['product' => $product, 'name' => $name, 'pack_size' => $packSize, 'category' => $category !== '' ? $category : null];
        }
        usort($entries, static fn (array $first, array $second): int => ($first['category'] === null) <=> ($second['category'] === null)
            ?: strnatcasecmp((string) $first['category'], (string) $second['category'])
            ?: strnatcasecmp($first['name'], $second['name'])
            ?: strnatcasecmp((string) $first['pack_size'], (string) $second['pack_size'])
            ?: ((int) $first['product']->id <=> (int) $second['product']->id));

        $rows = [];
        foreach ($entries as $index => $entry) {
            /** @var Product $product */
            $product = $entry['product'];
            $mrpCents = $this->cents($product->mrp);
            $rows[] = [
                'product_id' => (int) $product->id,
                'serial' => $index + 1,
                'name' => $entry['name'],
                'pack_size' => $entry['pack_size'],
                'category' => $entry['category'],
                'group' => $this->localizedFact($product, 'group'),
                'mrp' => $mrpCents > 0 ? Money::decimal($mrpCents) : null,
                'dealer_discount' => $this->discount($product, $product->dealer_price),
                'sub_dealer_discount' => $this->discount($product, $product->sub_dealer_price),
                'image' => PdfPhotos::sourceFor((int) $product->id, $product->image_path, $product->category),
                'usage' => $this->localizedFact($product, 'dose'),
                // The product description stands in until the owner fills in the efficacy.
                'efficacy' => $this->localizedFact($product, 'efficacy') ?? $this->plainText($product->description),
            ];
        }

        return ['rows' => $rows];
    }

    /** Bangla first on a Bangla page, then English, for a fact stored in both languages. */
    private function localizedFact(Product $product, string $field): ?string
    {
        $languages = app()->getLocale() === 'bn' ? ['bn', 'en'] : ['en', 'bn'];
        foreach ($languages as $language) {
            $text = $this->plainText($product->getAttribute($field.'_'.$language));
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function plainText(mixed $value): ?string
    {
        $text = trim(strip_tags((string) $value));

        return $text === '' ? null : $text;
    }

    private function cents(mixed $amount): int
    {
        try {
            return $amount === null || $amount === '' ? 0 : Money::cents((string) $amount);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * How far below the MRP the channel price is, in percent with trailing zeros dropped
     * (20 or 12.5), or null while the price is not set or is not below the MRP.
     */
    private function discount(Product $product, mixed $channelPrice): ?string
    {
        if ($this->cents($channelPrice) <= 0) {
            return null;
        }
        $percent = $product->discountPercentFromRetail($channelPrice);

        return $percent === null ? null : rtrim(rtrim($percent, '0'), '.');
    }
}
