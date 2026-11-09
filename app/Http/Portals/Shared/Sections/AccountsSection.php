<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\PartnerAccountList;
use App\Domain\Inventory\BranchScope;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partner accounts: dealers, sub-dealers, their applications, and the dashboard settings screen.
 */
final class AccountsSection implements WorkspaceSection
{
    /** Statuses the application list can be filtered by. */
    public const ApplicationStatuses = ['pending', 'approved', 'rejected'];

    /** Where an application can come from: the website form, or an office worker. */
    public const ApplicationSources = ['public', 'staff'];

    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'accounts' => $this->partnerAccounts($context->request, 'dealer', $context->branchScope),
            'sub-dealers' => $this->partnerAccounts($context->request, 'sub_dealer', $context->branchScope),
            'dashboards' => $this->dashboards($context->user),
            'partnerships' => $this->partnerApplications($context->request, 'dealer'),
            'sub-dealer-applications' => $this->partnerApplications($context->request, 'sub_dealer'),
            default => [],
        };
    }

    /**
     * Paged dealer or sub-dealer accounts with status counts and area filters.
     * On a branch picked in the top bar, only that branch's partners.
     *
     * @return array<string, mixed>
     */
    private function partnerAccounts(Request $request, string $role, ?BranchScope $branchScope = null): array
    {
        $list = app(PartnerAccountList::class);
        $areas = $list->areaOptions($role);
        $filters = $this->withoutStrayDistrict(PartnerAccountList::filters($request), $areas);
        // Status counts follow every other filter but not the status filter, so every choice keeps its number.
        $props = [];
        $props['account_status_counts'] = $list->statusCounts($role, $filters, $branchScope);
        $table = $list->table($request, $role, $filters, $branchScope);
        $props['accounts'] = $table['rows'];
        $props['accounts_table'] = [
            'totals' => $table['totals'],
            'sortable' => $table['sortable'],
            'state' => [...$table['state'], 'filters' => $this->chosenAccountFilters($filters)],
        ];
        $props['account_areas'] = $areas;

        return $props;
    }

    /**
     * Changing the division must not leave a district from another division behind, or the list turns up empty.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @param  list<array{division_id: int, division: string, districts: list<array{id: int, name: string}>}>  $areas
     * @return array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}
     */
    private function withoutStrayDistrict(array $filters, array $areas): array
    {
        if ($filters['division'] === '' || $filters['district'] === '') {
            return $filters;
        }

        $chosenDivision = collect($areas)->firstWhere('division_id', (int) $filters['division']);
        $districtIds = array_column($chosenDivision['districts'] ?? [], 'id');

        return in_array((int) $filters['district'], $districtIds, true) ? $filters : [...$filters, 'district' => ''];
    }

    /**
     * The drop-downs the toolbar should show as chosen. The Closed view is its own switch, not a filter that Clear would undo.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @return array<string, string>
     */
    private function chosenAccountFilters(array $filters): array
    {
        return array_filter([
            'status' => $filters['status'] === PartnerAccountList::ClosedStatus ? '' : $filters['status'],
            'division' => $filters['division'],
            'district' => $filters['district'],
            'rank' => $filters['rank'],
            'no_order_days' => $filters['no_order_days'] > 0 ? (string) $filters['no_order_days'] : '',
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * Dashboard settings with account counts and the newest employees.
     *
     * @return array<string, mixed>
     */
    private function dashboards(User $user): array
    {
        abort_unless($user->role === 'super_admin' || $user->allows('accounts'), 403);
        $props = [];
        $props['dashboard_settings'] = DashboardManagement::get();
        $props['dashboard_defaults'] = DashboardManagement::defaults();
        $props['stats'] = [
            'dealers_count' => User::where('role', 'dealer')->count(),
            'active_dealers' => User::where('role', 'dealer')->where('status', 'active')->count(),
            'sub_dealers_count' => User::where('role', 'sub_dealer')->count(),
            'active_sub_dealers' => User::where('role', 'sub_dealer')->where('status', 'active')->count(),
            'employees_count' => User::where('role', 'employee')->count(),
            'active_employees' => User::where('role', 'employee')->where('status', 'active')->count(),
            'super_admins_count' => User::where('role', 'super_admin')->count(),
        ];
        $props['recent_employees'] = User::where('role', 'employee')->latest()->limit(6)->get(['id', 'name', 'email', 'status', 'profile', 'created_at']);

        return $props;
    }

    /**
     * @return array{applications: LengthAwarePaginator<int, array<string, mixed>>, applications_table: array<string, mixed>, application_counts: array{total: int, pending: int, approved: int, rejected: int}}
     */
    private function partnerApplications(Request $request, string $role): array
    {
        $table = MoneyTable::make(
            $request,
            DB::table('dealer_applications')
                ->select(['id', 'email', 'data', 'submitted_by', 'status', 'user_id', 'reason', 'source', 'created_at', 'reviewed_by'])
                ->whereRaw("coalesce(data->>'role', 'dealer') = ?", [$role]),
            new MoneyTableSpec(
                dateColumn: 'created_at',
                search: ['email', "data->>'name'", "data->>'phone'", "data->>'district'"],
                filters: [
                    new MoneyTableFilter('status', __('portal.col_status'), 'status', array_map(
                        fn (string $status): array => ['value' => $status, 'label' => __('portal.status_'.$status)],
                        self::ApplicationStatuses,
                    )),
                    new MoneyTableFilter('source', __('portal.partnership_source'), "coalesce(source, 'staff')", array_map(
                        fn (string $source): array => ['value' => $source, 'label' => __('office_partners.source_'.$source)],
                        self::ApplicationSources,
                    )),
                ],
                sorts: [
                    'created_at' => 'created_at',
                    'name' => "data->>'name'",
                    'source' => "coalesce(source, 'staff')",
                    'status' => 'status',
                ],
                defaultSort: 'created_at',
                tieBreaker: 'id',
                defaultPeriod: null,
                perPage: 20,
            ),
        );
        $applications = $table['rows']->through(function (object $row): array {
            $payload = json_decode((string) ($row->data ?? '{}'), true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return [
                'id' => $row->id,
                'email' => $row->email,
                'name' => $payload['name'] ?? '—',
                'phone' => $payload['phone'] ?? null,
                'role' => $payload['role'] ?? 'dealer',
                'division' => $payload['division'] ?? null,
                'district' => $payload['district'] ?? null,
                'upazila' => $payload['upazila'] ?? null,
                'place_code' => $payload['place_code'] ?? null,
                'sponsor_code' => $payload['sponsor_code'] ?? null,
                'message' => $payload['message'] ?? null,
                'status' => $row->status,
                'source' => $row->source ?? 'staff',
                'submitted_by' => $row->submitted_by,
                'user_id' => $row->user_id,
                'reason' => $row->reason,
                'created_at' => $row->created_at,
            ];
        });
        $counts = DB::table('dealer_applications')
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'pending') as pending")
            ->selectRaw("count(*) filter (where status = 'approved') as approved")
            ->selectRaw("count(*) filter (where status = 'rejected') as rejected")
            ->whereRaw("coalesce(data->>'role', 'dealer') = ?", [$role])
            ->first();

        return [
            'applications' => $applications,
            'applications_table' => ['totals' => $table['totals'], 'state' => $table['state'], 'filters' => $table['filters'], 'sortable' => $table['sortable']],
            'application_counts' => [
                'total' => (int) ($counts->total ?? 0),
                'pending' => (int) ($counts->pending ?? 0),
                'approved' => (int) ($counts->approved ?? 0),
                'rejected' => (int) ($counts->rejected ?? 0),
            ],
        ];
    }
}
