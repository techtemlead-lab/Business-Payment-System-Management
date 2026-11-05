<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Staff\TerritoryAccess;
use App\Models\User;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportData
{
    public const Types = ['orders', 'products', 'product-summary', 'category-summary', 'district-summary', 'commissions', 'settlements', 'payroll', 'withdrawals', 'ledger', 'rewards', 'rank-achievements', 'rank-incentives', 'refunds'];

    /** Money still to collect on one order; never negative. */
    private const OutstandingSql = 'GREATEST(o.total-o.due_credit-o.paid, 0)';

    /** @return array{query: Builder, columns: list<string>, money: list<string>} */
    public function query(User $actor, string $type, string $from, string $to): array
    {
        $company = $actor->allows('finance') || $actor->allows('reports');
        $sales = $actor->allows('field_sales');
        if (! $company && $sales && $type === 'orders') {
            abort_unless($actor->allows('sr_orders'), 403);
        }
        abort_unless($company || ($sales && $type === 'orders') || (! $actor->staff() && in_array($type, ['orders', 'commissions', 'withdrawals'], true)) || $type === 'payroll', 403);
        [$query, $columns, $money, $date, $owner] = match ($type) {
            // No goods on credit any more: the orders export shows what was paid and what is still to pay before approval.
            'orders' => [DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id'),
                ['o.number', 'u.dealer_code', 'u.name', 'u.phone', 'u.district', 'u.division', 'o.status', 'o.total', 'o.paid', DB::raw(self::OutstandingSql.' AS outstanding'), 'o.created_at'],
                ['total', 'paid', 'outstanding'], 'o.created_at', 'o.user_id'],
            'products' => [DB::table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')->join('products as p', 'p.id', '=', 'l.product_id')->where('o.status', 'delivered'),
                ['o.number', DB::raw('COALESCE(l.category_snapshot,p.category,'.$this->uncategorisedSql().') AS category'), 'l.sku', 'l.name', 'l.quantity', 'l.dispatched', 'l.unit_price', 'l.total', 'o.status'], ['unit_price', 'total'], 'o.created_at', 'o.user_id'],
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
            'ledger' => [DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')->leftJoin('users as u', 'u.id', '=', 'l.user_id'),
                ['j.event_key', 'j.description', 'u.name', 'l.account', 'l.debit', 'l.credit', 'j.created_at'], ['debit', 'credit'], 'j.created_at', 'l.user_id'],
            'rewards' => [DB::table('reserve_disbursements as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id'),
                ['r.id', 'u.dealer_code', 'u.name', 'r.payee_name', 'r.program', 'r.amount', 'r.status', 'r.payment_reference', 'r.created_at'], ['amount'], 'r.created_at', 'r.user_id'],
            'refunds' => [DB::table('commission_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'o.user_id'),
                ['f.id', 'o.number', 'u.name', 'f.reference', 'f.amount', 'f.reason', 'f.created_at'], ['amount'], 'f.created_at', 'o.user_id'],
        };
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

        return ['query' => $query, 'columns' => $names, 'money' => $money];
    }

    /** @return array{Builder, array<mixed>, list<string>, string, string} */
    private function salesSummary(string $type): array
    {
        $category = 'COALESCE(l.category_snapshot,p.category,'.$this->uncategorisedSql().')';
        $query = DB::table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')->join('products as p', 'p.id', '=', 'l.product_id')->where('o.status', 'delivered');
        $query->groupByRaw($category);
        $columns = [DB::raw($category.' AS category')];
        if ($type === 'product-summary') {
            $query->groupBy('l.sku', 'l.name');
            $columns = [...$columns, 'l.sku', 'l.name'];
        }
        $columns = [...$columns, DB::raw('COUNT(DISTINCT o.id) AS order_count'), DB::raw('SUM(l.quantity) AS quantity'), DB::raw('SUM(l.total) AS gross_sales')];

        return [$query, $columns, ['gross_sales'], 'o.created_at', 'o.user_id'];
    }

    /**
     * Confirmed sales by division and district, like the location-wise sales
     * view Bangladeshi distribution software gives area managers.
     *
     * @return array{Builder, array<mixed>, list<string>, string, string}
     */
    private function districtSummary(): array
    {
        $unknown = DB::getPdo()->quote(__('portal.report_area_unknown'));
        $query = DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->whereIn('o.status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])
            ->groupByRaw("COALESCE(u.division, {$unknown}), COALESCE(u.district, {$unknown})");
        $columns = [
            DB::raw("COALESCE(u.division, {$unknown}) AS division"),
            DB::raw("COALESCE(u.district, {$unknown}) AS district"),
            DB::raw('COUNT(DISTINCT o.user_id) AS partner_count'),
            DB::raw('COUNT(o.id) AS order_count'),
            DB::raw('SUM(o.total) AS gross_sales'),
            DB::raw('SUM(o.paid) AS paid'),
            DB::raw('SUM(GREATEST(o.total-o.due_credit-o.paid, 0)) AS outstanding'),
        ];

        return [$query, $columns, ['gross_sales', 'paid', 'outstanding'], 'o.created_at', 'o.user_id'];
    }

    private function uncategorisedSql(): string
    {
        return DB::getPdo()->quote(__('portal.report_uncategorised'));
    }
}
