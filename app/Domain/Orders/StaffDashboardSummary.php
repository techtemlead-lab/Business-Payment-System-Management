<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\BranchScope;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the staff / owner home screen: what needs action today and how
 * sales moved over the last two weeks. Every block respects the viewer's
 * capabilities so staff only see work they are allowed to do.
 */
final class StaffDashboardSummary
{
    public const TrendDays = 14;

    /**
     * @param  BranchScope|null  $branchScope  The branch the top-bar switcher picked; null counts every branch.
     * @return array{
     *     work_queue: list<array{key: string, count: int, section: string, query?: array<string, string>}>,
     *     trend: list<array{date: string, orders: int, value: string}>,
     *     month: array{sales: string, collected: string, collected_today: string, orders: int}|null
     * }
     */
    public function for(User $viewer, ?BranchScope $branchScope = null): array
    {
        $commerce = $viewer->allows('orders') || $viewer->allows('finance');

        return [
            'work_queue' => $this->workQueue($viewer, $branchScope),
            'trend' => $commerce ? $this->trend($branchScope) : [],
            'month' => $commerce ? $this->month($branchScope) : null,
        ];
    }

    /**
     * @return list<array{key: string, count: int, section: string, query?: array<string, string>}>
     */
    private function workQueue(User $viewer, ?BranchScope $branchScope): array
    {
        $queue = [];

        if ($viewer->allows('order_review')) {
            $queue[] = [
                'key' => 'orders_to_review',
                'count' => $this->orders($branchScope)->whereIn('status', ['submitted', 'held'])->count(),
                'section' => 'orders',
                'query' => ['status' => 'submitted'],
            ];
        }
        if ($viewer->allows('finance')) {
            $queue[] = [
                'key' => 'payments_to_check',
                'count' => (int) $this->onScopedOrders(DB::table('payment_claims'), $branchScope)->where('status', 'pending')->count(),
                'section' => 'payments',
            ];
            $queue[] = [
                'key' => 'withdrawals_to_review',
                'count' => (int) $this->withdrawalsOf($branchScope)->where('withdrawals.status', 'requested')->count(),
                'section' => 'payouts-hub',
            ];
        }
        $deliveryCounts = $this->deliveryCounts($viewer, $branchScope);
        if ($viewer->allows('inventory')) {
            $queue[] = [
                'key' => 'orders_to_dispatch',
                'count' => $deliveryCounts['to_dispatch'],
                'section' => 'deliveries',
            ];
        }
        if (OverdueDeliveries::visibleTo($viewer)) {
            $queue[] = [
                'key' => 'deliveries_overdue',
                'count' => $deliveryCounts['overdue'],
                'section' => 'deliveries',
                'query' => ['status' => OverdueDeliveries::Filter],
            ];
        }
        if ($viewer->allows('inventory')) {
            $queue[] = [
                'key' => 'low_stock_products',
                'count' => $this->lowStockProducts($branchScope)->count(),
                'section' => 'inventory',
            ];
        }
        if ($viewer->allows('accounts')) {
            $queue[] = [
                'key' => 'applications_to_review',
                'count' => (int) DB::table('dealer_applications')->where('status', 'pending')->count(),
                'section' => 'partnerships',
            ];
        }

        return $queue;
    }

    /**
     * Active products at or under their low-stock mark: company stock on All branches,
     * and the branch's own stock and mark when one branch is picked.
     *
     * @return Builder<Product>
     */
    public function lowStockProducts(?BranchScope $branchScope): Builder
    {
        $products = Product::query()->where('products.active', true);
        if ($branchScope === null || $branchScope->allBranches) {
            return $products->whereColumn('products.stock', '<=', 'products.low_stock_at')
                ->select(['products.id', 'products.name', 'products.stock', 'products.sku']);
        }
        $products->join('branch_product_stocks', 'branch_product_stocks.product_id', '=', 'products.id')
            ->whereColumn('branch_product_stocks.quantity', '<=', 'branch_product_stocks.low_stock_at')
            ->select(['products.id', 'products.name', 'branch_product_stocks.quantity as stock', 'products.sku']);
        $branchScope->restrict($products, 'branch_product_stocks.branch_id');

        return $products;
    }

    /**
     * Orders still to send and orders sent but not marked delivered, counted in one query
     * so the home screen does not grow a query for the overdue list.
     *
     * @return array{to_dispatch: int, overdue: int}
     */
    private function deliveryCounts(User $viewer, ?BranchScope $branchScope): array
    {
        if (! $viewer->allows('inventory') && ! OverdueDeliveries::visibleTo($viewer)) {
            return ['to_dispatch' => 0, 'overdue' => 0];
        }
        $awaiting = "'".implode("', '", OrderListFilter::AwaitingDispatch)."'";
        $row = $this->orders($branchScope)->toBase()
            ->whereIn('status', OrderListFilter::AwaitingDispatch)
            ->selectRaw(
                "COUNT(*) FILTER (WHERE orders.status IN ({$awaiting}) AND EXISTS (SELECT 1 FROM order_lines WHERE order_lines.order_id = orders.id AND order_lines.dispatched < order_lines.quantity)) AS to_dispatch, "
                .'COUNT(*) FILTER (WHERE '.OverdueDeliveries::conditionSql().') AS overdue',
                OverdueDeliveries::bindings(),
            )
            ->first();

        return ['to_dispatch' => (int) ($row->to_dispatch ?? 0), 'overdue' => (int) ($row->overdue ?? 0)];
    }

    /**
     * Daily confirmed sales for the last {@see TrendDays} days in business time.
     *
     * @return list<array{date: string, orders: int, value: string}>
     */
    private function trend(?BranchScope $branchScope): array
    {
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $today->subDays(self::TrendDays - 1);

        $rows = $this->orders($branchScope)
            ->whereIn('status', OrderListFilter::Confirmed)
            ->where('created_at', '>=', $start->utc())
            ->selectRaw('(created_at at time zone \'UTC\' at time zone ?)::date as day, count(*) as orders, coalesce(sum(total), 0) as value', [$timezone])
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $trend = [];
        for ($offset = 0; $offset < self::TrendDays; $offset++) {
            $day = $start->addDays($offset)->toDateString();
            $row = $rows->get($day);
            $trend[] = [
                'date' => $day,
                'orders' => (int) ($row->orders ?? 0),
                'value' => Money::decimal(Money::cents(number_format((float) ($row->value ?? 0), 2, '.', ''))),
            ];
        }

        return $trend;
    }

    /**
     * Sales and money received this month. Money received today comes from the same
     * receipts query, so the home screen's "today" card costs no extra query.
     *
     * @return array{sales: string, collected: string, collected_today: string, orders: int}
     */
    private function month(?BranchScope $branchScope): array
    {
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $now = CarbonImmutable::now($timezone);
        $from = $now->startOfMonth()->utc();
        $confirmed = $this->orders($branchScope)->whereIn('status', OrderListFilter::Confirmed)->where('created_at', '>=', $from);
        $collected = $this->onScopedOrders(DB::table('receipts'), $branchScope)
            ->where('created_at', '>=', $from)
            ->selectRaw(
                'COALESCE(SUM(amount), 0) AS month_total, COALESCE(SUM(amount) FILTER (WHERE created_at >= ?), 0) AS today_total',
                [$now->startOfDay()->utc()],
            )
            ->first();

        return [
            'sales' => Money::decimal(Money::cents((string) ((clone $confirmed)->sum('total') ?: '0.00'))),
            'collected' => Money::decimal(Money::cents((string) $collected->month_total)),
            'collected_today' => Money::decimal(Money::cents((string) $collected->today_total)),
            'orders' => (clone $confirmed)->count(),
        ];
    }

    /**
     * Orders of the picked branch, or every order when no branch was picked.
     *
     * @return Builder<Order>
     */
    private function orders(?BranchScope $branchScope): Builder
    {
        $orders = Order::query();
        $branchScope?->restrict($orders, 'orders.fulfillment_branch_id');

        return $orders;
    }

    /**
     * Rows tied to an order (payment claims, receipts) of the picked branch only.
     */
    private function onScopedOrders(QueryBuilder $rows, ?BranchScope $branchScope): QueryBuilder
    {
        if ($branchScope !== null && ! $branchScope->allBranches) {
            $rows->whereIn('order_id', $this->orders($branchScope)->select('orders.id'));
        }

        return $rows;
    }

    /**
     * Withdrawals of the picked branch's partners only.
     */
    private function withdrawalsOf(?BranchScope $branchScope): QueryBuilder
    {
        $withdrawals = DB::table('withdrawals');
        if ($branchScope !== null && ! $branchScope->allBranches) {
            $withdrawals->join('users', 'users.id', '=', 'withdrawals.user_id');
            $branchScope->restrictPartners($withdrawals);
        }

        return $withdrawals;
    }
}
