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
        // Overview only needs line quantities for the items column — keep the payload slim.
        $lineRelation = $context->section === 'overview' ? 'lines:id,order_id,quantity' : 'lines';
        $orderWith = ['user:id,name,dealer_code,role,phone,division,district', $lineRelation];
        if ($context->isStaff && Schema::hasColumn('orders', 'fulfillment_branch_id')) {
            $orderWith[] = 'fulfillmentBranch:id,name,code';
        }

        return [
            'orders' => $filteredOrders->with($orderWith)
                ->latest()
                ->paginate(12)
                ->withQueryString(),
        ];
    }
}
