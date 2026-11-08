<?php

namespace App\Domain\Orders;

use App\Models\DatedScheme;
use App\Models\Product;

/**
 * Turns running free-goods schemes into explicit free order lines.
 *
 * A scheme gives its free goods once per order when the units bought across all the products it
 * covers reach its minimum. The dates are inclusive and follow the business timezone. Schemes
 * with no free product or quantity set stay information only. Free lines are at price zero and
 * flagged is_free, so CommissionPosting leaves them out of every money figure.
 */
final class SchemeFreeLines
{
    /**
     * @param  list<array{product_id: int, quantity: int}>  $chargedLines  the lines the dealer pays for
     * @return list<array<string, mixed>> free lines, in the shape of the order's other lines
     */
    public function for(array $chargedLines): array
    {
        $today = now((string) config('madina.business_timezone'))->toDateString();
        $schemes = DatedScheme::query()
            ->where('benefit_type', 'free_goods')
            ->whereNotNull('free_product_id')
            ->where('free_quantity', '>', 0)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('id')
            ->get();
        $freeLines = [];
        foreach ($schemes as $scheme) {
            $covered = array_map('intval', $scheme->product_ids);
            $bought = array_sum(array_map(
                fn (array $line): int => in_array((int) $line['product_id'], $covered, true) ? (int) $line['quantity'] : 0,
                $chargedLines,
            ));
            if ($bought < (int) $scheme->min_quantity) {
                continue;
            }
            $product = Product::query()->whereKey($scheme->free_product_id)->where('active', true)->first();
            if (! $product || $product->is_stock_paused) {
                continue;
            }
            $freeLines[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category_snapshot' => $product->category,
                'batch_number' => $product->batch_number,
                'expires_on' => $product->getAttribute('expires_on')?->toDateString(),
                'quantity' => (int) $scheme->free_quantity,
                'mrp' => $product->mrp,
                'unit_price' => '0.00',
                'total' => '0.00',
                'is_free' => true,
                'dated_scheme_id' => $scheme->id,
                'scheme_title' => $scheme->title,
            ];
        }

        return $freeLines;
    }
}
