<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
use App\Models\CompanyBranch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;

/**
 * Purchasing section: suppliers and purchase orders, with what was ordered, received and is
 * still due on each. Orders follow the top-bar branch switcher; cost is shown only to staff
 * who may read cost, the same rule the product list uses.
 */
final class PurchasingSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'purchasing') {
            return [];
        }
        $user = $context->user;
        $branchOperations = app(BranchOperations::class);
        $visibleBranches = $branchOperations->visibleBranches($user);
        $scope = $branchOperations->scopeFor($user, $context->request, $visibleBranches);
        $showCost = $user->allows('cost_read');

        $orders = PurchaseOrder::query()
            ->with([
                'supplier:id,name',
                'branch:id,code,name',
                'creator:id,name',
                'lines.product:id,sku,name',
                'receipts' => fn ($receipts) => $receipts->orderBy('id'),
                'receipts.receiver:id,name',
                'receipts.lines.product:id,sku,name',
            ])
            ->orderByDesc('id');
        $scope->restrict($orders, 'branch_id');

        return ['purchasing' => [
            'branch_scope' => $scope->chip(),
            'show_cost' => $showCost,
            'can' => [
                'create' => $user->canAct('inventory', 'create'),
                'edit' => $user->canAct('inventory', 'edit'),
            ],
            'orders' => $orders->paginate(20)->withQueryString()
                ->through(fn (PurchaseOrder $order): array => $this->orderRow($order, $showCost)),
            'suppliers' => Supplier::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'contact', 'notes']),
            'archived_suppliers' => Supplier::query()->whereNotNull('archived_at')->orderBy('name')->get(['id', 'name', 'contact', 'notes', 'archived_at']),
            'products' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'sku', 'name']),
            'branches' => $visibleBranches
                ->filter(fn (CompanyBranch $branch): bool => (bool) $branch->is_active && (bool) $branch->is_stock_hub)
                ->map(fn (CompanyBranch $branch): array => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->values(),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(PurchaseOrder $order, bool $showCost): array
    {
        $cancelled = $order->status === PurchaseOrder::StatusCancelled;
        $lines = $order->lines->map(fn (PurchaseOrderLine $line): array => [
            'id' => $line->id,
            'sku' => $line->product?->sku,
            'name' => $line->product?->name,
            'ordered' => $line->quantity_ordered,
            'received' => $line->quantity_received,
            'due' => $cancelled ? 0 : $line->quantityDue(),
        ])->values();

        return [
            'id' => $order->id,
            'number' => $order->displayNumber(),
            'status' => $order->status,
            'awaiting_goods' => $order->isAwaitingGoods(),
            'supplier_name' => $order->supplier?->name,
            'branch_code' => $order->branch?->code,
            'branch_name' => $order->branch?->name,
            'created_by_name' => $order->creator?->name,
            'created_at' => $order->created_at,
            'sent_at' => $order->sent_at,
            'cancel_reason' => $order->cancel_reason,
            'notes' => $order->notes,
            'ordered' => $lines->sum('ordered'),
            'received' => $lines->sum('received'),
            'due' => $lines->sum('due'),
            'lines' => $lines,
            'receipts' => $order->receipts->map(fn (GoodsReceipt $receipt): array => [
                'id' => $receipt->id,
                'received_by_name' => $receipt->receiver?->name,
                'created_at' => $receipt->created_at,
                'notes' => $receipt->notes,
                'lines' => $receipt->lines->map(fn (GoodsReceiptLine $line): array => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'batch_number' => $line->batch_number,
                    'expires_on' => $line->expires_on?->toDateString(),
                    ...($showCost ? ['unit_cost' => $line->unit_cost] : []),
                ])->values(),
            ])->values(),
        ];
    }
}
