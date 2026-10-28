<?php

namespace App\Domain\Inventory;

use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BranchPerformance
{
    /**
     * @return array{from:string,to:string,label:string,timezone:string}
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
     * @param  array{period?:string,from?:string,to?:string,branch_id?:int|null}  $filters
     * @return array{
     *   period: array{period:string,from:string,to:string,label:string,timezone:string},
     *   totals: array<string, mixed>,
     *   branches: list<array<string, mixed>>,
     *   unassigned: array<string, mixed>,
     *   orders: list<array<string, mixed>>
     * }
     */
    public function summary(User $actor, array $filters = []): array
    {
        abort_unless($actor->owner() || $actor->allows('reports') || $actor->allows('finance') || $actor->allows('inventory'), 403);

        $period = $this->resolvePeriod(
            isset($filters['period']) ? (string) $filters['period'] : null,
            isset($filters['from']) ? (string) $filters['from'] : null,
            isset($filters['to']) ? (string) $filters['to'] : null,
        );

        $branchFilter = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        if ($branchFilter !== null && $branchFilter < 1) {
            $branchFilter = null;
        }

        $branches = CompanyBranch::query()->orderBy('name')->get()->keyBy('id');
        $orderRows = DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.created_at', '>=', $period['from_utc'])
            ->where('o.created_at', '<', $period['to_utc_exclusive'])
            ->when($branchFilter, fn ($q) => $q->where('o.fulfillment_branch_id', $branchFilter))
            ->orderByDesc('o.created_at')
            ->limit(200)
            ->get([
                'o.id',
                'o.number',
                'o.status',
                'o.total',
                'o.paid',
                'o.due_credit',
                'o.fulfillment_branch_id',
                'o.created_at',
                'u.name as partner_name',
                'u.dealer_code',
            ]);

        $orderIds = $orderRows->pluck('id')->all();
        $profitByOrder = [];
        if ($orderIds !== []) {
            $profitRows = DB::table('order_lines as l')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->whereIn('l.order_id', $orderIds)
                ->groupBy('l.order_id')
                ->selectRaw('l.order_id, COALESCE(SUM(CASE WHEN p.cost_price IS NULL THEN 0 ELSE (l.unit_price - p.cost_price) * l.quantity END), 0) AS estimated_profit, COALESCE(SUM(CASE WHEN p.cost_price IS NULL THEN 0 ELSE p.cost_price * l.quantity END), 0) AS estimated_cost')
                ->get()
                ->keyBy('order_id');
            foreach ($profitRows as $orderId => $row) {
                $profitByOrder[(string) $orderId] = [
                    'estimated_profit' => Money::decimal(Money::cents((string) $row->estimated_profit)),
                    'estimated_cost' => Money::decimal(Money::cents((string) $row->estimated_cost)),
                ];
            }
        }

        $bucket = [];
        foreach ($branches as $branch) {
            $bucket[$branch->id] = $this->emptyMetrics($branch->summary());
        }
        $unassigned = $this->emptyMetrics([
            'id' => null,
            'code' => 'UNASSIGNED',
            'name' => __('portal.branch_unassigned'),
        ]);

        $ordersOut = [];
        foreach ($orderRows as $order) {
            $branchId = $order->fulfillment_branch_id !== null ? (int) $order->fulfillment_branch_id : null;
            $profit = $profitByOrder[(string) $order->id] ?? ['estimated_profit' => '0.00', 'estimated_cost' => '0.00'];
            $outstanding = Money::decimal(
                Money::cents((string) $order->total)
                - Money::cents((string) $order->paid)
                - Money::cents((string) ($order->due_credit ?? '0'))
            );
            $entry = [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'partner_name' => $order->partner_name,
                'dealer_code' => $order->dealer_code,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal(Money::cents((string) $order->paid)),
                'outstanding' => $outstanding,
                'estimated_profit' => $profit['estimated_profit'],
                'estimated_cost' => $profit['estimated_cost'],
                'fulfillment_branch_id' => $branchId,
                'branch_code' => $branchId && isset($branches[$branchId]) ? $branches[$branchId]->code : null,
                'branch_name' => $branchId && isset($branches[$branchId]) ? $branches[$branchId]->name : __('portal.branch_unassigned'),
                'created_at' => CarbonImmutable::parse($order->created_at)->timezone($period['timezone'])->toIso8601String(),
            ];
            $ordersOut[] = $entry;

            $target = ($branchId && isset($bucket[$branchId])) ? $bucket[$branchId] : $unassigned;
            $target['order_count']++;
            $target['order_value_cents'] += Money::cents($entry['total']);
            $target['income_cents'] += Money::cents($entry['paid']);
            $target['outstanding_cents'] += Money::cents($entry['outstanding']);
            $target['profit_cents'] += Money::cents($entry['estimated_profit']);
            $target['cost_cents'] += Money::cents($entry['estimated_cost']);
            if ($branchId && isset($bucket[$branchId])) {
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
            'orders' => $ordersOut,
            'selected_branch_id' => $branchFilter,
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
            'order_value' => Money::decimal((int) $row['order_value_cents']),
            'income' => Money::decimal((int) $row['income_cents']),
            'outstanding' => Money::decimal((int) $row['outstanding_cents']),
            'estimated_profit' => Money::decimal((int) $row['profit_cents']),
            'estimated_cost' => Money::decimal((int) $row['cost_cents']),
            'order_value_cents' => (int) $row['order_value_cents'],
            'income_cents' => (int) $row['income_cents'],
            'outstanding_cents' => (int) $row['outstanding_cents'],
            'profit_cents' => (int) $row['profit_cents'],
            'cost_cents' => (int) $row['cost_cents'],
        ];
    }
}
