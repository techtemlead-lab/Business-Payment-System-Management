<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotReports;
use App\Models\BranchProductStock;
use App\Models\StockLot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory section: branch stock laid over the product list CatalogSection
 * built, the branch picker, and the stock movement log.
 */
final class InventorySection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'inventory') {
            return [];
        }
        $request = $context->request;
        $user = $context->user;
        $props = [];
        $branchOps = app(BranchOperations::class);
        ['preferred' => $preferredBranch, 'branches' => $props['branches']] = $branchOps->branchChoices($user);
        $props['active_branch_id'] = $preferredBranch?->id;
        $props['active_branch'] = $preferredBranch?->summary();
        $props['delivery_points'] = $branchOps->deliveryPoints();
        $props['supports_product_expiry'] = Schema::hasColumn('products', 'expires_on');
        $props['stock_reason_codes'] = [
            'product_in' => BranchOperations::ProductInReasonCodes,
            'product_out' => BranchOperations::ProductOutReasonCodes,
        ];
        $viewingAllBranches = $request->session()->get(BranchOperations::SessionView) === 'all';
        $props['branch_view'] = $viewingAllBranches ? 'all' : 'branch';
        $activeBranchId = (int) ($preferredBranch?->id ?? 0);
        if ($viewingAllBranches && $user->allows('inventory')) {
            $props['products'] = $branchOps->expandStockAcrossBranches($data['products'] ?? []);
            $props['active_branch'] = [
                'id' => null,
                'code' => 'ALL',
                'name' => __('portal.branch_view_all'),
            ];
        } elseif ($activeBranchId > 0 && $user->allows('inventory')) {
            $branchQuantities = BranchProductStock::query()
                ->where('branch_id', $activeBranchId)
                ->pluck('quantity', 'product_id');
            $branchName = $preferredBranch?->name;
            $props['products'] = collect($data['products'] ?? [])->map(function (array $product) use ($branchQuantities, $branchName) {
                $product['branch_stock'] = (int) ($branchQuantities[$product['id']] ?? 0);
                $product['company_stock'] = (int) ($product['stock'] ?? 0);
                $product['branch_name'] = $branchName;

                return $product;
            })->all();
        }
        $props['products'] = $this->withBatchOnHand($this->withBookedUnits($props['products'] ?? $data['products'] ?? []), $activeBranchId);
        // The movement log follows the same branch as the stock on hand above it.
        $branchScope = $viewingAllBranches && $branchOps->canViewAllBranches($user)
            ? new BranchScope(true, [])
            : new BranchScope(false, $preferredBranch ? [$preferredBranch->id] : [], $preferredBranch);
        $props['branch_scope'] = $branchScope->chip();
        $movements = DB::table('stock_movements');
        $branchScope->restrict($movements, 'stock_movements.branch_id');
        $props['movements'] = $movements
            ->join('products', 'products.id', '=', 'product_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'stock_movements.branch_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.actor_id')
            ->select('stock_movements.*', 'products.name', 'products.sku', 'company_branches.code as branch_code', 'company_branches.name as branch_name', 'users.name as actor_name')
            ->orderByDesc('stock_movements.id')
            ->paginate(20);

        return $props;
    }

    /**
     * The expiry and batch on a stock row come from the goods still on that row: the lot that
     * goes out next, or a lot on hold or expired when nothing else is left. A row with no
     * units names nothing, so an emptied lot stops showing. Stock from before lots were
     * recorded has no lot row and keeps the details saved on the product.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function withBatchOnHand(array $products, int $activeBranchId): array
    {
        $lotsByProduct = app(StockLotReports::class)->lotsOnHand(array_map(intval(...), array_column($products, 'id')))->groupBy('product_id');

        return array_map(function (array $product) use ($lotsByProduct, $activeBranchId): array {
            $productLots = $lotsByProduct->get($product['id'], collect());
            if ($productLots->isEmpty() && (int) ($product['company_stock'] ?? $product['stock'] ?? 0) > 0) {
                return $product;
            }
            $branchId = isset($product['branch_stock']) ? (int) ($product['branch_id'] ?? $activeBranchId) : 0;
            $lots = $branchId > 0 ? $productLots->where('branch_id', $branchId) : $productLots;
            $next = $lots->first(fn (StockLot $lot): bool => ! $lot->isOnHold() && ! $lot->isExpired()) ?? $lots->first();

            return [...$product, 'expires_on' => $next?->expires_on?->toDateString(), 'batch_number' => $next?->batch_number];
        }, $products);
    }

    /**
     * Units promised to approved orders that have not been sent yet. Stock only
     * drops when an order is sent, so this shows how much is really free to sell,
     * like the reserved quantity in ERPNext or Odoo. Counted for the whole
     * company: an order gets its sending branch only when it is sent.
     *
     * @param  iterable<array<string, mixed>|object>  $products
     * @return list<array<string, mixed>>
     */
    private function withBookedUnits(iterable $products): array
    {
        $booked = DB::table('order_lines as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('o.status', ['approved', 'partially_dispatched'])
            ->whereColumn('l.dispatched', '<', 'l.quantity')
            ->groupBy('l.product_id')
            ->selectRaw('l.product_id, SUM(l.quantity - l.dispatched) AS units')
            ->pluck('units', 'product_id');

        return collect($products)->map(function (mixed $product) use ($booked): array {
            $product = (array) $product;
            $product['booked_units'] = (int) ($booked[$product['id'] ?? 0] ?? 0);

            return $product;
        })->values()->all();
    }
}
