<?php

namespace App\Domain\Inventory;

use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Where a product's units are right now. Stock drops when a parcel leaves and comes back only
 * when staff receive it, so the units in between are not on any shelf: they are counted here
 * from the open parcels. Nothing in this class changes stock.
 *
 * Each unit is in exactly one stage, so on every branch and on the company total:
 * in stock + on hold + expired + with courier = total.
 * "In stock" uses the same rule as {@see SellableStock::branchSql()}.
 */
final class StockStages
{
    /** A parcel in this state has left the branch and is neither delivered nor received back. */
    public const OpenParcel = 'dispatched';

    /**
     * Units with a courier now, per product and the branch that sent them.
     */
    public static function courierUnits(): Builder
    {
        return DB::table('shipments as open_parcel')
            ->crossJoin(DB::raw('LATERAL json_array_elements(open_parcel.lines) AS parcel_line'))
            ->join('order_lines as parcel_order_line', 'parcel_order_line.id', '=', DB::raw("CAST(parcel_line->>'line_id' AS bigint)"))
            ->where('open_parcel.status', self::OpenParcel)
            ->groupBy('parcel_order_line.product_id', 'open_parcel.branch_id')
            ->selectRaw("parcel_order_line.product_id, open_parcel.branch_id, SUM(CAST(parcel_line->>'quantity' AS integer)) AS units");
    }

    /**
     * One row per product and branch with every stage worked out. With a branch, products that
     * branch has never held still get a row of zeros. Without one, a product that only has the
     * old company-wide figure gets one row with no branch.
     */
    public static function rows(?int $branchId = null, ?int $productId = null): Builder
    {
        $split = 'LEAST(lot_held, GREATEST(physical - in_stock, 0))';

        return DB::query()->fromSub(self::rawRows($branchId, $productId), 'stage_row')->selectRaw(
            "product_id, branch_id, physical, in_stock, with_courier, next_expiry, next_batch, {$split} AS on_hold, GREATEST(physical - in_stock - {$split}, 0) AS expired, GREATEST(physical - lot_units, 0) AS unlotted",
        );
    }

    private static function rawRows(?int $branchId, ?int $productId): Builder
    {
        $today = StockLot::today();
        $physical = $branchId === null
            ? 'CASE WHEN branch_product_stocks.branch_id IS NULL THEN GREATEST(products.stock, 0) ELSE branch_product_stocks.quantity END'
            : 'COALESCE(branch_product_stocks.quantity, 0)';
        $oldFigure = $branchId === null
            ? 'CASE WHEN products.expires_on IS NOT NULL AND products.expires_on < CAST(? AS date) THEN 0 ELSE GREATEST(products.stock, 0) END'
            : '0';
        $inStock = 'CASE WHEN branch_product_stocks.branch_id IS NULL THEN '.$oldFigure.' ELSE '.SellableStock::branchSql().' END';
        $inStockBindings = [...($branchId === null ? [$today] : []), ...SellableStock::branchBindings()];
        $onHandUnits = 'SELECT COALESCE(SUM(on_hand_lot.quantity), 0) AS total, COALESCE(SUM(on_hand_lot.quantity) FILTER (WHERE on_hand_lot.held_at IS NOT NULL), 0) AS held'
            .' FROM stock_lots on_hand_lot WHERE on_hand_lot.product_id = products.id AND on_hand_lot.branch_id = branch_product_stocks.branch_id AND on_hand_lot.quantity > 0';
        $nextLot = 'SELECT next_out.expires_on, next_out.batch_number FROM stock_lots next_out WHERE next_out.product_id = products.id AND next_out.branch_id = branch_product_stocks.branch_id AND next_out.quantity > 0'
            .' ORDER BY (next_out.held_at IS NOT NULL OR (next_out.expires_on IS NOT NULL AND next_out.expires_on < CAST(? AS date))), next_out.expires_on ASC NULLS LAST, next_out.received_on, next_out.id LIMIT 1';

        return DB::table('products')
            ->leftJoin('branch_product_stocks', function (JoinClause $join) use ($branchId): void {
                $join->on('branch_product_stocks.product_id', '=', 'products.id');
                if ($branchId !== null) {
                    $join->where('branch_product_stocks.branch_id', '=', $branchId);
                }
            })
            ->leftJoinLateral($onHandUnits, 'on_hand')
            ->leftJoinLateral(DB::query()->fromRaw("({$nextLot}) AS next_out_row", [$today]), 'next_lot')
            ->leftJoinSub(self::courierUnits(), 'with_courier', function (JoinClause $join): void {
                $join->on('with_courier.product_id', '=', 'products.id')->on('with_courier.branch_id', '=', 'branch_product_stocks.branch_id');
            })
            ->selectRaw('products.id AS product_id, branch_product_stocks.branch_id AS branch_id')
            ->selectRaw("CAST({$physical} AS integer) AS physical")
            ->selectRaw("CAST({$inStock} AS integer) AS in_stock", $inStockBindings)
            ->selectRaw('CAST(COALESCE(on_hand.total, 0) AS integer) AS lot_units, CAST(COALESCE(on_hand.held, 0) AS integer) AS lot_held')
            ->selectRaw('CAST(COALESCE(with_courier.units, 0) AS integer) AS with_courier')
            ->selectRaw('next_lot.expires_on AS next_expiry, next_lot.batch_number AS next_batch')
            ->when($productId !== null, fn (Builder $query) => $query->where('products.id', $productId));
    }

    /**
     * The stages of one product on every branch that has held it, and for the whole company.
     *
     * @return array{
     *     branches: list<array{branch_id: int|null, branch_code: string|null, branch_name: string|null, in_stock: int, on_hold: int, expired: int, with_courier: int, total: int}>,
     *     total: array{in_stock: int, on_hold: int, expired: int, with_courier: int, total: int}
     * }
     */
    public function forProduct(Product $product): array
    {
        $branches = CompanyBranch::query()->get(['id', 'code', 'name'])->keyBy('id');
        $lines = [];
        foreach (self::rows(productId: (int) $product->id)->get() as $row) {
            $branchId = $row->branch_id !== null ? (int) $row->branch_id : null;
            $branch = $branchId !== null ? $branches->get($branchId) : null;
            $lines[] = [
                'branch_id' => $branchId,
                'branch_code' => $branch?->code,
                'branch_name' => $branch?->name,
                'in_stock' => (int) $row->in_stock,
                'on_hold' => (int) $row->on_hold,
                'expired' => (int) $row->expired,
                'with_courier' => (int) $row->with_courier,
                'total' => (int) $row->physical + (int) $row->with_courier,
            ];
        }
        // A parcel from a branch since removed is still out with the courier.
        $orphans = (int) DB::query()->fromSub(self::courierUnits(), 'parcel')->where('product_id', $product->id)->whereNull('branch_id')->value('units');
        if ($orphans > 0) {
            $lines[] = ['branch_id' => null, 'branch_code' => null, 'branch_name' => null, 'in_stock' => 0, 'on_hold' => 0, 'expired' => 0, 'with_courier' => $orphans, 'total' => $orphans];
        }
        $total = ['in_stock' => 0, 'on_hold' => 0, 'expired' => 0, 'with_courier' => 0, 'total' => 0];
        foreach ($lines as $line) {
            foreach (array_keys($total) as $stage) {
                $total[$stage] += $line[$stage];
            }
        }

        return [
            'branches' => array_values(array_filter($lines, fn (array $line): bool => $line['branch_id'] !== null)),
            'total' => $total,
        ];
    }
}
