<?php

namespace App\Domain\Inventory;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Suppliers, purchase orders and goods received against them. Received goods reach the
 * shelf only through {@see StockService}, so branch stock, the company total, the movement
 * log and the low-stock notices stay the same as for any other product-in.
 */
final class PurchasingService
{
    public function __construct(
        private BranchOperations $branches,
        private StockService $stock,
    ) {}

    /**
     * @param  array{name:string,contact?:?string,notes?:?string}  $data
     */
    public function saveSupplier(User $actor, array $data, ?Supplier $supplier = null): Supplier
    {
        $this->assertMay($actor, $supplier ? 'edit' : 'create');

        return DB::transaction(function () use ($actor, $data, $supplier): Supplier {
            $payload = [
                'name' => trim($data['name']),
                'contact' => $data['contact'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];
            if ($supplier) {
                $supplier->fill($payload)->save();
            } else {
                $supplier = Supplier::query()->create([...$payload, 'created_by' => $actor->id]);
            }
            Audit::record($actor->id, 'supplier.saved', (string) $supplier->id, ['name' => $supplier->name]);

            return $supplier;
        });
    }

    /**
     * @param  array{supplier_id:int,branch_id:int,notes?:?string,lines:list<array{product_id:int,quantity:int}>}  $data
     */
    public function createOrder(User $actor, array $data): PurchaseOrder
    {
        $this->assertMay($actor, 'create');
        $branch = $this->branches->resolveWritableBranch($actor, (int) $data['branch_id']);

        return DB::transaction(function () use ($actor, $data, $branch): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $branch->id,
                'status' => PurchaseOrder::StatusDraft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            foreach ($data['lines'] as $line) {
                $order->lines()->create([
                    'product_id' => $line['product_id'],
                    'quantity_ordered' => $line['quantity'],
                ]);
            }
            Audit::record($actor->id, 'purchase_order.created', (string) $order->id, [
                'number' => $order->displayNumber(),
                'supplier_id' => $order->supplier_id,
                'branch_code' => $branch->code,
                'lines' => $data['lines'],
            ]);

            return $order;
        });
    }

    public function sendOrder(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        $this->assertMay($actor, 'edit');
        $this->branches->resolveWritableBranch($actor, (int) $order->branch_id);

        return DB::transaction(function () use ($actor, $order): PurchaseOrder {
            $locked = $this->lockOrder($order);
            if ($locked->status !== PurchaseOrder::StatusDraft) {
                throw ValidationException::withMessages(['order' => __('purchasing.err_only_draft_can_be_sent')]);
            }
            $locked->forceFill(['status' => PurchaseOrder::StatusSent, 'sent_at' => now()])->save();
            Audit::record($actor->id, 'purchase_order.sent', (string) $locked->id, ['number' => $locked->displayNumber()]);

            return $locked;
        });
    }

    /**
     * Stops an order. Goods already received stay in stock and stay on the record; the order
     * simply expects nothing more.
     */
    public function cancelOrder(User $actor, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        $this->assertMay($actor, 'edit');
        $this->branches->resolveWritableBranch($actor, (int) $order->branch_id);

        return DB::transaction(function () use ($actor, $order, $reason): PurchaseOrder {
            $locked = $this->lockOrder($order);
            if (! in_array($locked->status, [PurchaseOrder::StatusDraft, PurchaseOrder::StatusSent, PurchaseOrder::StatusPartlyReceived], true)) {
                throw ValidationException::withMessages(['order' => __('purchasing.err_cannot_cancel')]);
            }
            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => PurchaseOrder::StatusCancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();
            Audit::record($actor->id, 'purchase_order.cancelled', (string) $locked->id, [
                'number' => $locked->displayNumber(),
                'previous_status' => $previousStatus,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * Book goods in against an order, in full or in part. One line may appear more than once
     * when it arrives in several batches. Sending the same reference twice books it once.
     *
     * @param  array{
     *   reference:string,
     *   notes?:?string,
     *   lines:list<array{purchase_order_line_id:int,quantity:int,batch_number?:?string,expires_on:string,unit_cost:numeric-string|int|float}>
     * }  $data
     */
    public function receive(User $actor, PurchaseOrder $order, array $data): GoodsReceipt
    {
        $this->assertMay($actor, 'create');
        $this->branches->resolveWritableBranch($actor, (int) $order->branch_id);

        return DB::transaction(function () use ($actor, $order, $data): GoodsReceipt {
            $locked = $this->lockOrder($order);

            $earlier = GoodsReceipt::query()->where('reference', $data['reference'])->first();
            if ($earlier) {
                if ((int) $earlier->purchase_order_id !== (int) $locked->id) {
                    throw ValidationException::withMessages(['reference' => __('portal.err_stock_ref_dup')]);
                }

                return $earlier;
            }

            if (! $locked->isAwaitingGoods()) {
                throw ValidationException::withMessages(['order' => __('purchasing.err_not_awaiting_goods')]);
            }

            /** @var Collection<int, PurchaseOrderLine> $lines */
            $lines = PurchaseOrderLine::query()
                ->with('product:id,sku,name')
                ->where('purchase_order_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $this->assertNotOverReceived($lines, $data['lines']);

            $receipt = GoodsReceipt::query()->create([
                'reference' => $data['reference'],
                'purchase_order_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'notes' => $data['notes'] ?? null,
                'received_by' => $actor->id,
            ]);

            foreach ($data['lines'] as $row) {
                /** @var PurchaseOrderLine $line */
                $line = $lines->get((int) $row['purchase_order_line_id']);
                $stockReference = (string) Str::uuid();
                $batchNumber = trim((string) ($row['batch_number'] ?? ''));
                $this->stock->stockIn($actor, [
                    'product_id' => $line->product_id,
                    'branch_id' => (int) $locked->branch_id,
                    'quantity' => (int) $row['quantity'],
                    'reason_code' => 'supplier_receipt',
                    'reason' => $locked->displayNumber(),
                    'reference' => $stockReference,
                    'expires_on' => $row['expires_on'],
                    'batch_number' => $batchNumber,
                    'unit_cost' => $row['unit_cost'],
                ]);
                $receipt->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'quantity' => (int) $row['quantity'],
                    'batch_number' => $batchNumber !== '' ? $batchNumber : null,
                    'expires_on' => $row['expires_on'],
                    'unit_cost' => $row['unit_cost'],
                    'stock_reference' => $stockReference,
                ]);
                $line->quantity_received += (int) $row['quantity'];
                $line->save();
            }

            $fullyReceived = $lines->every(fn (PurchaseOrderLine $line): bool => $line->quantityDue() === 0);
            $locked->forceFill(['status' => $fullyReceived ? PurchaseOrder::StatusReceived : PurchaseOrder::StatusPartlyReceived])->save();

            Audit::record($actor->id, 'purchase_order.received', (string) $locked->id, [
                'number' => $locked->displayNumber(),
                'receipt_id' => $receipt->id,
                'status' => $locked->status,
                'lines' => array_map(fn (array $row): array => [
                    'purchase_order_line_id' => (int) $row['purchase_order_line_id'],
                    'quantity' => (int) $row['quantity'],
                    'batch_number' => trim((string) ($row['batch_number'] ?? '')),
                    'expires_on' => $row['expires_on'],
                ], $data['lines']),
            ]);

            return $receipt;
        });
    }

    /**
     * Refuses the whole receipt if any line would bring in more than is still due, adding up
     * the batches of that line first.
     *
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  list<array{purchase_order_line_id:int,quantity:int}>  $rows
     */
    private function assertNotOverReceived($lines, array $rows): void
    {
        $incoming = [];
        foreach ($rows as $row) {
            $lineId = (int) $row['purchase_order_line_id'];
            if (! $lines->has($lineId)) {
                throw ValidationException::withMessages(['lines' => __('purchasing.err_line_not_on_order')]);
            }
            $incoming[$lineId] = ($incoming[$lineId] ?? 0) + (int) $row['quantity'];
        }
        foreach ($incoming as $lineId => $quantity) {
            /** @var PurchaseOrderLine $line */
            $line = $lines->get($lineId);
            if ($quantity > $line->quantityDue()) {
                throw ValidationException::withMessages(['lines' => __('purchasing.err_over_receipt', [
                    'sku' => $line->product->sku ?? (string) $line->product_id,
                    'due' => (string) $line->quantityDue(),
                ])]);
            }
        }
    }

    private function lockOrder(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function assertMay(User $actor, string $action): void
    {
        $this->branches->assertCompanyHandler($actor);
        abort_unless($actor->canAct('inventory', $action), 403, __('portal.err_staff_action_denied'));
    }
}
