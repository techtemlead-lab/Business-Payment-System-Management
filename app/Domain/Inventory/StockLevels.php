<?php

namespace App\Domain\Inventory;

use App\Models\Product;

/**
 * The one count of active products that are low or out of stock. The staff dashboard and the
 * tiles on the Stock page both read it, so they cannot disagree. A product is out when nothing
 * is available now ({@see SellableStock}) and low when something is, but no more than its mark.
 * On All branches that is the company's sellable stock against the product's mark; with a
 * branch picked, that branch's usable stock against the branch's own mark.
 */
final class StockLevels
{
    /**
     * @return array{low: int, out: int}
     */
    public static function counts(?BranchScope $scope): array
    {
        $products = Product::query()->where('products.active', true);
        if ($scope === null || $scope->allBranches) {
            $available = SellableStock::sql();
            $mark = 'products.low_stock_at';
            $bindings = SellableStock::bindings();
        } else {
            $products->join('branch_product_stocks', 'branch_product_stocks.product_id', '=', 'products.id');
            $scope->restrict($products, 'branch_product_stocks.branch_id');
            $available = SellableStock::branchSql();
            $mark = 'branch_product_stocks.low_stock_at';
            $bindings = SellableStock::branchBindings();
        }
        $row = $products->toBase()->selectRaw(
            "COUNT(*) FILTER (WHERE {$available} <= 0) AS out_count, COUNT(*) FILTER (WHERE {$available} > 0 AND {$available} <= {$mark}) AS low_count",
            [...$bindings, ...$bindings, ...$bindings],
        )->first();

        return ['low' => (int) ($row->low_count ?? 0), 'out' => (int) ($row->out_count ?? 0)];
    }
}
