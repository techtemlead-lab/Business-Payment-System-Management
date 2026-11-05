<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Orders\OrderListFilter;
use Illuminate\Support\Facades\Schema;

/**
 * Paged order list shared by the overview, orders, deliveries, dues and reports sections.
 */
final class OrdersSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if (! in_array($context->section, ['overview', 'orders', 'deliveries', 'dues', 'reports'])) {
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
        }

        return $props;
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
