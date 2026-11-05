<?php

namespace App\Domain\Inventory;

use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\Money;
use App\Support\TableWorkbook;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BranchPerformance
{
    /** Rejected and cancelled orders are not waiting for any payment. */
    private const OpenOrder = "o.status NOT IN ('rejected', 'cancelled')";

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
     * Owner/staff financial snapshot per company branch for a period. Branch
     * figures and the grand totals cover every order in the period; the order
     * list below them is paged on the server.
     *
     * @param  array{period?:string,from?:string,to?:string,branch_id?:int|string|null}  $filters
     * @return array{
     *   period: array{period:string,from:string,to:string,label:string,timezone:string},
     *   totals: array<string, mixed>,
     *   branches: list<array<string, mixed>>,
     *   unassigned: array<string, mixed>,
     *   orders: LengthAwarePaginator<int, array<string, mixed>>,
     *   selected_branch_id: ?int
     * }
     */
    public function summary(User $actor, array $filters = [], int $perPage = 25): array
    {
        $this->authorize($actor);
        [$period, $branchFilter] = $this->resolveFilters($filters);
        $branches = CompanyBranch::query()->orderBy('name')->get()->keyBy('id');

        $bucket = [];
        foreach ($branches as $branch) {
            $bucket[$branch->id] = $this->emptyMetrics($branch->summary());
        }
        $unassigned = $this->emptyMetrics(['id' => null, 'code' => 'UNASSIGNED', 'name' => __('portal.branch_unassigned')]);
        foreach ($this->branchMetrics($period, $branchFilter) as $row) {
            $branchId = $row->fulfillment_branch_id !== null ? (int) $row->fulfillment_branch_id : null;
            $metrics = [
                'order_count' => (int) $row->order_count,
                'order_value_cents' => $this->signedCents($row->order_value),
                'income_cents' => $this->signedCents($row->income),
                'outstanding_cents' => $this->signedCents($row->outstanding),
                'profit_cents' => $this->signedCents($row->estimated_profit),
                'cost_cents' => $this->signedCents($row->estimated_cost),
            ];
            if ($branchId !== null && isset($bucket[$branchId])) {
                $bucket[$branchId] = [...$bucket[$branchId], ...$metrics];
            } else {
                foreach ($metrics as $key => $value) {
                    $unassigned[$key] += $value;
                }
            }
        }

        $branchSummaries = array_values(array_map(fn (array $row) => $this->finalizeMetrics($row), $bucket));
        usort($branchSummaries, fn (array $a, array $b) => $b['order_value_cents'] <=> $a['order_value_cents']);

        $totals = $this->emptyMetrics(['id' => 0, 'code' => 'ALL', 'name' => __('portal.branch_all')]);
        foreach ([...$branchSummaries, $this->finalizeMetrics($unassigned)] as $row) {
            foreach (['order_count', 'order_value_cents', 'income_cents', 'outstanding_cents', 'profit_cents', 'cost_cents'] as $key) {
                $totals[$key] += $row[$key];
            }
        }

        // The branch figures already counted the orders, so the page needs no count query of its own.
        $orders = $this->orderQuery($period, $branchFilter)
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
        ];
    }

    /**
     * Every order in the period and branch, newest first, for the Excel download.
     *
     * @param  array{period?:string,from?:string,to?:string,branch_id?:int|string|null}  $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function orderRows(User $actor, array $filters = []): Generator
    {
        $this->authorize($actor);
        [$period, $branchFilter] = $this->resolveFilters($filters);
        $branches = CompanyBranch::query()->get()->keyBy('id');
        foreach ($this->orderQuery($period, $branchFilter)->lazy(500) as $order) {
            yield $this->orderRow($order, $branches, $period['timezone']);
        }
    }

    /**
     * The resolved period and branch for these filters.
     *
     * @param  array{period?:string,from?:string,to?:string,branch_id?:int|string|null}  $filters
     * @return array{0: array{period:string,from:string,to:string,label:string,timezone:string,from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}, 1: ?int}
     */
    public function resolveFilters(array $filters): array
    {
        $period = $this->resolvePeriod(
            isset($filters['period']) ? (string) $filters['period'] : null,
            isset($filters['from']) ? (string) $filters['from'] : null,
            isset($filters['to']) ? (string) $filters['to'] : null,
        );
        $branchFilter = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        if ($branchFilter !== null && $branchFilter < 1) {
            $branchFilter = null;
        }

        return [$period, $branchFilter];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->owner() || $actor->allows('reports') || $actor->allows('finance') || $actor->allows('inventory'), 403);
    }

    /**
     * @param  array{from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}  $period
     */
    private function ordersInPeriod(array $period, ?int $branchFilter, string $alias = 'o'): Builder
    {
        return DB::table('orders as '.$alias)
            ->where($alias.'.created_at', '>=', $period['from_utc'])
            ->where($alias.'.created_at', '<', $period['to_utc_exclusive'])
            ->when($branchFilter, fn (Builder $query) => $query->where($alias.'.fulfillment_branch_id', $branchFilter));
    }

    /**
     * Estimated profit and cost per order from the cost price of each product sold.
     *
     * @param  array{from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}  $period
     */
    private function profitPerOrder(array $period, ?int $branchFilter): Builder
    {
        return $this->ordersInPeriod($period, $branchFilter, 'po')
            ->join('order_lines as l', 'l.order_id', '=', 'po.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->groupBy('l.order_id')
            ->select('l.order_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN p.cost_price IS NULL THEN 0 ELSE (l.unit_price - p.cost_price) * l.quantity END), 0) AS estimated_profit')
            ->selectRaw('COALESCE(SUM(CASE WHEN p.cost_price IS NULL THEN 0 ELSE p.cost_price * l.quantity END), 0) AS estimated_cost');
    }

    /**
     * One row per delivery branch with the money for every order in the period.
     *
     * @param  array{from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}  $period
     * @return list<object>
     */
    private function branchMetrics(array $period, ?int $branchFilter): array
    {
        $open = self::OpenOrder;

        return $this->ordersInPeriod($period, $branchFilter)
            ->leftJoinSub($this->profitPerOrder($period, $branchFilter), 'lp', 'lp.order_id', '=', 'o.id')
            ->groupBy('o.fulfillment_branch_id')
            ->select('o.fulfillment_branch_id')
            ->selectRaw('COUNT(*) AS order_count')
            ->selectRaw('COALESCE(SUM(o.total), 0) AS order_value')
            ->selectRaw('COALESCE(SUM(o.paid), 0) AS income')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$open} THEN GREATEST(o.total - o.due_credit - o.paid, 0) ELSE 0 END), 0) AS outstanding")
            ->selectRaw('COALESCE(SUM(lp.estimated_profit), 0) AS estimated_profit')
            ->selectRaw('COALESCE(SUM(lp.estimated_cost), 0) AS estimated_cost')
            ->get()
            ->all();
    }

    /**
     * Orders in the period with partner, what is still to pay and estimated profit, newest first.
     *
     * @param  array{from_utc:CarbonImmutable,to_utc_exclusive:CarbonImmutable}  $period
     */
    private function orderQuery(array $period, ?int $branchFilter): Builder
    {
        $open = self::OpenOrder;

        return $this->ordersInPeriod($period, $branchFilter)
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoinSub($this->profitPerOrder($period, $branchFilter), 'lp', 'lp.order_id', '=', 'o.id')
            ->select(['o.id', 'o.number', 'o.status', 'o.total', 'o.paid', 'o.fulfillment_branch_id', 'o.created_at', 'u.name as partner_name', 'u.dealer_code'])
            ->selectRaw("CASE WHEN {$open} THEN GREATEST(o.total - o.due_credit - o.paid, 0) ELSE 0 END AS outstanding")
            ->selectRaw('COALESCE(lp.estimated_profit, 0) AS estimated_profit')
            ->selectRaw('COALESCE(lp.estimated_cost, 0) AS estimated_cost')
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id');
    }

    /**
     * @param  Collection<int, CompanyBranch>  $branches
     * @return array<string, mixed>
     */
    private function orderRow(object $order, $branches, string $timezone): array
    {
        $branchId = $order->fulfillment_branch_id !== null ? (int) $order->fulfillment_branch_id : null;
        $branch = $branchId !== null ? $branches->get($branchId) : null;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'partner_name' => $order->partner_name,
            'dealer_code' => $order->dealer_code,
            'total' => $this->signedDecimal($this->signedCents($order->total)),
            'paid' => $this->signedDecimal($this->signedCents($order->paid)),
            'outstanding' => $this->signedDecimal($this->signedCents($order->outstanding)),
            'estimated_profit' => $this->signedDecimal($this->signedCents($order->estimated_profit)),
            'estimated_cost' => $this->signedDecimal($this->signedCents($order->estimated_cost)),
            'fulfillment_branch_id' => $branchId,
            'branch_code' => $branch?->code,
            'branch_name' => $branch?->name ?? __('portal.branch_unassigned'),
            'created_at' => CarbonImmutable::parse($order->created_at, 'UTC')->timezone($timezone)->toIso8601String(),
        ];
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
            'order_value' => $this->signedDecimal((int) $row['order_value_cents']),
            'income' => $this->signedDecimal((int) $row['income_cents']),
            'outstanding' => $this->signedDecimal((int) $row['outstanding_cents']),
            'estimated_profit' => $this->signedDecimal((int) $row['profit_cents']),
            'estimated_cost' => $this->signedDecimal((int) $row['cost_cents']),
            'order_value_cents' => (int) $row['order_value_cents'],
            'income_cents' => (int) $row['income_cents'],
            'outstanding_cents' => (int) $row['outstanding_cents'],
            'profit_cents' => (int) $row['profit_cents'],
            'cost_cents' => (int) $row['cost_cents'],
        ];
    }

    private function signedCents(mixed $value): int
    {
        return TableWorkbook::signedCents($value);
    }

    /** Estimated profit can be below zero when an item sells under its cost price. */
    private function signedDecimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').Money::decimal(abs($cents));
    }
}
