<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchPerformance;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Company branches: the branch list and the branch performance report.
 */
final class BranchesSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'branches' => $this->branches($context),
            'branch-performance' => $this->performance($context),
            default => [],
        };
    }

    /**
     * Branches with their staff and stock totals, and the staff who can be assigned.
     *
     * @return array<string, mixed>
     */
    private function branches(SectionContext $context): array
    {
        $user = $context->user;
        $props = [];
        $branchOps = app(BranchOperations::class);
        $branches = CompanyBranch::query()
            ->with('placeCode:id,code')
            ->withCount(['stocks as stock_skus' => fn ($stocks) => $stocks->where('quantity', '>', 0)])
            ->withSum('stocks as stock_units', 'quantity')
            ->addSelect(['stock_value' => DB::table('branch_product_stocks')
                ->join('products', 'products.id', '=', 'branch_product_stocks.product_id')
                ->whereColumn('branch_product_stocks.branch_id', 'company_branches.id')
                ->selectRaw('COALESCE(SUM(branch_product_stocks.quantity * products.mrp), 0)')])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        $branchIds = $branches->getCollection()->pluck('id');
        $staffByBranch = BranchStaffAssignment::query()
            ->with('user:id,name,email,phone,role')
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->groupBy('branch_id');
        $props['branches'] = $branches->through(function (CompanyBranch $branch) use ($staffByBranch) {
            $summary = $branch->summary();
            $summary['staff'] = ($staffByBranch[$branch->id] ?? collect())->map(fn (BranchStaffAssignment $row) => [
                'user_id' => $row->user_id,
                'name' => $row->user?->name,
                'email' => $row->user?->email,
                'phone' => $row->user?->phone,
                'duty' => $row->duty,
                'is_primary' => $row->is_primary,
            ])->values()->all();
            $summary['stock_skus'] = (int) ($branch->stock_skus ?? 0);
            $summary['stock_units'] = (int) ($branch->stock_units ?? 0);
            $summary['stock_value'] = number_format((float) ($branch->stock_value ?? 0), 2, '.', '');
            $manager = collect($summary['staff'])->first(fn (array $member): bool => (bool) $member['is_primary'])
                ?? ($summary['staff'][0] ?? null);
            $summary['manager_name'] = is_array($manager) ? ($manager['name'] ?? null) : null;

            return $summary;
        });
        $props['active_branch_id'] = $user->active_branch_id;
        $props['delivery_points'] = $branchOps->deliveryPoints();
        $props['assignable_staff'] = User::query()
            ->staffAccounts()
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email', 'phone', 'role'])
            ->map(fn (User $staffMember) => $staffMember->only(['id', 'name', 'email', 'phone', 'role']))
            ->all();

        return $props;
    }

    /**
     * Branch sales report for the chosen period and branch.
     *
     * @return array<string, mixed>
     */
    private function performance(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        $props = [];
        $props['performance'] = app(BranchPerformance::class)->summary($user, [
            'period' => $request->query('period'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'branch_id' => $request->query('branch_id'),
        ]);
        $props['branch_options'] = CompanyBranch::query()->orderBy('name')->get()->map(fn (CompanyBranch $branch) => $branch->summary())->all();

        return $props;
    }
}
