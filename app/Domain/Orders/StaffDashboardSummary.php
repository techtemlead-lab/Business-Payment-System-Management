<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
     * @return array{
     *     work_queue: list<array{key: string, count: int, section: string, query?: array<string, string>}>,
     *     trend: list<array{date: string, orders: int, value: string}>,
     *     month: array{sales: string, collected: string, collected_today: string, orders: int}|null
     * }
     */
    public function for(User $viewer): array
    {
        $commerce = $viewer->allows('orders') || $viewer->allows('finance');

        return [
            'work_queue' => $this->workQueue($viewer),
            'trend' => $commerce ? $this->trend() : [],
            'month' => $commerce ? $this->month() : null,
        ];
    }

    /**
     * @return list<array{key: string, count: int, section: string, query?: array<string, string>}>
     */
    private function workQueue(User $viewer): array
    {
        $queue = [];

        if ($viewer->allows('order_review')) {
            $queue[] = [
                'key' => 'orders_to_review',
                'count' => Order::whereIn('status', ['submitted', 'held'])->count(),
                'section' => 'orders',
                'query' => ['status' => 'submitted'],
            ];
        }
        if ($viewer->allows('finance')) {
            $queue[] = [
                'key' => 'payments_to_check',
                'count' => (int) DB::table('payment_claims')->where('status', 'pending')->count(),
                'section' => 'payments',
            ];
            $queue[] = [
                'key' => 'withdrawals_to_review',
                'count' => (int) DB::table('withdrawals')->where('status', 'requested')->count(),
                'section' => 'withdrawals',
            ];
        }
        if ($viewer->allows('inventory')) {
            $queue[] = [
                'key' => 'orders_to_dispatch',
                'count' => Order::whereIn('status', OrderListFilter::AwaitingDispatch)
                    ->whereHas('lines', fn ($lines) => $lines->whereColumn('dispatched', '<', 'quantity'))
                    ->count(),
                'section' => 'deliveries',
            ];
            $queue[] = [
                'key' => 'low_stock_products',
                'count' => Product::where('active', true)->whereColumn('stock', '<=', 'low_stock_at')->count(),
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
     * Daily confirmed sales for the last {@see TrendDays} days in business time.
     *
     * @return list<array{date: string, orders: int, value: string}>
     */
    private function trend(): array
    {
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $today->subDays(self::TrendDays - 1);

        $rows = Order::query()
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
    private function month(): array
    {
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $now = CarbonImmutable::now($timezone);
        $from = $now->startOfMonth()->utc();
        $confirmed = Order::query()->whereIn('status', OrderListFilter::Confirmed)->where('created_at', '>=', $from);
        $collected = DB::table('receipts')
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
}
