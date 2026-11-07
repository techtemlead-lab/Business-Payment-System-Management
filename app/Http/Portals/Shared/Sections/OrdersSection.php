<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Orders\OrderListFilter;
use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paged order list shared by the overview, orders, deliveries and reports sections.
 */
final class OrdersSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if (! in_array($context->section, ['overview', 'orders', 'deliveries', 'reports'])) {
            return [];
        }
        $request = $context->request;
        $filteredOrders = app(OrderListFilter::class)->apply(clone $context->orders, $request, $context->section, $context->isStaff);
        // Overview only needs ordered and sent quantities for the items and delivered columns — keep the payload slim.
        $lineRelation = $context->section === 'overview' ? 'lines:id,order_id,quantity,dispatched' : 'lines';
        $orderWith = ['user:id,name,dealer_code,role,phone,division,district', $lineRelation];
        // Partners see which branch is sending their order; only the name and code are loaded.
        if (Schema::hasColumn('orders', 'fulfillment_branch_id')) {
            $orderWith[] = 'fulfillmentBranch:id,name,code';
        }

        $props = [
            'orders' => $filteredOrders->with($orderWith)
                ->latest()
                ->paginate(12)
                ->withQueryString(),
        ];
        if ($context->section === 'orders') {
            $props['order_status_counts'] = $this->statusCounts($context);
            $props['order_totals'] = $this->listTotals($filteredOrders);
        }
        if ($context->section === 'deliveries') {
            $props['delivery_summary'] = $this->deliverySummary($filteredOrders);
        }

        return $props;
    }

    /**
     * Grand totals for every order in the filtered list, not only the 12 on screen. Rejected and
     * cancelled orders count as orders but add nothing to value or to pay.
     *
     * @return array{orders: int, value: string, paid: string, to_pay: string}
     */
    private function listTotals(Builder $filteredOrders): array
    {
        $open = "orders.status NOT IN ('rejected', 'cancelled')";
        $row = (clone $filteredOrders)->toBase()->reorder()
            ->selectRaw("COUNT(*) AS orders, COALESCE(SUM(CASE WHEN {$open} THEN orders.total ELSE 0 END), 0) AS value, "
                .'COALESCE(SUM(orders.paid), 0) AS paid, '
                ."COALESCE(SUM(CASE WHEN {$open} THEN GREATEST(orders.total - orders.due_credit - orders.paid, 0) ELSE 0 END), 0) AS to_pay")
            ->first();

        return [
            'orders' => (int) $row->orders,
            'value' => Money::decimal(Money::cents((string) $row->value)),
            'paid' => Money::decimal(Money::cents((string) $row->paid)),
            'to_pay' => Money::decimal(Money::cents((string) $row->to_pay)),
        ];
    }

    /**
     * Totals above the deliveries list: orders and units still waiting to be sent.
     *
     * @return array{orders: int, units: int, oldest_days: int}
     */
    private function deliverySummary(Builder $filteredOrders): array
    {
        $today = now('Asia/Dhaka')->toDateString();
        $ids = (clone $filteredOrders)->toBase()->reorder()->select('orders.id');
        $units = (int) DB::table('order_lines')->whereIn('order_id', $ids)->sum(DB::raw('GREATEST(quantity - dispatched, 0)'));
        $row = (clone $filteredOrders)->toBase()->reorder()
            ->selectRaw("COUNT(*) AS orders, COALESCE(MAX(DATE '{$today}' - DATE(orders.created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka')), 0) AS oldest_days")
            ->first();

        return ['orders' => (int) $row->orders, 'units' => $units, 'oldest_days' => (int) $row->oldest_days];
    }

    /**
     * Orders per status under every other filter on screen (search, period,
     * buyer type), for the status tabs above the list — like Shopify or Daraz
     * Seller Center. The status filter itself is left out so every tab keeps
     * its count.
     *
     * @return array<string, int>
     */
    private function statusCounts(SectionContext $context): array
    {
        $withoutStatus = $context->request->duplicate($context->request->except('status'));

        return app(OrderListFilter::class)
            ->apply(clone $context->orders, $withoutStatus, $context->section, $context->isStaff)
            ->toBase()
            ->reorder()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
