<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\SellableStock;
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
     *     month: array{sales: string, delivered_today: string, orders: int}|null
     * }
     */
    public function for(User $viewer, ?BranchScope $branchScope = null): array
    {
        $workQueue = $this->workQueue($viewer, $branchScope);
        if (! $viewer->allows('orders') && ! $viewer->allows('finance')) {
            return ['work_queue' => $workQueue, 'trend' => [], 'month' => null];
        }
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $delivered = $this->deliveredByDay($today, $timezone, $branchScope);

        return [
            'work_queue' => $workQueue,
            'trend' => $this->trend($delivered, $today),
            'month' => $this->month($delivered, $today),
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
     * Active products at or under their low-stock mark, counting only units that can really be
     * sent: the staff use this list to act before dealers run out, and units on hold or past
     * their date will not fill an order. On All branches that is the company's sellable stock;
     * with one branch picked, that branch's own usable stock and mark.
     *
     * @return Builder<Product>
     */
    public function lowStockProducts(?BranchScope $branchScope): Builder
    {
        $products = Product::query()->where('products.active', true);
        if ($branchScope === null || $branchScope->allBranches) {
            return $products->whereRaw(SellableStock::sql().' <= products.low_stock_at', SellableStock::bindings())
                ->select(['products.id', 'products.name', 'products.sku'])
                ->selectRaw('CAST('.SellableStock::sql().' AS integer) AS stock', SellableStock::bindings());
        }
        $products->join('branch_product_stocks', 'branch_product_stocks.product_id', '=', 'products.id')
            ->whereRaw(SellableStock::branchSql().' <= branch_product_stocks.low_stock_at', SellableStock::branchBindings())
            ->select(['products.id', 'products.name', 'products.sku'])
            ->selectRaw('CAST('.SellableStock::branchSql().' AS integer) AS stock', SellableStock::branchBindings());
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
     * What was delivered on each business day, from the earlier of the trend window and the
     * start of this month, so the chart, the month and today cost one query and cannot disagree.
     * The owner's rule: goods count once they arrive, on the day the last parcel arrived, the
     * same day the management figures use. Imported opening-balance orders are left out, since
     * nothing was delivered on the day they were imported.
     *
     * @return array<string, array{orders: int, cents: int}>
     */
    private function deliveredByDay(CarbonImmutable $today, string $timezone, ?BranchScope $branchScope): array
    {
        $from = $today->subDays(self::TrendDays - 1)->min($today->startOfMonth())->utc();
        $deliveredAt = "COALESCE((SELECT MAX(parcel.delivered_at) FROM shipments AS parcel WHERE parcel.order_id = orders.id AND parcel.status = 'delivered'), orders.updated_at)";

        $days = [];
        $rows = $this->orders($branchScope)->toBase()
            ->whereRaw(DeliveredMoney::countedSql())
            ->whereRaw('orders.is_opening_balance IS NOT TRUE')
            ->where('orders.updated_at', '>=', $from)
            ->whereRaw("{$deliveredAt} >= ?", [$from])
            ->selectRaw("({$deliveredAt} at time zone 'UTC' at time zone ?)::date AS day, COUNT(*) AS orders, COALESCE(SUM(".DeliveredMoney::valueSql().'), 0) AS value', [$timezone])
            ->groupBy('day')
            ->get();
        foreach ($rows as $row) {
            $days[(string) $row->day] = ['orders' => (int) $row->orders, 'cents' => Money::cents((string) $row->value)];
        }

        return $days;
    }

    /**
     * Goods delivered on each of the last {@see TrendDays} days in business time.
     *
     * @param  array<string, array{orders: int, cents: int}>  $delivered
     * @return list<array{date: string, orders: int, value: string}>
     */
    private function trend(array $delivered, CarbonImmutable $today): array
    {
        $trend = [];
        for ($daysAgo = self::TrendDays - 1; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo)->toDateString();
            $trend[] = [
                'date' => $day,
                'orders' => $delivered[$day]['orders'] ?? 0,
                'value' => Money::decimal($delivered[$day]['cents'] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * Goods delivered this month and today. Money paid in advance counts for nothing here until
     * the goods arrive.
     *
     * @param  array<string, array{orders: int, cents: int}>  $delivered
     * @return array{sales: string, delivered_today: string, orders: int}
     */
    private function month(array $delivered, CarbonImmutable $today): array
    {
        $firstOfMonth = $today->startOfMonth()->toDateString();
        $thisMonth = array_filter($delivered, fn (string $day): bool => $day >= $firstOfMonth, ARRAY_FILTER_USE_KEY);

        return [
            'sales' => Money::decimal(array_sum(array_column($thisMonth, 'cents'))),
            'delivered_today' => Money::decimal($delivered[$today->toDateString()]['cents'] ?? 0),
            'orders' => array_sum(array_column($thisMonth, 'orders')),
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
        $branchScope?->restrictOrders($orders);

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
