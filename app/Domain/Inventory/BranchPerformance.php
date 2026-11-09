<?php

namespace App\Domain\Inventory;

use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderListFilter;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BranchPerformance
{
    /**
     * @return array{period:string,from:string,to:string,label:string,timezone:string,from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}
     */
    public function resolvePeriod(?string $period, ?string $from, ?string $to): array
    {
        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $now = CarbonImmutable::now($timezone);
        $period = in_array($period, ['today', 'month', 'custom'], true) ? $period : 'month';

        if ($period === 'today') {
            $start = $now->startOfDay();
            $end = $now->endOfDay();
            $label = 'today';
        } elseif ($period === 'custom' && is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $end = CarbonImmutable::parse($to, $timezone)->endOfDay();
            if ($end->lt($start)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }
            $label = 'custom';
        } else {
            $start = $now->startOfMonth();
            $end = $now->endOfMonth();
            $period = 'month';
            $label = 'month';
        }

        return [
            'period' => $period,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'label' => $label,
            'timezone' => $timezone,
            'from_utc' => $start->utc(),
            'to_utc_exclusive' => $end->addSecond()->utc(),
        ];
    }

    /**
     * Owner/staff financial snapshot per company branch for a period.
     *
     * The report covers every confirmed order in the period ({@see OrderListFilter::Confirmed}).
     * Order value, income, cost and profit count delivered goods only ({@see DeliveredMoney}),
     * and the sums are done in SQL, so they stay right however many orders there are. The order
     * list below them is paged on the server, 25 orders at a time.
     *
     * Staff who may not view every branch see only the branches they are assigned to
     * ({@see BranchOperations::visibleBranches()}); asking for any other branch is refused.
     *
     * @param  array{period?:string|null,from?:string|null,to?:string|null,branch_id?:int|string|null,follow_workspace_branch?:bool}  $filters
     * @return array{
     *   period: array{period:string,from:string,to:string,label:string,timezone:string},
     *   totals: array<string, mixed>,
     *   branches: list<array<string, mixed>>,
     *   unassigned: array<string, mixed>,
     *   orders: LengthAwarePaginator<int, array<string, mixed>>,
     *   selected_branch_id: int|null,
     *   can_view_all_branches: bool,
     *   branch_options: list<array<string, mixed>>
     * }
     */
    public function summary(User $actor, array $filters = [], int $perPage = 25): array
    {
        ['period' => $period, 'branch_id' => $branchFilter, 'branches' => $branches, 'can_view_all_branches' => $canViewAllBranches] = $this->reportScope($actor, $filters);
        $confirmedOrders = $this->confirmedOrders($period, $branchFilter, $branches, $canViewAllBranches);

        $bucket = [];
        foreach ($branches as $branch) {
            $bucket[$branch->id] = $this->emptyMetrics($branch->summary());
        }
        $unassigned = $this->emptyMetrics([
            'id' => null,
            'code' => 'UNASSIGNED',
            'name' => __('portal.branch_unassigned'),
        ]);

        $branchRows = (clone $confirmedOrders)
            ->groupBy('o.fulfillment_branch_id')
            ->selectRaw(
                'o.fulfillment_branch_id, COUNT(*) AS order_count, '
                .'COALESCE(SUM('.DeliveredMoney::valueSql('o').'), 0) AS order_value, '
                .'COALESCE(SUM('.DeliveredMoney::paidSql('o').'), 0) AS income, '
                .'COALESCE(SUM(GREATEST(o.total - o.paid - COALESCE(o.due_credit, 0), 0)), 0) AS outstanding, '
                .'COALESCE(SUM(lc.estimated_profit), 0) AS estimated_profit, '
                .'COALESCE(SUM(lc.estimated_cost), 0) AS estimated_cost'
            )
            ->get();

        foreach ($branchRows as $row) {
            $branchId = $row->fulfillment_branch_id !== null ? (int) $row->fulfillment_branch_id : null;
            $known = $branchId !== null && isset($bucket[$branchId]);
            $target = $known ? $bucket[$branchId] : $unassigned;
            $target['order_count'] += (int) $row->order_count;
            $target['order_value_cents'] += $this->signedCents($row->order_value);
            $target['income_cents'] += $this->signedCents($row->income);
            $target['outstanding_cents'] += $this->signedCents($row->outstanding);
            $target['profit_cents'] += $this->signedCents($row->estimated_profit);
            $target['cost_cents'] += $this->signedCents($row->estimated_cost);
            if ($known) {
                $bucket[$branchId] = $target;
            } else {
                $unassigned = $target;
            }
        }

        $branchSummaries = array_values(array_map(fn (array $row) => $this->finalizeMetrics($row), $bucket));
        usort($branchSummaries, fn (array $a, array $b) => $b['order_value_cents'] <=> $a['order_value_cents']);

        $totals = $this->emptyMetrics(['id' => 0, 'code' => 'ALL', 'name' => __('portal.branch_all')]);
        foreach ([...$branchSummaries, $this->finalizeMetrics($unassigned)] as $row) {
            $totals['order_count'] += $row['order_count'];
            $totals['order_value_cents'] += $row['order_value_cents'];
            $totals['income_cents'] += $row['income_cents'];
            $totals['outstanding_cents'] += $row['outstanding_cents'];
            $totals['profit_cents'] += $row['profit_cents'];
            $totals['cost_cents'] += $row['cost_cents'];
        }

        // The branch figures already counted the orders, so the page needs no count query of its own.
        $orders = $this->listedOrders($confirmedOrders)
            ->paginate($perPage, ['*'], 'page', null, (int) $totals['order_count'])
            ->withQueryString();
        $orders->setCollection($orders->getCollection()->map(fn (object $order): array => $this->orderRow($order, $branches, $period['timezone'])));

        return [
            'period' => [
                'period' => $period['period'],
                'from' => $period['from'],
                'to' => $period['to'],
                'label' => $period['label'],
                'timezone' => $period['timezone'],
            ],
            'totals' => $this->finalizeMetrics($totals),
            'branches' => $branchSummaries,
            'unassigned' => $this->finalizeMetrics($unassigned),
            'orders' => $orders,
            'selected_branch_id' => $branchFilter,
            'can_view_all_branches' => $canViewAllBranches,
            'branch_options' => $branches->values()->map(fn (CompanyBranch $branch) => $branch->summary())->all(),
        ];
    }

    /**
     * Every order behind the report for these filters, newest first, for the Excel download.
     *
     * @param  array{period?:string|null,from?:string|null,to?:string|null,branch_id?:int|string|null,follow_workspace_branch?:bool}  $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function orderRows(User $actor, array $filters = []): Generator
    {
        ['period' => $period, 'branch_id' => $branchFilter, 'branches' => $branches, 'can_view_all_branches' => $canViewAllBranches] = $this->reportScope($actor, $filters);
        foreach ($this->listedOrders($this->confirmedOrders($period, $branchFilter, $branches, $canViewAllBranches))->lazy(500) as $order) {
            yield $this->orderRow($order, $branches, $period['timezone']);
        }
    }

    /**
     * The period, the branch and the branches this reader may see for these filters.
     * Refuses a branch they may not see.
     *
     * @param  array{period?:string|null,from?:string|null,to?:string|null,branch_id?:int|string|null,follow_workspace_branch?:bool}  $filters
     * @return array{period: array{period:string,from:string,to:string,label:string,timezone:string,from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}, branch_id: int|null, branches: Collection<int, CompanyBranch>, can_view_all_branches: bool}
     */
    public function reportScope(User $actor, array $filters): array
    {
        abort_unless($actor->owner() || $actor->allows('reports') || $actor->allows('finance') || $actor->allows('inventory'), 403);

        $period = $this->resolvePeriod(
            isset($filters['period']) ? (string) $filters['period'] : null,
            isset($filters['from']) ? (string) $filters['from'] : null,
            isset($filters['to']) ? (string) $filters['to'] : null,
        );

        $branchOperations = app(BranchOperations::class);
        $branches = $branchOperations->visibleBranches($actor)->keyBy('id');

        $branchFilter = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        if ($branchFilter !== null && $branchFilter < 1) {
            $branchFilter = null;
        }
        if ($branchFilter === null && ($filters['follow_workspace_branch'] ?? false)) {
            $branchFilter = $branchOperations->preferredAmong($branches->values(), $actor)?->id;
        }
        abort_if($branchFilter !== null && ! $branches->has($branchFilter), 403);

        return [
            'period' => $period,
            'branch_id' => $branchFilter,
            'branches' => $branches,
            'can_view_all_branches' => $branchOperations->canViewAllBranches($actor),
        ];
    }

    /**
     * Every confirmed order in the period for the chosen branch, joined to its estimated cost and profit.
     *
     * @param  array{from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}  $period
     * @param  Collection<int, CompanyBranch>  $branches
     */
    private function confirmedOrders(array $period, ?int $branchFilter, Collection $branches, bool $canViewAllBranches): Builder
    {
        return DB::table('orders as o')
            ->whereIn('o.status', OrderListFilter::Confirmed)
            ->where('o.created_at', '>=', $period['from_utc'])
            ->where('o.created_at', '<', $period['to_utc_exclusive'])
            ->when(
                $branchFilter !== null,
                fn (Builder $orders) => $orders->where('o.fulfillment_branch_id', $branchFilter),
                fn (Builder $orders) => $orders->when(
                    ! $canViewAllBranches,
                    fn (Builder $scoped) => $scoped->whereIn('o.fulfillment_branch_id', $branches->keys()->all()),
                ),
            )
            ->leftJoinLateral($this->orderCostQuery(), 'lc');
    }

    /**
     * The confirmed orders with partner details and money columns, newest first.
     */
    private function listedOrders(Builder $confirmedOrders): Builder
    {
        return (clone $confirmedOrders)
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->select([
                'o.id',
                'o.number',
                'o.status',
                'o.total',
                'o.paid',
                DB::raw(DeliveredMoney::valueSql('o').' AS delivered_value'),
                DB::raw(DeliveredMoney::paidSql('o').' AS delivered_paid'),
                'o.due_credit',
                'o.fulfillment_branch_id',
                'o.created_at',
                'u.name as partner_name',
                'u.dealer_code',
                DB::raw('COALESCE(lc.estimated_profit, 0) AS estimated_profit'),
                DB::raw('COALESCE(lc.estimated_cost, 0) AS estimated_cost'),
            ])
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id');
    }

    /**
     * @param  Collection<int, CompanyBranch>  $branches
     * @return array<string, mixed>
     */
    private function orderRow(object $order, Collection $branches, string $timezone): array
    {
        $branchId = $order->fulfillment_branch_id !== null ? (int) $order->fulfillment_branch_id : null;
        $outstandingCents = Money::cents((string) $order->total)
            - Money::cents((string) $order->paid)
            - Money::cents((string) ($order->due_credit ?? '0'));

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'partner_name' => $order->partner_name,
            'dealer_code' => $order->dealer_code,
            'total' => Money::decimal(Money::cents((string) $order->total)),
            'paid' => Money::decimal(Money::cents((string) $order->paid)),
            'delivered_value' => Money::decimal(Money::cents((string) $order->delivered_value)),
            'delivered_paid' => Money::decimal(Money::cents((string) $order->delivered_paid)),
            'outstanding' => Money::decimal(max($outstandingCents, 0)),
            'estimated_profit' => $this->signedDecimal($this->signedCents($order->estimated_profit)),
            'estimated_cost' => $this->signedDecimal($this->signedCents($order->estimated_cost)),
            'fulfillment_branch_id' => $branchId,
            'branch_code' => $branchId && isset($branches[$branchId]) ? $branches[$branchId]->code : null,
            'branch_name' => $branchId && isset($branches[$branchId]) ? $branches[$branchId]->name : __('portal.branch_unassigned'),
            'created_at' => CarbonImmutable::parse($order->created_at)->timezone($timezone)->toIso8601String(),
        ];
    }

    /**
     * Estimated cost and profit of one order's lines, joined laterally to each order.
     * Lines whose product has no cost price add nothing.
     */
    private function orderCostQuery(): Builder
    {
        return DB::table('order_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereColumn('l.order_id', 'o.id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN p.cost_price IS NULL THEN 0 ELSE (l.unit_price - p.cost_price) * '.DeliveredMoney::lineQuantitySql().' END), 0) AS estimated_profit, '
                .'COALESCE(SUM(CASE WHEN p.cost_price IS NULL THEN 0 ELSE p.cost_price * '.DeliveredMoney::lineQuantitySql().' END), 0) AS estimated_cost'
            );
    }

    /**
     * Cents from a SQL decimal that may be negative (a sale below cost gives a loss).
     */
    private function signedCents(mixed $value): int
    {
        $text = trim((string) ($value ?? '0'));
        if (str_starts_with($text, '-')) {
            return -Money::cents(substr($text, 1));
        }

        return Money::cents($text);
    }

    private function signedDecimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').Money::decimal(abs($cents));
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function emptyMetrics(array $identity): array
    {
        return [
            ...$identity,
            'order_count' => 0,
            'order_value_cents' => 0,
            'income_cents' => 0,
            'outstanding_cents' => 0,
            'profit_cents' => 0,
            'cost_cents' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finalizeMetrics(array $row): array
    {
        return [
            'id' => $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'division' => $row['division'] ?? null,
            'district' => $row['district'] ?? null,
            'is_delivery_point' => (bool) ($row['is_delivery_point'] ?? false),
            'is_stock_hub' => (bool) ($row['is_stock_hub'] ?? false),
            'order_count' => (int) $row['order_count'],
            'order_value' => Money::decimal((int) $row['order_value_cents']),
            'income' => Money::decimal((int) $row['income_cents']),
            'outstanding' => Money::decimal((int) $row['outstanding_cents']),
            'estimated_profit' => $this->signedDecimal((int) $row['profit_cents']),
            'estimated_cost' => $this->signedDecimal((int) $row['cost_cents']),
            'order_value_cents' => (int) $row['order_value_cents'],
            'income_cents' => (int) $row['income_cents'],
            'outstanding_cents' => (int) $row['outstanding_cents'],
            'profit_cents' => (int) $row['profit_cents'],
            'cost_cents' => (int) $row['cost_cents'],
        ];
    }
}
