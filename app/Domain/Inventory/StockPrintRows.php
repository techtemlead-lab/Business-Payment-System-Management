<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\ProductSizeGroups;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The rows of the printed stock: the same rows, filters and branch as the Stock page, with the
 * product details (code, category, pack size, picture, price), the figures of each stage, every
 * batch still on hand with its expiry date, the date of the last stock movement and, only for
 * staff who may see costs, the value at cost. It only reads; nothing here changes stock.
 */
final class StockPrintRows
{
    public function __construct(private StockRegister $register) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     truncated: bool,
     *     filtered: bool,
     *     shows_cost: bool,
     *     all_branches: bool,
     *     branch_name: ?string,
     *     totals: array{in_stock: int, booked: int, on_hold: int, with_courier: int, expired: int, low: int, out: int, price_value: string, cost_value: ?string}
     * }
     */
    public function build(Request $request, BranchScope $scope, User $viewer): array
    {
        $found = $this->register->printRows($request, $scope, $viewer);
        $showsCost = $viewer->allows('cost_read');
        $rows = $found['rows'];
        $productIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
        $products = $productIds === [] ? collect() : Product::query()->whereIn('id', $productIds)->get(['id', 'strength', 'pack_size_en', 'pack_size_bn', 'name', 'mrp', 'category'])->keyBy('id');
        $lots = $this->lots($productIds, $scope);
        $moved = $this->lastMovements($productIds, $scope);

        $totals = ['in_stock' => 0, 'booked' => 0, 'on_hold' => 0, 'with_courier' => 0, 'expired' => 0, 'low' => 0, 'out' => 0];
        $priceCents = 0;
        $costCents = 0;
        $out = [];
        foreach ($rows as $index => $row) {
            /** @var Product|null $product */
            $product = $products->get((int) $row['id']);
            [$name, $packSize] = StockPrintName::split((string) $row['register_name'], $product ? ProductSizeGroups::label($product) : null);
            $key = $row['id'].'-'.($row['branch_id'] ?? '');
            $costValue = null;
            if ($showsCost && isset($row['cost_price']) && $row['cost_price'] !== '') {
                $costValue = Money::decimal($this->cents($row['cost_price']) * (int) $row['physical']);
                $costCents += $this->cents($row['cost_price']) * (int) $row['physical'];
            }
            $priceCents += $this->cents($row['stock_value']);
            foreach (['in_stock', 'booked', 'on_hold', 'with_courier', 'expired'] as $field) {
                $totals[$field] += (int) $row[$field];
            }
            $totals['low'] += $row['level'] === 'low' ? 1 : 0;
            $totals['out'] += $row['level'] === 'out_of_stock' ? 1 : 0;
            $out[] = [
                'product_id' => (int) $row['id'],
                'serial' => $index + 1,
                'name' => $name,
                'pack_size' => $packSize,
                'sku' => $row['sku'],
                'category' => filled($row['category']) ? (string) $row['category'] : null,
                'unit' => $row['unit'],
                'mrp' => $this->cents($row['mrp']) > 0 ? Money::decimal($this->cents($row['mrp'])) : null,
                'branch_name' => $row['branch_name'],
                'in_stock' => (int) $row['in_stock'],
                'booked' => (int) $row['booked'],
                'free_to_sell' => (int) $row['free_to_sell'],
                'on_hold' => (int) $row['on_hold'],
                'with_courier' => (int) $row['with_courier'],
                'expired' => (int) $row['expired'],
                'level' => (string) $row['level'],
                'low_stock_at' => (int) $row['low_stock_at'],
                'is_stock_paused' => (bool) $row['is_stock_paused'],
                'live' => (bool) $row['active'],
                'lots' => $lots[$key] ?? [],
                'batch_number' => $row['batch_number'],
                'expires_on' => $row['expires_on'],
                'last_movement' => $moved[$key] ?? null,
                'price_value' => $this->cents($row['stock_value']) > 0 ? Money::decimal($this->cents($row['stock_value'])) : null,
                'cost_value' => $costValue,
            ];
        }

        return [
            'rows' => $out,
            'total' => $found['total'],
            'truncated' => $found['truncated'],
            'filtered' => $found['filtered'],
            'shows_cost' => $showsCost,
            'all_branches' => $scope->allBranches,
            'branch_name' => $scope->branch?->name,
            'totals' => [...$totals, 'price_value' => Money::decimal($priceCents), 'cost_value' => $showsCost ? Money::decimal($costCents) : null],
        ];
    }

    /**
     * Every batch still on hand, by product and branch.
     *
     * @param  list<int>  $productIds
     * @return array<string, list<array{batch: ?string, expires_on: ?string, quantity: int}>>
     */
    private function lots(array $productIds, BranchScope $scope): array
    {
        if ($productIds === []) {
            return [];
        }
        $lots = StockLot::query()->whereIn('product_id', $productIds)->where('quantity', '>', 0)
            ->when(! $scope->allBranches, fn ($query) => $query->whereIn('branch_id', $scope->branchIds))
            ->orderByRaw('expires_on ASC NULLS LAST')->orderBy('id')
            ->get(['product_id', 'branch_id', 'batch_number', 'expires_on', 'quantity']);
        $byRow = [];
        foreach ($lots as $lot) {
            $byRow[$lot->product_id.'-'.$lot->branch_id][] = [
                'batch' => $lot->batch_number,
                'expires_on' => $lot->expires_on?->format('Y-m-d'),
                'quantity' => (int) $lot->quantity,
            ];
        }

        return $byRow;
    }

    /**
     * The date of the latest stock movement, by product and branch.
     *
     * @param  list<int>  $productIds
     * @return array<string, string>
     */
    private function lastMovements(array $productIds, BranchScope $scope): array
    {
        if ($productIds === []) {
            return [];
        }
        $moves = DB::table('stock_movements')->whereIn('product_id', $productIds)
            ->when(! $scope->allBranches, fn ($query) => $query->whereIn('branch_id', $scope->branchIds))
            ->groupBy('product_id', 'branch_id')
            ->selectRaw('product_id, branch_id, MAX(created_at) AS last_at')
            ->get();
        $byRow = [];
        foreach ($moves as $move) {
            $byRow[$move->product_id.'-'.$move->branch_id] = Carbon::parse($move->last_at)->timezone('Asia/Dhaka')->format('Y-m-d');
        }

        return $byRow;
    }

    private function cents(mixed $amount): int
    {
        try {
            return $amount === null || $amount === '' ? 0 : Money::cents((string) $amount);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}
