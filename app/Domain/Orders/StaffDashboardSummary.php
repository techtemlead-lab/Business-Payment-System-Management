<?php

namespace App\Domain\Orders;

use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FundSpending;
use App\Domain\Finance\MoneyTaskFigures;
use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Finance\PendingPaymentChecks;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\SellableStock;
use App\Domain\Inventory\StockLevels;
use App\Domain\Payouts\PayoutLimits;
use App\Domain\Security\OwnerExemption;
use App\Domain\Support\PartnerChat;
use App\Http\Portals\Shared\Sections\WithdrawalsSection;
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
     * CR-16: the staff capability that finishes each work-list row. A failed month close and
     * unanswered chats are left out on purpose: the first is an alarm, and the owner decides who
     * answers chat, so both stay on the owner's list.
     */
    private const QueueCapabilities = [
        'orders_to_review' => ['order_review'],
        'payments_to_check' => ['finance'],
        'withdrawals_to_review' => ['finance'],
        'payouts_overdue' => ['finance'],
        'commission_ready_to_pay' => ['finance'],
        'commission_held' => ['finance'],
        'orders_to_dispatch' => ['inventory'],
        'deliveries_overdue' => ['orders', 'inventory'],
        'back_orders_late' => ['inventory', 'order_review'],
        'owed_items_open' => ['inventory'],
        'low_stock_products' => ['inventory'],
        'applications_to_review' => ['accounts'],
    ];

    /**
     * @param  BranchScope|null  $branchScope  The branch the top-bar switcher picked; null counts every branch.
     * @return array{
     *     work_queue: list<array{key: string, count: int, section: string, query?: array<string, string>, label?: string, amount?: string, alert?: bool}>,
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
     * @return list<array{key: string, count: int, section: string, query?: array<string, string>, label?: string, amount?: string, alert?: bool, oldest_days?: int|null}>
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
                'count' => $this->onScopedOrders(PendingPaymentChecks::query(), $branchScope)->count(),
                'section' => 'payments',
            ];
            $queue[] = [
                'key' => 'withdrawals_to_review',
                'count' => (int) $this->withdrawalsOf($branchScope)->where('withdrawals.status', 'requested')->count(),
                'section' => 'payouts-hub',
            ];
            // Approved money nobody has sent yet, past the owner's due days (MA-06). The setting is read once.
            $dueDays = app(PayoutLimits::class)->dueDays();
            $queue[] = [
                'key' => 'payouts_overdue',
                'label' => (string) __('payouts.dash_payouts_overdue', ['days' => (string) $dueDays]),
                'count' => (int) $this->withdrawalsOf($branchScope)->whereRaw(WithdrawalsSection::followUpSql($dueDays)." = 'overdue'")->count(),
                'section' => 'payouts-hub',
                'query' => ['follow_up' => 'overdue'],
            ];
            array_push($queue, ...$this->commissionTasks());
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
        if ($viewer->allows('inventory') || $viewer->allows('order_review')) {
            // Paid back orders near or past the e-commerce delivery and refund limit (OS-02, LC-04).
            $queue[] = [
                'key' => 'back_orders_late',
                'label' => __('orders_stock.dashboard_back_orders_hint', ['days' => BackOrders::WarnFromDays]),
                'count' => app(BackOrders::class)->lateCount($this->orders($branchScope)->toBase()),
                'section' => 'deliveries',
            ];
        }
        if ($viewer->allows('inventory')) {
            $queue[] = [
                'key' => 'low_stock_products',
                'count' => array_sum(StockLevels::counts($branchScope)),
                'section' => 'inventory',
            ];
            // Goods owed to partners who paid in full (owner's rule 2026-11-21): send them with the next order.
            $queue[] = [
                'key' => 'owed_items_open',
                'label' => __('owed_items.dashboard_label'),
                'count' => OwedItems::openQuery()->count(),
                'section' => 'deliveries',
            ];
        }
        if (PartnerChat::canAnswer($viewer)) {
            $waiting = app(PartnerChat::class)->unanswered($viewer);
            $queue[] = [
                'key' => 'chats_unanswered',
                'count' => $waiting['count'],
                'oldest_days' => $waiting['oldest_days'],
                'section' => 'partner-messages',
            ];
        }
        if ($viewer->allows('accounts')) {
            $queue[] = [
                'key' => 'applications_to_review',
                'count' => (int) DB::table('dealer_applications')->where('status', 'pending')->count(),
                'section' => 'partnerships',
            ];
        }

        return OwnerExemption::applies($viewer) ? $this->ownerQueue($queue) : $queue;
    }

    /**
     * CR-16: the owner's "waiting for you" list holds only what waits for the owner: money at or
     * above the owner's limits and commission changes, plus any staff work no active staff member
     * can do. Work a staff member finishes is marked `staff_handles`, so the list leaves it out
     * while the day's figures above it still count it. The owner sees every step in the activity log.
     *
     * @param  list<array<string, mixed>>  $queue
     * @return list<array<string, mixed>>
     */
    private function ownerQueue(array $queue): array
    {
        $staff = User::query()->where('status', 'active')->staffAccounts()->whereNotIn('role', ['super_admin', 'md', 'chairman'])->get();
        $covered = fn (string ...$capabilities): bool => $staff->contains(fn (User $person): bool => collect($capabilities)->contains(fn (string $cap): bool => $person->allows($cap)));
        foreach ($queue as $index => $item) {
            $capabilities = self::QueueCapabilities[$item['key']] ?? null;
            if ($capabilities !== null && $covered(...$capabilities)) {
                $queue[$index]['staff_handles'] = true;
            }
        }
        $limits = app(OwnerApprovalLimits::class)->all();
        $withdrawalLimit = $limits[OwnerApprovalLimits::Withdrawal]['amount'];
        $ownerOnlyFunds = "'".implode("', '", CompanyFunds::OwnerOnly)."'";
        // One query for every owner row, so the owner's home page does not grow a query per row.
        $counts = DB::selectOne(
            "SELECT (SELECT COUNT(*) FROM withdrawals WHERE status = 'requested' AND amount >= ?) AS withdrawals,
                (SELECT COUNT(*) FROM order_return_requests AS r LEFT JOIN users AS first ON first.id = r.first_approved_by
                    WHERE r.status = ? AND r.refund_amount >= ? AND (first.id IS NULL OR first.role NOT IN ('super_admin', 'md', 'chairman'))) AS refunds,
                (SELECT COUNT(*) FROM reserve_disbursements WHERE program = ? AND status = 'requested' AND (amount >= ? OR fund_key IN ({$ownerOnlyFunds}))) AS fund_spends,
                (SELECT COUNT(*) FROM reserve_disbursements WHERE program <> ? AND status = 'requested' AND amount >= ?) AS rewards,
                (SELECT COUNT(*) FROM commission_policy_schedules WHERE status = 'draft') AS commission_changes",
            [$withdrawalLimit, OrderReturns::StatusAwaitingSecond, $limits[OwnerApprovalLimits::Refund]['amount'],
                FundSpending::Program, $limits[OwnerApprovalLimits::FundSpend]['amount'], FundSpending::Program, $withdrawalLimit],
        );
        $rows = [
            ['key' => 'owner_withdrawals', 'count' => (int) $counts->withdrawals, 'section' => 'payouts-hub', 'query' => ['status' => 'requested']],
            ['key' => 'owner_refunds', 'count' => (int) $counts->refunds, 'section' => 'orders'],
            ['key' => 'owner_fund_spends', 'count' => (int) $counts->fund_spends, 'section' => 'treasury/work'],
            ['key' => 'owner_rewards', 'count' => (int) $counts->rewards, 'section' => 'operations/reserve-payouts'],
            ['key' => 'owner_commission_changes', 'count' => (int) $counts->commission_changes, 'section' => 'operations/policy-schedules'],
        ];
        foreach ($rows as $row) {
            if ($row['count'] > 0) {
                $queue[] = [...$row, 'label' => (string) __('self_approval.dash_'.$row['key'])];
            }
        }

        return $queue;
    }

    /**
     * MA-15, MA-03: commission ready to pay, commission held and a failed month close. They show
     * only while there is something to do, and name themselves so they need no word list entry.
     *
     * @return list<array{key: string, count: int, section: string, label: string, amount?: string, alert?: bool}>
     */
    private function commissionTasks(): array
    {
        $figures = app(MoneyTaskFigures::class);
        $ready = $figures->readyToPay();
        $held = $figures->held();
        $failedCloses = $figures->failedCloses();
        $tasks = [];
        if ($ready['count'] > 0) {
            $tasks[] = ['key' => 'commission_ready_to_pay', 'count' => $ready['count'], 'section' => 'settlements', 'label' => (string) __('money_core.task_ready_to_pay'), 'amount' => $ready['amount']];
        }
        if ($held['count'] > 0) {
            $tasks[] = ['key' => 'commission_held', 'count' => $held['count'], 'section' => 'operations/commission-holds', 'label' => (string) __('money_core.task_held'), 'amount' => $held['amount']];
        }
        if ($failedCloses > 0) {
            $tasks[] = ['key' => 'month_close_failed', 'count' => $failedCloses, 'section' => 'settlements', 'label' => (string) __('money_core.task_close_failed'), 'alert' => true];
        }
        $staleSpends = $figures->staleSpends();
        if ($staleSpends['count'] > 0) {
            $tasks[] = ['key' => 'spends_overdue', 'count' => $staleSpends['count'], 'section' => 'treasury/work', 'label' => (string) __('money_core.task_spends_overdue', ['days' => $staleSpends['days']]), 'amount' => $staleSpends['amount'], 'alert' => true];
        }
        $stalePayables = $figures->stalePayables();
        if ($stalePayables['count'] > 0) {
            $tasks[] = ['key' => 'payables_overdue', 'count' => $stalePayables['count'], 'section' => 'operations/accounting', 'label' => (string) __('money_core.task_payables_overdue', ['days' => $stalePayables['days']]), 'amount' => $stalePayables['amount'], 'alert' => true];
        }

        return $tasks;
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
                "COUNT(*) FILTER (WHERE orders.status IN ({$awaiting}) AND EXISTS (SELECT 1 FROM order_lines WHERE order_lines.order_id = orders.id AND ".OrderLines::unsentSql('order_lines').')) AS to_dispatch, '
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
