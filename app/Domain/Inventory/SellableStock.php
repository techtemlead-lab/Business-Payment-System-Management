<?php

namespace App\Domain\Inventory;

use App\Models\StockLot;

/**
 * The one definition of how many units of a product can really be sold today. The public
 * catalogue, the dealer ordering screen, the "in stock" labels, the product available and
 * unavailable notices and the staff low-stock list all use it, and it gives the same answer as
 * {@see StockLots::take()}:
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
        $usable = self::usableUnits('branch_stock');
        $lots = self::lotTotals('branch_stock');

        return <<<SQL
            (CASE
                WHEN EXISTS (SELECT 1 FROM branch_product_stocks any_stock WHERE any_stock.product_id = products.id AND any_stock.quantity > 0) THEN
                    COALESCE((
                        SELECT SUM({$usable})
                        FROM branch_product_stocks branch_stock
                        JOIN company_branches hub ON hub.id = branch_stock.branch_id AND hub.is_active AND hub.is_stock_hub
                        CROSS JOIN LATERAL ({$lots}) lots
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

    /**
     * A SQL expression for the units one branch can still use today: not on hold and not past
     * their date. It reads the surrounding products and branch_product_stocks rows. Unlike
     * {@see sql()} it also counts a branch that is not a stock hub, because the staff there
     * still need to know what they have left.
     */
    public static function branchSql(): string
    {
        $usable = self::usableUnits('branch_product_stocks');
        $lots = self::lotTotals('branch_product_stocks');

        return "(SELECT {$usable} FROM ({$lots}) lots)";
    }

    /**
     * Today's date at the company, once for each place the branch SQL asks for it.
     *
     * @return list<string>
     */
    public static function branchBindings(): array
    {
        $today = StockLot::today();

        return [$today, $today];
    }

    /**
     * Usable units of one branch stock row, from its lot totals. See the class notes for the
     * cases where the branch total and its lots disagree.
     */
    private static function usableUnits(string $branchStock): string
    {
        return <<<SQL
            GREATEST(0, lots.usable + CASE
                WHEN {$branchStock}.quantity < lots.total THEN {$branchStock}.quantity - lots.total
                WHEN products.expires_on IS NULL OR products.expires_on >= CAST(? AS date) THEN {$branchStock}.quantity - lots.total
                ELSE 0
            END)
            SQL;
    }

    /**
     * All units in one branch stock row's lots, and the units in them that can still go out.
     */
    private static function lotTotals(string $branchStock): string
    {
        return <<<SQL
            SELECT COALESCE(SUM(lot.quantity), 0) AS total,
                COALESCE(SUM(lot.quantity) FILTER (WHERE lot.held_at IS NULL AND (lot.expires_on IS NULL OR lot.expires_on >= CAST(? AS date))), 0) AS usable
            FROM stock_lots lot
            WHERE lot.branch_id = {$branchStock}.branch_id AND lot.product_id = {$branchStock}.product_id AND lot.quantity > 0
            SQL;
    }
}
