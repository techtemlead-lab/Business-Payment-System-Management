<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockMovementList;
use App\Models\BranchProductStock;
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
        $props['products'] = $this->withBookedUnits($props['products'] ?? $data['products'] ?? []);
        // The movement log follows the same branch as the stock on hand above it.
        $branchScope = StockMovementList::scope($user, $request, $preferredBranch);
        $props['branch_scope'] = $branchScope->chip();
        $movements = app(StockMovementList::class);
        $movementFilters = StockMovementList::filters($request);
        // The totals query also gives the row count, so the page skips its own count query.
        $props['movement_totals'] = $movements->totals($movementFilters, $branchScope);
        $props['movements'] = $movements->page($movementFilters, $branchScope, total: $props['movement_totals']['count']);
        $props['movement_kinds'] = StockMovementList::Kinds;

        return $props;
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
