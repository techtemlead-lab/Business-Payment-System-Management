<?php

namespace App\Domain\Finance;

use App\Domain\Staff\TerritoryAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportData
{
    public const Types = ['orders', 'products', 'dues', 'commissions', 'settlements', 'payroll', 'withdrawals', 'ledger', 'rewards', 'refunds'];

    /** @return array{query: Builder, columns: list<string>, money: list<string>} */
    public function query(User $actor, string $type, string $from, string $to): array
    {
        $company = $actor->allows('finance') || $actor->allows('reports');
        $sales = $actor->allows('field_sales');
        if (! $company && $sales && in_array($type, ['orders', 'dues'], true)) {
            abort_unless($actor->allows($type === 'dues' ? 'sr_dues' : 'sr_orders'), 403);
        }
        abort_unless($company || ($sales && in_array($type, ['orders', 'dues'], true)) || (! $actor->staff() && in_array($type, ['orders', 'dues', 'commissions', 'withdrawals'], true)) || $type === 'payroll', 403);
        [$query, $columns, $money, $date, $owner] = match ($type) {
            'orders', 'dues' => [DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')
                ->when($type === 'dues', fn ($q) => $q->whereColumn('o.paid', '<', 'o.total')->whereNotIn('o.status', ['cancelled', 'rejected'])),
                ['o.number', 'u.name', 'o.status', 'o.total', 'o.paid', DB::raw('(o.total-o.paid) AS outstanding'), 'o.created_at'], ['total', 'paid', 'outstanding'], 'o.created_at', 'o.user_id'],
            'products' => [DB::table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')->join('products as p', 'p.id', '=', 'l.product_id')->where('o.status', 'delivered'),
                ['o.number', 'p.category', 'l.sku', 'l.name', 'l.quantity', 'l.dispatched', 'l.unit_price', 'l.total', 'o.status'], ['unit_price', 'total'], 'o.created_at', 'o.user_id'],
            'commissions' => [DB::table('commission_allocations as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id'),
                ['a.id', 'a.order_id', 'u.name', 'a.category', 'a.rate_percentage', 'a.cash_basis', 'a.original_amount', 'a.amount', 'a.status', 'a.created_at'], ['cash_basis', 'original_amount', 'amount'], 'a.created_at', 'a.user_id'],
            'settlements' => [DB::table('commission_settlement_payouts as p')->join('users as u', 'u.id', '=', 'p.user_id'),
                ['p.id', 'u.name', 'p.kind', 'p.period_month', 'p.amount', 'p.reversed_amount', 'p.created_at'], ['amount', 'reversed_amount'], 'p.created_at', 'p.user_id'],
            'payroll' => [DB::table('payroll_entries as p')->join('users as u', 'u.id', '=', 'p.staff_id'),
                ['p.id', 'u.name', 'p.period_month', 'p.gross', 'p.deduction', 'p.net', 'p.status', 'p.payment_reference', 'p.paid_at'], ['gross', 'deduction', 'net'], 'p.created_at', 'p.staff_id'],
            'withdrawals' => [DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id'),
                ['w.id', 'u.name', 'w.amount', 'w.withheld_amount', 'w.net_amount', 'w.channel', 'w.status', 'w.payment_reference', 'w.paid_at'], ['amount', 'withheld_amount', 'net_amount'], 'w.created_at', 'w.user_id'],
            'ledger' => [DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')->leftJoin('users as u', 'u.id', '=', 'l.user_id'),
                ['j.event_key', 'j.description', 'u.name', 'l.account', 'l.debit', 'l.credit', 'j.created_at'], ['debit', 'credit'], 'j.created_at', 'l.user_id'],
            'rewards' => [DB::table('reserve_disbursements as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id'),
                ['r.id', 'u.name', 'r.payee_name', 'r.program', 'r.amount', 'r.status', 'r.payment_reference', 'r.created_at'], ['amount'], 'r.created_at', 'r.user_id'],
            'refunds' => [DB::table('commission_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'o.user_id'),
                ['f.id', 'o.number', 'u.name', 'f.reference', 'f.amount', 'f.reason', 'f.created_at'], ['amount'], 'f.created_at', 'o.user_id'],
        };
        $query->where($date, '>=', CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->utc())
            ->where($date, '<', CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->utc());
        if (! $company) {
            if ($sales && in_array($type, ['orders', 'dues'], true)) {
                $query->whereIn($owner, app(TerritoryAccess::class)->partners($actor)->select('users.id'));
            } else {
                $query->where($owner, $actor->id);
            }
        }
        $query->orderBy($date)->select($columns);
        $names = array_map(fn ($column): string => is_string($column) ? substr($column, strpos($column, '.') + 1) : 'outstanding', $columns);

        return ['query' => $query, 'columns' => $names, 'money' => $money];
    }
}
