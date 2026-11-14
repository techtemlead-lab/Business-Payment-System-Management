<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\ProductSizeGroups;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The rows of the printed stock list. Each pack size is its own product, so a row is one
 * product (pack size) and one expiry date: batches that share an expiry date are added up.
 * Stock whose lot was never recorded is listed under the expiry date saved on the product, and
 * stock with no expiry date at all is listed with a blank date. Nothing here changes stock.
 *
 * The quantity is what a branch physically holds, so batches on hold or past their expiry
 * date are listed too, with their date visible. Units with a courier are not in it.
 */
final class StockPrintList
{
    /**
     * @return array{
     *     rows: list<array{serial: int, name: string, pack_size: ?string, expires_on: ?string, quantity: int, mrp: ?string, dealer_discount: ?string, sub_dealer_discount: ?string, total: string}>,
     *     total_quantity: int,
     *     total_value: string
     * }
     */
    public function build(BranchScope $scope): array
    {
        $units = $this->unitsByProductAndExpiry($scope);
        if ($units === []) {
            return ['rows' => [], 'total_quantity' => 0, 'total_value' => Money::decimal(0)];
        }

        $products = Product::query()
            ->whereIn('id', array_unique(array_column($units, 'product_id')))
            ->get(['id', 'name', 'strength', 'pack_size_en', 'pack_size_bn', 'mrp', 'dealer_price', 'sub_dealer_price'])
            ->keyBy('id');

        $entries = [];
        foreach ($units as $unit) {
            $product = $products->get($unit['product_id']);
            if ($product === null) {
                continue;
            }
            $entries[] = ['product' => $product, 'expires_on' => $unit['expires_on'], 'quantity' => $unit['quantity']];
        }
        usort($entries, static fn (array $first, array $second): int => strnatcasecmp($first['product']->registerName(), $second['product']->registerName())
            ?: strnatcasecmp((string) ProductSizeGroups::label($first['product']), (string) ProductSizeGroups::label($second['product']))
            ?: ((int) $first['product']->id <=> (int) $second['product']->id)
            ?: (($first['expires_on'] ?? '9999-12-31') <=> ($second['expires_on'] ?? '9999-12-31')));

        $rows = [];
        $totalQuantity = 0;
        $totalCents = 0;
        foreach ($entries as $index => $entry) {
            /** @var Product $product */
            $product = $entry['product'];
            $mrpCents = $this->cents($product->mrp);
            $lineCents = $mrpCents * $entry['quantity'];
            $totalQuantity += $entry['quantity'];
            $totalCents += $lineCents;
            $rows[] = [
                'serial' => $index + 1,
                'name' => $product->registerName(),
                'pack_size' => ProductSizeGroups::label($product),
                'expires_on' => $entry['expires_on'],
                'quantity' => $entry['quantity'],
                'mrp' => $mrpCents > 0 ? Money::decimal($mrpCents) : null,
                'dealer_discount' => $this->discount($product, $product->dealer_price),
                'sub_dealer_discount' => $this->discount($product, $product->sub_dealer_price),
                'total' => Money::decimal($lineCents),
            ];
        }

        return ['rows' => $rows, 'total_quantity' => $totalQuantity, 'total_value' => Money::decimal($totalCents)];
    }

    /**
     * @return list<array{product_id: int, expires_on: ?string, quantity: int}>
     */
    private function unitsByProductAndExpiry(BranchScope $scope): array
    {
        if (! $scope->allBranches && $scope->branchIds === []) {
            return [];
        }

        $lots = DB::table('stock_lots')
            ->where('quantity', '>', 0)
            ->groupBy('product_id', 'expires_on')
            ->selectRaw('product_id, expires_on, CAST(SUM(quantity) AS integer) AS units');
        $scope->restrict($lots, 'branch_id');

        /** @var array<string, array{product_id: int, expires_on: ?string, quantity: int}> $merged */
        $merged = [];
        $add = static function (int $productId, ?string $expiresOn, int $quantity) use (&$merged): void {
            $key = $productId.'|'.($expiresOn ?? '');
            $merged[$key] = [
                'product_id' => $productId,
                'expires_on' => $expiresOn,
                'quantity' => ($merged[$key]['quantity'] ?? 0) + $quantity,
            ];
        };
        foreach ($lots->get() as $lot) {
            $add((int) $lot->product_id, $lot->expires_on !== null ? substr((string) $lot->expires_on, 0, 10) : null, (int) $lot->units);
        }

        $branchId = $scope->allBranches ? null : $scope->branchIds[0];
        $unlotted = DB::query()->fromSub(StockStages::rows($branchId), 'stage')
            ->join('products', 'products.id', '=', 'stage.product_id')
            ->where('stage.unlotted', '>', 0)
            ->groupBy('stage.product_id', 'products.expires_on')
            ->selectRaw('stage.product_id, products.expires_on, CAST(SUM(stage.unlotted) AS integer) AS units');
        foreach ($unlotted->get() as $row) {
            $add((int) $row->product_id, $row->expires_on !== null ? substr((string) $row->expires_on, 0, 10) : null, (int) $row->units);
        }

        return array_values($merged);
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
