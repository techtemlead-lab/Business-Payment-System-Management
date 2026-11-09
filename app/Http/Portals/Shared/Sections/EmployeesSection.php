<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchScope;
use App\Domain\Staff\StaffPermissions;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use stdClass;

/**
 * Staff accounts with their effective permissions, and the permission catalog the staff editor offers.
 */
final class EmployeesSection implements WorkspaceSection
{
    /** Columns of the staff list. */
    private const ListColumns = ['id', 'name', 'email', 'phone', 'role', 'status', 'profile', 'created_at', 'active_branch_id'];

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'employees') {
            return [];
        }
        $request = $context->request;
        $props = [];
        $query = $this->officeStaff();
        $this->onScopedBranches($query, $context->branchScope);
        $customRoleNames = app(StaffPermissions::class)->customRoleNames();
        $table = MoneyTable::make($request, $query->select(self::ListColumns)->toBase(), $this->tableSpec($customRoleNames, $context->branchScope));
        $people = User::hydrate($table['rows']->items())->load('activeBranch:id,name,code')->keyBy('id');
        $rows = $table['rows']->through(function (stdClass $row) use ($people, $customRoleNames): array {
            /** @var User $employee */
            $employee = $people[$row->id];

            return [
                ...$employee->toArray(),
                'role_name' => $customRoleNames[$employee->role] ?? null,
                'effective_permissions' => $employee->capabilities(),
                'branch_name' => $employee->activeBranch?->name,
            ];
        });
        $props['employees'] = [...$rows->toArray(), 'table' => Arr::except($table, ['rows'])];
        $staffQuery = $this->officeStaff();
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
        $props['owner_accounts'] = $context->user->role === 'super_admin'
            ? User::query()->whereIn('role', ['md', 'chairman'])->orderBy('role')->orderBy('name')->get(['id', 'name', 'role', 'status'])->all()
            : [];
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
     * Search, filters and sort of the staff list. The branch filter has its own name so it
     * never touches the branch chosen in the top bar.
     *
     * @param  array<string, string>  $customRoleNames
     */
    private function tableSpec(array $customRoleNames, ?BranchScope $branchScope): MoneyTableSpec
    {
        $builtInRoles = ['employee', ...array_diff(array_keys(config('madina.roles')), ['super_admin', 'md', 'chairman'])];
        $roleOptions = array_map(fn (string $role): array => ['value' => $role, 'label' => (string) __('portal.staff_role_'.$role)], array_values(array_unique($builtInRoles)));
        foreach ($customRoleNames as $key => $name) {
            $roleOptions[] = ['value' => (string) $key, 'label' => $name];
        }

        $branches = CompanyBranch::query()->orderBy('name');
        if ($branchScope !== null && ! $branchScope->allBranches) {
            $branches->whereIn('id', $branchScope->branchIds);
        }
        $branchOptions = $branches->get(['id', 'name'])
            ->map(fn (CompanyBranch $branch): array => ['value' => (string) $branch->id, 'label' => (string) $branch->name])
            ->all();

        $filters = [new MoneyTableFilter('role', (string) __('office_staff.filter_role'), 'users.role', $roleOptions)];
        if (count($branchOptions) > 1) {
            $filters[] = new MoneyTableFilter('staff_branch', (string) __('office_staff.filter_branch'), 'CAST(users.active_branch_id AS text)', $branchOptions);
        }
        $filters[] = new MoneyTableFilter('status', (string) __('office_staff.filter_status'), 'users.status', [
            ['value' => 'active', 'label' => (string) __('portal.status_active')],
            ['value' => 'suspended', 'label' => (string) __('portal.status_suspended')],
        ]);

        return new MoneyTableSpec(
            dateColumn: null,
            search: ['users.name', 'users.email', 'users.phone'],
            filters: $filters,
            sorts: ['newest' => 'users.id', 'name' => 'LOWER(users.name)', 'role' => 'users.role', 'joined' => 'users.created_at', 'status' => 'users.status'],
            defaultSort: 'newest',
            tieBreaker: 'users.id',
            defaultPeriod: null,
            perPage: 20,
        );
    }

    /**
     * Every office staff member. The owners (Super Admin, MD, Chairman) are not managed here.
     *
     * @return Builder<User>
     */
    private function officeStaff(): Builder
    {
        $roles = ['employee', ...array_diff(array_keys(config('madina.roles')), ['super_admin', 'md', 'chairman'])];

        return User::where(fn (Builder $staff) => $staff->whereIn('role', $roles)->orWhere('role', 'like', 'custom\\_%'));
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
            ->orWhereIn('users.id', BranchStaffAssignment::query()->whereIn('branch_id', $branchIds)->select('user_id'))
            // Someone just added has no branch yet; the head office lists them so they can be found and placed.
            ->when($branchScope->showsHeadOffice(), fn (Builder $headOffice) => $headOffice->orWhere(fn (Builder $noBranch) => $noBranch
                ->whereNull('users.active_branch_id')
                ->whereNotIn('users.id', BranchStaffAssignment::query()->select('user_id')))));
    }
}
