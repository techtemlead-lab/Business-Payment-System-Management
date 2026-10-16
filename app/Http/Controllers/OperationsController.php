<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\ReserveService;
use App\Domain\Staff\TerritoryAccess;
use App\Models\CommissionAllocation;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OperationsController extends Controller
{
    public const Sections = ['field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports'];

    public function index(Request $request, string $section): Response|JsonResponse
    {
        $actor = $request->user();
        $capability = match ($section) {
            'field-sales' => 'field_sales', 'staff-operations' => 'staff_admin', 'payroll' => 'payroll', 'my-payroll' => null,
            'financial-reports' => $actor->allows('finance') ? 'finance' : ($actor->allows('field_sales') ? 'field_sales' : 'reports'), default => 'finance'
        };
        abort_unless(in_array($section, self::Sections, true) && $actor->staff() && ($capability === null || $actor->allows($capability)), 403);
        $tables = [];
        $data = ['section' => $section, 'tables' => [], 'paymentAccounts' => [], 'partners' => [], 'staff' => [], 'summary' => []];
        $staff = User::whereIn('role', ['employee', ...array_keys(config('madina.roles'))])->orderBy('name')->get(['id', 'name', 'role', 'status', 'profile']);
        if ($actor->allows('staff_admin') || $actor->allows('finance')) {
            $data['staff'] = $staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'field_sales' => $u->allows('field_sales')])->all();
        }
        if (in_array($section, ['policy-schedules', 'reserve-payouts', 'field-sales'], true)) {
            $partners = $section === 'field-sales' ? app(TerritoryAccess::class)->partners($actor) : User::whereIn('role', ['dealer', 'sub_dealer']);
            $data['partners'] = $partners->when($request->filled('q'), fn ($q) => $q->where('name', 'ilike', '%'.$request->string('q').'%'))
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
            $tables['Territories'] = DB::table('staff_territories as t')->join('users as u', 'u.id', '=', 't.staff_id')->select(['t.*', 'u.name'])->orderByDesc('t.id');
            $tables['Monthly targets'] = DB::table('staff_targets as t')->join('users as u', 'u.id', '=', 't.staff_id')->select(['t.id', 't.staff_id', 'u.name', 't.period_month', 't.target', 't.bonus', 't.version'])->orderByDesc('t.period_month');
        }
        if ($section === 'field-sales') {
            $territories = app(TerritoryAccess::class);
            $data['regions'] = $territories->territories($actor->id);
            $data['products'] = Product::where('active', true)->where('is_stock_paused', false)->orderBy('name')->get(['id', 'name', 'sku']);
            $scope = $territories->partners($actor)->select('users.id');
            if ($actor->allows('sr_orders') || $actor->allows('sr_dues')) {
                $columns = ['o.id', 'o.number', 'u.name', 'o.status', 'o.total', 'o.submitted_by'];
                if ($actor->allows('sr_dues')) {
                    $columns = [...$columns, 'o.paid', DB::raw('o.total-o.paid AS outstanding')];
                }
                $tables['Territory orders and dues'] = DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')->whereIn('o.user_id', $scope)->select($columns)->orderByDesc('o.created_at');
            }
            $tables['My targets'] = DB::table('staff_targets')->where('staff_id', $actor->id)->orderByDesc('period_month');
            $data['summary'] = ['Current month net territory sales' => Money::decimal($territories->volume($actor->id, now()->timezone('Asia/Dhaka')->format('Y-m')))];
        }
        if (in_array($section, ['field-sales', 'staff-operations'], true)) {
            $tables['Dealer applications'] = DB::table('dealer_applications')->when(! $actor->allows('accounts'), fn ($q) => $q->where('submitted_by', $actor->id))
                ->select(['id', 'email', 'data', 'submitted_by', 'status', 'user_id', 'reason', 'created_at'])->orderByDesc('created_at');
        }
        if (in_array($section, ['payroll', 'my-payroll'], true)) {
            $tables['Salary register'] = DB::table('payroll_entries as p')->join('users as u', 'u.id', '=', 'p.staff_id')
                ->when($section === 'my-payroll', fn ($q) => $q->where('staff_id', $actor->id))
                ->select(['p.id', 'u.name', 'p.staff_id', 'p.period_month', 'p.gross', 'p.deduction', 'p.net', 'p.snapshot', 'p.status', 'p.prepared_by', 'p.approved_by', 'p.payment_reference', 'p.paid_at', DB::raw('p.receipt_path IS NOT NULL AS has_receipt')])->orderByDesc('p.created_at');
            if ($actor->allows('finance') && $section === 'payroll') {
                $tables['Effective salary contracts'] = DB::table('staff_contracts as c')->join('users as u', 'u.id', '=', 'c.staff_id')->select(['c.id', 'u.name', 'c.effective_month', 'c.revision', 'c.base_salary', 'c.allowance', 'c.deduction', 'c.reason'])->orderByDesc('c.effective_month')->orderByDesc('c.id');
            }
        }
        if ($section === 'policy-schedules') {
            $tables['Policy schedules'] = DB::table('commission_policy_schedules as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')
                ->select(['s.*', 'u.name'])->orderByDesc('s.id');
            $data['policy'] = app(CommissionPolicy::class)->snapshot();
        }
        if ($section === 'reserve-payouts') {
            $tables['Reward and expense requests'] = DB::table('reserve_disbursements as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->select(['r.id', 'r.program', 'u.name', 'r.user_id', 'r.payee_name', 'r.amount', 'r.status', 'r.created_by', 'r.approved_by', 'r.payment_reference', 'r.reason', DB::raw('r.receipt_path IS NOT NULL AS has_receipt')])->orderByDesc('r.id');
            $tables['Company reserve clawbacks'] = DB::table('reserve_expense_clawbacks')->orderByDesc('id');
            $tables['Clawback recoveries'] = DB::table('reserve_recoveries')->select(['id', 'clawback_id', 'amount', 'payment_reference', 'paid_at', DB::raw('true AS has_receipt')])->orderByDesc('id');
            foreach (ReserveService::Programs as $program => $category) {
                $total = CommissionAllocation::where('category', $category)->get()->sum(fn ($fund) => app(ReserveService::class)->remaining($fund));
                $data['summary'][$program.' uncommitted reserve'] = ($total < 0 ? '-' : '').Money::decimal(abs($total));
            }
        }
        if ($section === 'commission-holds') {
            $tables['Held allocations'] = DB::table('commission_allocations as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.status', 'held')
                ->select(['a.id', 'u.name', 'u.status as partner_status', 'a.order_id', 'a.category', 'a.amount', 'a.metadata'])->orderBy('a.id');
        }
        if ($section === 'financial-readiness') {
            $data['readiness'] = app(FinancialReadiness::class)->inspect();
            $tables['Opening balance reviews'] = DB::table('financial_verifications')->select(['id', 'fingerprint', 'source_reference', 'created_by', 'verified_by', 'verified_at', 'created_at'])->orderByDesc('id');
        }
        foreach ($tables as $title => $query) {
            $data['tables'][] = ['title' => $title, 'page' => $query->paginate(20, ['*'], 'page_'.count($data['tables']))->withQueryString()];
        }

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('Operations', $data);
    }
}
