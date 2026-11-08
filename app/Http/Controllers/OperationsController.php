<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\PolicyChangeSummary;
use App\Domain\Commissions\RecipientFunding;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundSpending;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\ReportData;
use App\Domain\Finance\ReserveService;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Staff\StaffPermissions;
use App\Domain\Staff\TerritoryAccess;
use App\Models\Product;
use App\Models\User;
use App\Support\DeploymentReadiness;
use App\Support\Money;
use App\Support\OfficeHubs;
use App\Support\ProductVideo;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OperationsController extends Controller
{
    /**
     * Which columns each table's search box looks in, and which status column its filter uses.
     * Fixed SQL column names only; the search text is always passed as a binding.
     *
     * @var array<string, array{search: list<string>, status: string|null, statuses: list<string>}>
     */
    private const TableFilters = [
        'territories' => ['search' => ['u.name', 't.level', 't.division', 't.district', 't.upazila', 't."union"'], 'status' => null, 'statuses' => []],
        'monthly_targets' => ['search' => ['u.name', 't.period_month'], 'status' => null, 'statuses' => []],
        'territory_orders' => ['search' => ['o.number', 'u.name'], 'status' => 'o.status', 'statuses' => ['submitted', 'held', 'approved', 'rejected', 'cancelled', 'partially_dispatched', 'dispatched', 'delivered']],
        'my_targets' => ['search' => ['period_month'], 'status' => null, 'statuses' => []],
        'dealer_applications' => ['search' => ['email', "data->>'name'", "data->>'phone'", "data->>'district'"], 'status' => 'status', 'statuses' => ['pending', 'approved', 'rejected']],
        'salary_register' => ['search' => ['u.name', 'p.period_month', 'p.payment_reference'], 'status' => 'p.status', 'statuses' => ['draft', 'approved', 'rejected', 'paid']],
        'salary_contracts' => ['search' => ['u.name', 'c.effective_month', 'c.reason'], 'status' => null, 'statuses' => []],
        'policy_schedules' => ['search' => ['u.name', 'proposer.name', 's.reason', 's.scope'], 'status' => 's.status', 'statuses' => ['draft', 'approved', 'rejected', 'cancelled']],
        'reserve_requests' => ['search' => ['u.name', 'r.payee_name', 'r.program', 'r.payment_reference', 'r.reason'], 'status' => 'r.status', 'statuses' => ['requested', 'approved', 'credited', 'rejected', 'paid', 'cancelled']],
        'clawback_recoveries' => ['search' => ['payment_reference'], 'status' => null, 'statuses' => []],
        'held_allocations' => ['search' => ['u.name', 'a.category', 'a.order_id'], 'status' => null, 'statuses' => []],
        'opening_holds' => ['search' => ['u.name', 'e.reference'], 'status' => null, 'statuses' => []],
        'opening_reviews' => ['search' => ['fingerprint', 'source_reference'], 'status' => null, 'statuses' => []],
    ];

    public const Sections = ['field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports', 'finance-settings', 'access-management', 'video-marketing'];

    public function index(Request $request, string $section): Response|JsonResponse
    {
        $actor = $request->user();
        $capability = match ($section) {
            'access-management' => 'staff_admin', 'video-marketing' => $actor->allows('content') ? 'content' : 'catalog',
            'field-sales' => 'field_sales', 'staff-operations' => 'staff_admin', 'payroll' => 'payroll', 'my-payroll' => null,
            'financial-reports' => $actor->allows('finance') ? 'finance' : ($actor->allows('field_sales') ? 'field_sales' : 'reports'), default => 'finance'
        };
        abort_unless(in_array($section, self::Sections, true) && $actor->staff() && ($capability === null || $actor->allows($capability)), 403);
        $request->validate(['q' => 'nullable|string|max:100']);
        $tables = [];
        $data = ['section' => $section, 'tables' => [], 'paymentAccounts' => [], 'partners' => [], 'staff' => [], 'summary' => [], 'partnerSearch' => (string) $request->string('q')];
        if ($section === 'financial-reports') {
            $data['reportTypes'] = app(ReportData::class)->availableTo($actor);
            $data['reportGroups'] = OfficeHubs::reportGroups($actor, $data['reportTypes']);
        }
        $staff = User::staffAccounts()->orderBy('name')->get(['id', 'name', 'role', 'status', 'profile']);
        if ($actor->allows('staff_admin') || $actor->allows('finance')) {
            $data['staff'] = $staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'field_sales' => $u->allows('field_sales')])->all();
        }
        if (in_array($section, ['policy-schedules', 'reserve-payouts', 'field-sales', 'commission-holds'], true)) {
            $partners = $section === 'field-sales' ? app(TerritoryAccess::class)->partners($actor) : User::whereIn('role', ['dealer', 'sub_dealer']);
            $data['partners'] = $partners->when($request->filled('q'), fn ($q) => $q->where(fn ($match) => $match->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('dealer_code', 'ilike', '%'.$request->string('q').'%')))
                ->orderBy('name')->limit(200)->get(['id', 'name', 'dealer_code', 'role', 'status', 'division', 'district'])->toArray();
        }
        if ($section === 'payment-accounts' || in_array($section, ['payroll', 'reserve-payouts'], true) && $actor->allows('finance')) {
            $data['paymentAccounts'] = app(PaymentAccounts::class)->listing($actor);
            if ($section === 'payment-accounts' && $request->filled('account_history')) {
                $request->validate(['account_history' => 'required|integer|min:1']);
                $data['accountHistory'] = app(PaymentAccounts::class)->history($actor, (int) $request->input('account_history'));
            }
        }
        if ($section === 'staff-operations') {
            $tables['territories'] = DB::table('staff_territories as t')->join('users as u', 'u.id', '=', 't.staff_id')->select(['t.*', 'u.name'])->orderByDesc('t.id');
            $tables['monthly_targets'] = DB::table('staff_targets as t')->join('users as u', 'u.id', '=', 't.staff_id')->select(['t.id', 't.staff_id', 'u.name', 't.period_month', 't.target', 't.bonus', 't.version'])->orderByDesc('t.period_month');
        }
        if ($section === 'field-sales') {
            $territories = app(TerritoryAccess::class);
            $data['regions'] = $territories->territories($actor->id);
            $data['products'] = Product::where('active', true)->where('is_stock_paused', false)->orderBy('name')->get(['id', 'name', 'sku']);
            $data['fullPaymentWhenOrdering'] = app(FinanceSettings::class)->fullPaymentWhenOrdering();
            $scope = $territories->partners($actor)->select('users.id');
            if ($actor->allows('sr_orders')) {
                $columns = ['o.id', 'o.number', 'u.name', 'o.status', 'o.total', 'o.submitted_by'];
                $tables['territory_orders'] = DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')->whereIn('o.user_id', $scope)->select($columns)->orderByDesc('o.created_at');
            }
            $tables['my_targets'] = DB::table('staff_targets')->where('staff_id', $actor->id)->orderByDesc('period_month');
            $data['summary'] = ['current_month_net_territory_sales' => Money::decimal($territories->volume($actor->id, now()->timezone('Asia/Dhaka')->format('Y-m')))];
        }
        if (in_array($section, ['field-sales', 'staff-operations'], true)) {
            $tables['dealer_applications'] = DB::table('dealer_applications')->when(! $actor->allows('accounts'), fn ($q) => $q->where('submitted_by', $actor->id))
                ->select(['id', 'email', 'data', 'submitted_by', 'status', 'user_id', 'reason', 'created_at'])->orderByDesc('created_at');
        }
        if (in_array($section, ['payroll', 'my-payroll'], true)) {
            $tables['salary_register'] = DB::table('payroll_entries as p')->join('users as u', 'u.id', '=', 'p.staff_id')
                ->when($section === 'my-payroll', fn ($q) => $q->where('staff_id', $actor->id))
                ->select(['p.id', 'u.name', 'p.staff_id', 'p.period_month', 'p.gross', 'p.deduction', 'p.net', 'p.snapshot', 'p.status', 'p.prepared_by', 'p.approved_by', 'p.payment_reference', 'p.paid_at', DB::raw('p.receipt_path IS NOT NULL AS has_receipt')])->orderByDesc('p.created_at');
            if ($actor->allows('finance') && $section === 'payroll') {
                $tables['salary_contracts'] = DB::table('staff_contracts as c')->join('users as u', 'u.id', '=', 'c.staff_id')->select(['c.id', 'u.name', 'c.effective_month', 'c.revision', 'c.base_salary', 'c.allowance', 'c.deduction', 'c.reason'])->orderByDesc('c.effective_month')->orderByDesc('c.id');
            }
        }
        if ($section === 'policy-schedules') {
            $data['hub'] = OfficeHubs::commission($actor, true, $section);
            // Changes waiting for the owner come first, with old → new values and who proposed them.
            $tables['policy_schedules'] = DB::table('commission_policy_schedules as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')
                ->leftJoin('users as proposer', 'proposer.id', '=', 's.created_by')->leftJoin('users as reviewer', 'reviewer.id', '=', 's.reviewed_by')
                ->select(['s.id', 's.status', 's.scope', 's.origin', 'u.name', 's.changes', 'proposer.name as proposed_by_name', 's.reason',
                    's.effective_at', 's.expires_at', 'reviewer.name as reviewed_by_name', 's.reviewed_at', 's.review_reason', 's.created_by', 's.user_id'])
                ->orderByRaw("CASE WHEN s.status = 'draft' THEN 0 ELSE 1 END")->orderByDesc('s.id');
            $data['policy'] = [...app(CommissionPolicy::class)->snapshot(), 'funding_sources' => RecipientFunding::Sources];
        }
        if ($section === 'reserve-payouts') {
            $tables['reserve_requests'] = DB::table('reserve_disbursements as r')->where('r.program', '!=', FundSpending::Program)->leftJoin('users as u', 'u.id', '=', 'r.user_id')->leftJoin('payout_receipts as pr', 'pr.reserve_disbursement_id', '=', 'r.id')
                ->select(['r.id', 'r.program', 'u.name', 'r.user_id', 'r.payee_name', 'r.amount', 'r.status', 'r.created_by', 'r.approved_by', 'r.payment_reference', 'r.reason', 'pr.id as payout_receipt_id', 'pr.number as payout_receipt_number', DB::raw('r.receipt_path IS NOT NULL AS has_receipt')])->orderByDesc('r.id');
            $tables['reserve_clawbacks'] = DB::table('reserve_expense_clawbacks')->orderByDesc('id');
            $tables['clawback_recoveries'] = DB::table('reserve_recoveries')->select(['id', 'clawback_id', 'amount', 'payment_reference', 'paid_at', DB::raw('true AS has_receipt')])->orderByDesc('id');
            $remaining = app(ReserveService::class)->remainingByCategory();
            // Bike and car draw on the same fund, so it is shown once as the car and bike fund.
            $programs = [];
            foreach (ReserveService::Programs as $program => $category) {
                $programs[in_array($program, ['bike', 'car'], true) ? 'car_bike' : $program] = $category;
            }
            foreach ($programs as $program => $category) {
                $total = $remaining[$category] ?? 0;
                $data['summary'][$program.'_uncommitted_reserve'] = ($total < 0 ? '-' : '').Money::decimal(abs($total));
            }
        }
        if ($section === 'commission-holds') {
            $tables['held_allocations'] = DB::table('commission_allocations as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.status', 'held')
                ->select(['a.id', 'u.name', 'u.status as partner_status', 'a.order_id', 'a.category', 'a.amount', 'a.metadata'])->orderBy('a.id');
        }
        if ($section === 'commission-holds') {
            $tables['opening_holds'] = DB::table('opening_balance_entries as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->whereColumn('e.held', '>', 'e.held_released')->select(['e.id', 'u.name', 'e.reference', 'e.held', 'e.as_of_date'])->orderBy('e.id');
        }
        if ($section === 'financial-readiness') {
            $data['readiness'] = app(FinancialReadiness::class)->inspect();
            $data['deploymentStatus'] = app(DeploymentReadiness::class)->inspect(app()->isProduction());
            $data['startupMode'] = app(IntegrationSettings::class)->startupMode();
            $tables['opening_reviews'] = DB::table('financial_verifications')->select(['id', 'fingerprint', 'source_reference', 'created_by', 'verified_by', 'verified_at', 'created_at'])->orderByDesc('id');
        }
        if ($section === 'finance-settings') {
            $data['withdrawalSetting'] = app(FinanceSettings::class)->withdrawalMinimum();
            $data['autoApproval'] = app(FinanceSettings::class)->autoApproval();
            $data['kycBeforeFirstWithdrawal'] = [...app(FinanceSettings::class)->kycBeforeFirstWithdrawal(), 'can_edit' => $actor->owner()];
            $data['fullPaymentWhenOrdering'] = [...app(FinanceSettings::class)->fullPaymentWhenOrdering(), 'can_edit' => $actor->owner()];
        }
        if ($section === 'access-management') {
            abort_unless($actor->role === 'super_admin', 403);
            $permissions = app(StaffPermissions::class);
            $data['permissionModules'] = $permissions->modules();
            $data['rolePresets'] = collect(['employee' => [], ...config('madina.roles')])->map(fn ($capabilities, $key) => ['key' => $key, 'name' => Str::headline($key),
                'permissions' => $permissions->matrix(new User(['role' => $key]))])->values()->all();
            $data['staffAccess'] = $staff->where('role', '!=', 'super_admin')->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role,
                'version' => $permissions->version($user), 'permissions' => $permissions->matrix($user)])->values()->all();
            $data['customRoles'] = DB::table('staff_roles')->orderBy('name')->get()->map(fn ($role) => ['key' => $role->key, 'name' => $role->name,
                'version' => $role->version, 'permissions' => json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR)])->all();
        }
        if ($section === 'video-marketing') {
            $data = [...$data, ...ProductVideo::managementData()];
            // Staff who can open the website page manage videos there; the rest keep this form.
            $data['videoManagedOnWebsite'] = $actor->allows('content');
        }
        foreach ($tables as $key => $query) {
            $filter = $this->applyTableFilter($request, $key, $query);
            $page = $query->paginate(20, ['*'], 'page_'.count($data['tables']))->withQueryString();
            if ($key === 'policy_schedules') {
                $page->through(function (object $row): object {
                    $changes = is_string($row->changes) ? json_decode($row->changes, true, flags: JSON_THROW_ON_ERROR) : [];
                    $row->changes = PolicyChangeSummary::describe(is_array($changes) ? $changes : []);

                    return $row;
                });
            }
            $data['tables'][] = ['key' => $key, 'page' => $page, 'filter' => $filter];
        }

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('Operations', $data);
    }

    /**
     * Search and status filter for one table, run on the server so they cover every page.
     * Each table reads its own "{key}_q" and "{key}_status" from the address.
     *
     * @return array{searchable: bool, statuses: list<string>, q: string, status: string}
     */
    private function applyTableFilter(Request $request, string $key, Builder $query): array
    {
        $config = self::TableFilters[$key] ?? ['search' => [], 'status' => null, 'statuses' => []];
        $search = trim(Str::limit($request->string($key.'_q')->toString(), 100, ''));
        $status = $request->string($key.'_status')->toString();
        if ($search !== '' && $config['search'] !== []) {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $match) use ($config, $term): void {
                foreach ($config['search'] as $column) {
                    $match->orWhereRaw('('.$column.')::text ilike ?', [$term]);
                }
            });
        }
        if ($config['status'] !== null && in_array($status, $config['statuses'], true)) {
            $query->where($config['status'], $status);
        }

        return [
            'searchable' => $config['search'] !== [],
            'statuses' => $config['status'] !== null ? $config['statuses'] : [],
            'q' => $config['search'] !== [] ? $search : '',
            'status' => in_array($status, $config['statuses'], true) ? $status : '',
        ];
    }
}
