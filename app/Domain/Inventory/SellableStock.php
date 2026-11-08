<?php

namespace App\Domain\Inventory;

use App\Models\StockLot;

/**
 * The one definition of how many units of a product can really be sold today. The public
 * catalogue, the dealer ordering screen and the "in stock" labels all use it, and it gives the
 * same answer as {@see StockLots::take()}:
 *
 * - only lots at active stock hubs count, because orders are dispatched only from those;
 * - lots on hold and lots past their expiry date never count;
 * - when a branch total is above its lots, the extra counts as one more lot with the product's own
 *   expiry; when it is below, the difference comes off the lots that would go out last
 *   ({@see StockLots::reconcile()});
 * - a product that only has the old company-wide total counts whole, unless the product has
 *   expired, because the first dispatch moves that total to the hub
 *   ({@see BranchOperations::ensureBranchStock()}).
 *
 * The products.stock column stays the physical count for the stock screens and reports.
 */
final class SellableStock
{
    /**
     * A SQL expression for one product's sellable units. It reads the surrounding products row.
     */
    public static function sql(): string
    {
        return <<<'SQL'
            (CASE
                WHEN EXISTS (SELECT 1 FROM branch_product_stocks any_stock WHERE any_stock.product_id = products.id AND any_stock.quantity > 0) THEN
                    COALESCE((
                        SELECT SUM(GREATEST(0, lots.usable + CASE
                            WHEN branch_stock.quantity < lots.total THEN branch_stock.quantity - lots.total
                            WHEN products.expires_on IS NULL OR products.expires_on >= CAST(? AS date) THEN branch_stock.quantity - lots.total
                            ELSE 0
                        END))
                        FROM branch_product_stocks branch_stock
                        JOIN company_branches hub ON hub.id = branch_stock.branch_id AND hub.is_active AND hub.is_stock_hub
                        CROSS JOIN LATERAL (
                            SELECT COALESCE(SUM(lot.quantity), 0) AS total,
                                COALESCE(SUM(lot.quantity) FILTER (WHERE lot.held_at IS NULL AND (lot.expires_on IS NULL OR lot.expires_on >= CAST(? AS date))), 0) AS usable
                            FROM stock_lots lot
                            WHERE lot.branch_id = branch_stock.branch_id AND lot.product_id = branch_stock.product_id AND lot.quantity > 0
                        ) lots
                        WHERE branch_stock.product_id = products.id
                    ), 0)
                WHEN products.expires_on IS NOT NULL AND products.expires_on < CAST(? AS date) THEN 0
                WHEN NOT EXISTS (SELECT 1 FROM company_branches any_hub WHERE any_hub.is_active AND any_hub.is_stock_hub) THEN 0
                ELSE GREATEST(products.stock, 0)
            END)
            SQL;
    }

    /**
     * Today's date at the company, once for each place the SQL asks for it.
     *
     * @return list<string>
     */
    public static function bindings(): array
    {
        $today = StockLot::today();

        return [$today, $today, $today];
    }
}
