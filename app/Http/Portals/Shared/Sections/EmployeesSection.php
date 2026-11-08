<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchScope;
use App\Models\BranchStaffAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff accounts with their effective permissions, and the permission catalog the staff editor offers.
 */
final class EmployeesSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'employees') {
            return [];
        }
        $request = $context->request;
        $props = [];
        $query = User::where(fn ($q) => $q->whereIn('role', ['employee', 'sr'])->orWhere('role', 'like', 'custom\\_%'));
        $this->onScopedBranches($query, $context->branchScope);
        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('phone', 'ilike', $term);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $props['employees'] = $query->with('activeBranch:id,name,code')->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'role', 'status', 'profile', 'created_at', 'active_branch_id'])
            ->through(fn (User $employee) => [
                ...$employee->toArray(),
                'effective_permissions' => $employee->capabilities(),
                'branch_name' => $employee->activeBranch?->name,
            ]);
        $staffQuery = User::where(fn ($q) => $q->whereIn('role', ['employee', 'sr'])->orWhere('role', 'like', 'custom\\_%'));
        $this->onScopedBranches($staffQuery, $context->branchScope);
        $props['employee_stats'] = [
            'total' => (clone $staffQuery)->count(),
            'active' => (clone $staffQuery)->where('status', 'active')->count(),
            'suspended' => (clone $staffQuery)->where('status', 'suspended')->count(),
        ];
        $permissionCatalog = [
            ['key' => 'field_sales', 'category' => 'field_sales'],
            ['key' => 'sr_orders', 'category' => 'field_sales'],
            ['key' => 'sr_onboarding', 'category' => 'field_sales'],
            ['key' => 'staff_admin', 'category' => 'operations'],
            ['key' => 'payroll', 'category' => 'finance'],
            ['key' => 'orders', 'category' => 'operations'],
            ['key' => 'order_review', 'category' => 'operations'],
            ['key' => 'inventory', 'category' => 'inventory'],
            ['key' => 'catalog', 'category' => 'inventory'],
            ['key' => 'accounts', 'category' => 'network'],
            ['key' => 'finance', 'category' => 'finance'],
            ['key' => 'reports', 'category' => 'operations'],
            ['key' => 'content', 'category' => 'marketing'],
            ['key' => 'audit', 'category' => 'security'],
            ['key' => 'pricing', 'category' => 'pricing'],
            ['key' => 'cost_read', 'category' => 'finance'],
            ['key' => 'identity_write', 'category' => 'security'],
            ['key' => 'partner_chat', 'category' => 'operations'],
            ['key' => 'support_desk', 'category' => 'operations'],
            ['key' => 'prescriber_verify', 'category' => 'security'],
        ];
        $props['available_permissions'] = array_map(
            fn (array $permission): array => [
                'key' => $permission['key'],
                'label' => __('portal.perm_'.$permission['key']),
                'description' => __('portal.perm_'.$permission['key'].'_hint'),
                'category' => __('portal.perm_cat_'.$permission['category']),
            ],
            $permissionCatalog
        );

        return $props;
    }

    /**
     * On a branch picked in the top bar, only staff who work there: assigned to the
     * branch on the Branches page, or with it as their current branch.
     *
     * @param  Builder<User>  $staff
     */
    private function onScopedBranches(Builder $staff, ?BranchScope $branchScope): void
    {
        if ($branchScope === null || $branchScope->allBranches) {
            return;
        }
        $branchIds = $branchScope->branchIds;
        $staff->where(fn (Builder $working) => $working
            ->whereIn('users.active_branch_id', $branchIds)
            ->orWhereIn('users.id', BranchStaffAssignment::query()->whereIn('branch_id', $branchIds)->select('user_id')));
    }
}
