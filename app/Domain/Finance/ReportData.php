<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderListFilter;
use App\Domain\Staff\TerritoryAccess;
use App\Models\User;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportData
{
    public const Types = ['orders', 'products', 'product-summary', 'category-summary', 'district-summary', 'commissions', 'settlements', 'payroll', 'withdrawals', 'ledger', 'rewards', 'rank-achievements', 'rank-incentives', 'refunds', 'order-refunds', 'collections', 'tds-register', 'fund-spending'];

    /** Money still to collect on one order; never negative. */
    private const OutstandingSql = 'GREATEST(o.total-o.due_credit-o.paid, 0)';

    /**
     * Reports whose rows belong to one dealer or sub-dealer (joined as `u`), so they can be
     * narrowed to dealers only or sub-dealers only (contract S04).
     */
    public const AccountTypeReports = ['orders', 'commissions', 'settlements', 'withdrawals', 'rewards', 'rank-achievements', 'rank-incentives', 'refunds', 'collections', 'tds-register', 'order-refunds'];

    /** Reports made of order rows (orders are always "o" in their queries), so they can be limited to a branch. */
    public const OrderBasedReports = ['orders', 'products', 'product-summary', 'category-summary', 'district-summary', 'collections', 'order-refunds', 'refunds'];

    /** Partner kinds a report can be narrowed to. */
    public const AccountTypes = ['dealer', 'sub_dealer'];

    /**
     * What each report's total line adds up (owner's decision: only money actually paid or
     * received). Only these columns, and with `statuses` only rows in those statuses; every
     * other row stays listed. Reports without `statuses` list only money that already moved.
     *
     * @var array<string, array{columns: list<string>, statuses?: list<string>}>
     */
    private const PaidTotals = [
        'orders' => ['columns' => ['delivered_value']],
        'products' => ['columns' => ['total']],
        'product-summary' => ['columns' => ['gross_sales']],
        'category-summary' => ['columns' => ['gross_sales']],
        'district-summary' => ['columns' => ['paid']],
        'commissions' => ['columns' => ['amount'], 'statuses' => ['available']],
        'settlements' => ['columns' => ['amount', 'reversed_amount']],
        'payroll' => ['columns' => ['gross', 'deduction', 'net'], 'statuses' => ['paid']],
        'withdrawals' => ['columns' => ['amount', 'withheld_amount', 'net_amount'], 'statuses' => ['paid']],
        'ledger' => ['columns' => ['debit', 'credit']],
        'rewards' => ['columns' => ['amount'], 'statuses' => ['credited', 'paid']],
        'rank-achievements' => ['columns' => []],
        'rank-incentives' => ['columns' => ['amount', 'reversed_amount', 'net_award']],
        'collections' => ['columns' => ['amount'], 'statuses' => ['verified']],
        'tds-register' => ['columns' => ['amount', 'withheld_amount', 'net_amount']],
        'fund-spending' => ['columns' => ['amount']],
        'order-refunds' => ['columns' => ['amount']],
        'refunds' => ['columns' => ['amount']],
    ];

    /**
     * Whether a listed row's money belongs in the report's total line.
     *
     * @param  array{counted_statuses: ?list<string>}  $report
     */
    public static function countsTowardTotal(array $report, object $row): bool
    {
        return $report['counted_statuses'] === null || in_array($row->status ?? null, $report['counted_statuses'], true);
    }

    /**
     * The files this person is offered in the Report Centre, in display order.
     *
     * @return list<string>
     */
    public function availableTo(User $actor): array
    {
        if (! $actor->allows('finance') && ! $actor->allows('reports')) {
            return ['orders', 'payroll'];
        }

        return array_values(array_filter(self::Types, fn (string $type): bool => $this->mayRead($actor, $type)));
    }

    /**
     * Files not built from orders have no branch, so they are company-wide. Of the people
     * who read company reports, only those who may view every branch can have them.
     */
    private function mayRead(User $actor, string $type): bool
    {
        if ($type === 'fund-spending' && ! FundSpending::canManage($actor)) {
            return false;
        }
        if ($type === 'payroll' && ! $actor->allows('payroll')) {
            return false;
        }

        return in_array($type, self::OrderBasedReports, true) || app(BranchOperations::class)->canViewAllBranches($actor);
    }

    /**
     * @param  array{fund?: ?string, head?: ?int}  $filters  Only the fund-spending report uses these.
     * @return array{query: Builder, columns: list<string>, money: list<string>, totals: list<string>, counted_statuses: ?list<string>}
     */
    public function query(User $actor, string $type, string $from, string $to, ?string $accountType = null, array $filters = []): array
    {
        $company = $actor->allows('finance') || $actor->allows('reports');
        $sales = $actor->allows('field_sales');
        if (! $company && $sales && $type === 'orders') {
            abort_unless($actor->allows('sr_orders'), 403);
        }
        abort_unless($company || ($sales && $type === 'orders') || (! $actor->staff() && in_array($type, ['orders', 'commissions', 'withdrawals'], true)) || $type === 'payroll', 403);
        abort_if($company && ! $this->mayRead($actor, $type), 403);
        [$query, $columns, $money, $date, $owner] = match ($type) {
            // No goods on credit any more: the orders export shows what was paid and what is still to pay before approval.
            'orders' => [DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')
                ->leftJoinSub(DB::table('order_refunds')->selectRaw('order_id, SUM(amount) AS given_back')->groupBy('order_id'), 'refund', 'refund.order_id', '=', 'o.id'),
                ['o.number', 'u.dealer_code', 'u.name', 'u.phone', 'u.district', 'u.division', 'o.status', 'o.total', 'o.paid', DB::raw(self::OutstandingSql.' AS outstanding'), DB::raw('(o.paid-COALESCE(refund.given_back,0)) AS net_received'), DB::raw(DeliveredMoney::valueSql('o').' AS delivered_value'), 'o.created_at'],
                ['total', 'paid', 'outstanding', 'net_received', 'delivered_value'], 'o.created_at', 'o.user_id'],
            'products' => [$this->deliveredLines(),
                ['o.number', DB::raw('COALESCE(l.category_snapshot,p.category,'.$this->uncategorisedSql().') AS category'), 'l.sku', 'l.name', DB::raw(DeliveredMoney::lineQuantitySql().' AS quantity'), 'l.dispatched', 'l.unit_price', DB::raw(DeliveredMoney::lineValueSql().' AS total'), 'o.status'], ['unit_price', 'total'], 'o.created_at', 'o.user_id'],
            'product-summary', 'category-summary' => $this->salesSummary($type),
            'district-summary' => $this->districtSummary(),
            'rank-achievements' => [DB::table('dealer_ranks as r')->join('users as u', 'u.id', '=', 'r.user_id')
                ->crossJoin(DB::raw("LATERAL json_array_elements(CASE WHEN json_array_length(COALESCE(r.qualification_history,'[]'::json))>0 THEN r.qualification_history ELSE json_build_array(json_build_object('rank',r.current_rank,'achieved_at',r.achieved_at)) END) AS h(value)"))->whereRaw("h.value->>'rank' <> 'dealer'"),
                ['u.name', 'u.dealer_code', DB::raw("h.value->>'rank' AS rank"), DB::raw("(h.value->>'achieved_at')::timestamptz AS achieved_at")], [], DB::raw("((h.value->>'achieved_at')::timestamptz AT TIME ZONE 'UTC')"), 'r.user_id'],
            'rank-incentives' => [DB::table('rank_incentive_awards as r')->join('users as u', 'u.id', '=', 'r.user_id')
                ->leftJoinSub(DB::table('commission_settlement_payouts')->where('kind', 'like', 'rank_%')->selectRaw('user_id,kind,SUM(reversed_amount) AS reversed_amount')->groupBy('user_id', 'kind'), 'p', fn ($join) => $join->on('p.user_id', '=', 'r.user_id')->whereRaw("p.kind='rank_' || r.rank")),
                ['r.id', 'u.name', 'u.dealer_code', 'r.rank', 'r.amount', DB::raw('COALESCE(p.reversed_amount,0) AS reversed_amount'), DB::raw('(r.amount-COALESCE(p.reversed_amount,0)) AS net_award'), 'r.created_at'], ['amount', 'reversed_amount', 'net_award'], 'r.created_at', 'r.user_id'],
            'commissions' => [DB::table('commission_allocations as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('orders as o', 'o.id', '=', 'a.order_id')
                ->when(! ($actor->owner() && PortalHost::current() === 'sadmin'), fn ($q) => $q->where('a.category', '!=', RetainedFundLedger::BenefitsCategory)),
                ['a.id', 'o.number', 'u.dealer_code', 'u.name', 'a.category', 'a.rate_percentage', 'a.cash_basis', 'a.original_amount', 'a.amount', 'a.status', 'a.created_at'], ['cash_basis', 'original_amount', 'amount'], 'a.created_at', 'a.user_id'],
            'settlements' => [DB::table('commission_settlement_payouts as p')->join('users as u', 'u.id', '=', 'p.user_id'),
                ['p.id', 'u.dealer_code', 'u.name', 'p.kind', 'p.period_month', 'p.amount', 'p.reversed_amount', 'p.created_at'], ['amount', 'reversed_amount'], 'p.created_at', 'p.user_id'],
            'payroll' => [DB::table('payroll_entries as p')->join('users as u', 'u.id', '=', 'p.staff_id'),
                ['p.id', 'u.name', 'p.period_month', 'p.gross', 'p.deduction', 'p.net', 'p.status', 'p.payment_reference', 'p.paid_at'], ['gross', 'deduction', 'net'], 'p.created_at', 'p.staff_id'],
            'withdrawals' => [DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id'),
                ['w.id', 'w.created_at as requested_at', 'u.dealer_code', 'u.name', 'u.phone', 'w.amount', 'w.withheld_amount', 'w.net_amount', 'w.channel', 'w.status', 'w.payment_reference', 'w.paid_at'], ['amount', 'withheld_amount', 'net_amount'], 'w.created_at', 'w.user_id'],
            'ledger' => [DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->when(! ($actor->owner() && PortalHost::current() === 'sadmin'), fn ($q) => CompanyFunds::withoutOwnerOnlySpends($q, 'j.event_key')),
                ['j.event_key', 'j.description', 'u.name', 'l.account', 'l.debit', 'l.credit', 'j.created_at'], ['debit', 'credit'], 'j.created_at', 'l.user_id'],
            'rewards' => [DB::table('reserve_disbursements as r')->where('r.program', '!=', FundSpending::Program)->leftJoin('users as u', 'u.id', '=', 'r.user_id'),
                ['r.id', 'u.dealer_code', 'u.name', 'r.payee_name', 'r.program', 'r.amount', 'r.status', 'r.payment_reference', 'r.created_at'], ['amount'], 'r.created_at', 'r.user_id'],
            // Every payment a partner sent with its TrxID, who checked it and when: the daily collection sheet.
            'collections' => [DB::table('payment_claims as c')->join('orders as o', 'o.id', '=', 'c.order_id')->join('users as u', 'u.id', '=', 'o.user_id')
                ->leftJoin('users as checker', 'checker.id', '=', 'c.verified_by'),
                ['c.created_at', 'o.number', 'u.dealer_code', 'u.name', 'u.phone', 'u.district', 'u.division', 'c.channel', 'c.reference', 'c.amount', 'c.status',
                    DB::raw('checker.name AS checked_by'), DB::raw("CASE WHEN c.status <> 'pending' THEN c.updated_at END AS checked_at")],
                ['amount'], 'c.created_at', 'o.user_id'],
            // Tax cut (TDS) on money sent to partners, for the tax return.
            'fund-spending' => [CompanyFunds::paidSpendRows(['from' => $from, 'to' => $to, ...$filters], $actor->owner() && PortalHost::current() === 'sadmin'),
                [DB::raw('COALESCE(r.spent_on, r.paid_at::date) AS spent_on'), DB::raw("COALESCE(r.fund_key, 'marketing') AS fund"), DB::raw(app()->getLocale() === 'bn' ? 'COALESCE(NULLIF(h.name_bn, \'\'), h.name_en, \'\') AS head' : 'COALESCE(h.name_en, \'\') AS head'), DB::raw('r.payee_name AS payee'), 'r.payment_method', 'r.payment_reference', 'r.amount', 'r.reason', 'r.status'],
                // Cast to a time so the day-range check below counts the last day (a plain date would drop it).
                ['amount'], DB::raw('COALESCE(r.spent_on, r.paid_at::date)::timestamp'), 'r.created_by'],
            'tds-register' => [DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id')->where('w.status', 'paid'),
                ['w.paid_at', 'u.dealer_code', 'u.name', 'u.phone', 'u.role', 'w.amount', 'w.withheld_amount', 'w.net_amount', 'w.channel', 'w.payment_reference'],
                ['amount', 'withheld_amount', 'net_amount'], 'w.paid_at', 'w.user_id'],
            // Money sent back to dealers for paid orders that were never sent out.
            'order-refunds' => [DB::table('order_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'f.user_id')
                ->join('users as staff', 'staff.id', '=', 'f.refunded_by'),
                ['f.created_at', 'o.number', 'u.dealer_code', 'u.name', 'u.phone', 'f.paid_before', 'f.amount', 'f.reason', DB::raw('staff.name AS refunded_by')],
                ['paid_before', 'amount'], 'f.created_at', 'f.user_id'],
            'refunds' => [DB::table('commission_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'o.user_id'),
                ['f.id', 'o.number', 'u.name', 'f.reference', 'f.amount', 'f.reason', 'f.created_at'], ['amount'], 'f.created_at', 'o.user_id'],
        };
        if ($accountType !== null && in_array($accountType, self::AccountTypes, true) && in_array($type, self::AccountTypeReports, true)) {
            $query->where('u.role', $accountType);
        }
        $query->where($date, '>=', CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->utc())
            ->where($date, '<', CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->utc());
        if (! $company) {
            if ($sales && $type === 'orders') {
                $query->whereIn($owner, app(TerritoryAccess::class)->partners($actor)->select('users.id'));
            } else {
                $query->where($owner, $actor->id);
            }
        }
        $query->orderBy(match ($type) {
            'product-summary' => 'sku',
            'category-summary' => 'category',
            'district-summary' => 'gross_sales',
            default => $date,
        }, $type === 'district-summary' ? 'desc' : 'asc')->select($columns);
        $names = array_map(function ($column) use ($query): string {
            $sql = is_string($column) ? $column : $column->getValue($query->getGrammar());
            if (preg_match('/\sAS\s+([a-z0-9_]+)$/i', $sql, $match)) {
                return $match[1];
            }

            return substr($sql, strpos($sql, '.') + 1);
        }, $columns);

        return ['query' => $query, 'columns' => $names, 'money' => $money, 'totals' => self::PaidTotals[$type]['columns'], 'counted_statuses' => self::PaidTotals[$type]['statuses'] ?? null];
    }

    /** @return array{Builder, array<mixed>, list<string>, string, string} */
    private function salesSummary(string $type): array
    {
        $category = 'COALESCE(l.category_snapshot,p.category,'.$this->uncategorisedSql().')';
        $query = $this->deliveredLines();
        $query->groupByRaw($category);
        $columns = [DB::raw($category.' AS category')];
        if ($type === 'product-summary') {
            $query->groupBy('l.sku', 'l.name');
            $columns = [...$columns, 'l.sku', 'l.name'];
        }
        $columns = [...$columns, DB::raw('COUNT(DISTINCT o.id) AS order_count'), DB::raw('SUM('.DeliveredMoney::lineQuantitySql().') AS quantity'), DB::raw('SUM('.DeliveredMoney::lineValueSql().') AS gross_sales')];

        return [$query, $columns, ['gross_sales'], 'o.created_at', 'o.user_id'];
    }

    /**
     * Confirmed orders by division and district, like the location-wise sales
     * view Bangladeshi distribution software gives area managers. Sales and paid
     * count delivered goods only; a partly delivered order counts what arrived.
     *
     * @return array{Builder, array<mixed>, list<string>, string, string}
     */
    private function districtSummary(): array
    {
        $unknown = DB::getPdo()->quote(__('portal.report_area_unknown'));
        $query = DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->whereIn('o.status', OrderListFilter::Confirmed)
            ->groupByRaw("COALESCE(u.division, {$unknown}), COALESCE(u.district, {$unknown})");
        $columns = [
            DB::raw("COALESCE(u.division, {$unknown}) AS division"),
            DB::raw("COALESCE(u.district, {$unknown}) AS district"),
            DB::raw('COUNT(DISTINCT o.user_id) AS partner_count'),
            DB::raw('COUNT(o.id) AS order_count'),
            DB::raw('SUM('.DeliveredMoney::valueSql('o').') AS gross_sales'),
            DB::raw('SUM('.DeliveredMoney::paidSql('o').') AS paid'),
            DB::raw('SUM(GREATEST(o.total-o.due_credit-o.paid, 0)) AS outstanding'),
        ];

        return [$query, $columns, ['gross_sales', 'paid', 'outstanding'], 'o.created_at', 'o.user_id'];
    }

    /** Order lines with goods that reached the partner. A line that was not delivered is left out. */
    private function deliveredLines(): Builder
    {
        return DB::table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('o.status', DeliveredMoney::Statuses)
            ->whereRaw(DeliveredMoney::lineQuantitySql().' > 0');
    }

    private function uncategorisedSql(): string
    {
        return DB::getPdo()->quote(__('portal.report_uncategorised'));
    }
}
