<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The owner's numbered list of products on the public home page.
 *
 * A product is on the list when "show on home page" is yes. Its number is its place, 1 first;
 * the page fills left to right and then the next row, so the number is all that sets the order.
 */
final class HomePageOrder
{
    /** The furthest place a person may type. Nobody lists this many products. */
    public const LastPlace = 2000;

    /**
     * The list for the office screens, first place first.
     *
     * @return list<array{id: int, name: string, mrp: string, image: string}>
     */
    public static function forEditor(): array
    {
        return Product::query()
            ->featuredInOrder()
            ->get()
            ->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'mrp' => (string) $product->mrp,
                'image' => (string) ($product->media()['image_url'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Where this product stands on the list and how many products are on it.
     *
     * @return array{place: int|null, count: int}
     */
    public static function placeOf(?Product $product): array
    {
        $listedIds = Product::query()->featuredInOrder()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $index = $product === null ? false : array_search((int) $product->id, $listedIds, true);

        return ['place' => $index === false ? null : $index + 1, 'count' => count($listedIds)];
    }

    /**
     * Puts a product already marked for the home page at the place typed and renumbers the rest,
     * 1 first with no gaps. A place past the end means last.
     *
     * Call inside a transaction. Other products only change their number, so an editor left open
     * on one of them still saves.
     */
    public static function moveTo(Product $product, int $place): void
    {
        // Products on sale come first, because those are the ones the owner sees numbered.
        $otherIds = Product::query()
            ->where('featured', true)
            ->whereKeyNot($product->id)
            ->orderByDesc('active')
            ->orderByRaw('featured_position ASC NULLS LAST')
            ->orderBy('name')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('featured_position', 'id');

        $orderedIds = $otherIds->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $index = max(0, min($place, count($orderedIds) + 1) - 1);
        array_splice($orderedIds, $index, 0, [(int) $product->id]);

        foreach ($orderedIds as $position => $productId) {
            $wanted = $position + 1;
            $current = $productId === (int) $product->id ? $product->featured_position : $otherIds[$productId];
            if ($current !== null && (int) $current === $wanted) {
                continue;
            }
            DB::table('products')->where('id', $productId)->update(['featured_position' => $wanted]);
        }

        $product->featured_position = $index + 1;
        $product->syncOriginalAttribute('featured_position');
    }
}
