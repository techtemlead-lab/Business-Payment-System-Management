<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
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
        $preferredBranch = $branchOps->preferredBranch($user);
        $props['branches'] = $branchOps->branchesFor($user);
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
        $props['movements'] = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'product_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'stock_movements.branch_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.actor_id')
            ->select('stock_movements.*', 'products.name', 'products.sku', 'company_branches.code as branch_code', 'company_branches.name as branch_name', 'users.name as actor_name')
            ->orderByDesc('stock_movements.id')
            ->paginate(20);

        return $props;
    }
}
