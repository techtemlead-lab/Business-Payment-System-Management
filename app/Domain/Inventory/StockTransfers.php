<?php

namespace App\Domain\Inventory;

use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moving stock between branches: request, send (stock leaves the source and is in
 * transit, so it is in no branch's total), receive (stock enters the destination in the
 * same batches). Locks follow the rest of the stock code: transfer, product, branch stock.
 */
final class StockTransfers
{
    public function __construct(
        private BranchOperations $branches,
        private StockLots $lots,
        private StockNotifications $notifications,
    ) {}

    /**
     * @param  array{product_id: int, quantity: int, source_branch_id: int, destination_branch_id: int, note?: ?string}  $data
     */
    public function request(User $actor, array $data): StockTransfer
    {
        abort_unless($actor->allows('inventory'), 403);
        if ((int) $data['source_branch_id'] === (int) $data['destination_branch_id']) {
            throw ValidationException::withMessages(['destination_branch_id' => __('stock_lots.err_same_branch')]);
        }
        $source = $this->branches->resolveWritableBranch($actor, (int) $data['source_branch_id']);
        $destination = $this->branches->resolveWritableBranch($actor, (int) $data['destination_branch_id']);

        return DB::transaction(function () use ($actor, $data, $source, $destination): StockTransfer {
            /** @var StockTransfer $transfer */
            $transfer = StockTransfer::query()->create([
                'number' => 'TR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'product_id' => (int) $data['product_id'],
                'source_branch_id' => $source->id,
                'destination_branch_id' => $destination->id,
                'quantity' => (int) $data['quantity'],
                'status' => StockTransfer::StatusRequested,
                'note' => filled($data['note'] ?? null) ? Str::limit(trim((string) $data['note']), 500, '') : null,
                'requested_by' => $actor->id,
            ]);
            $this->audit($actor, 'stock.transfer_requested', $transfer, $source, $destination);

            return $transfer;
        }, 3);
    }

    /**
     * Stock leaves the source branch, earliest-expiring lots first; expired lots stay behind.
     */
    public function send(User $actor, StockTransfer $transfer): StockTransfer
    {
        abort_unless($actor->allows('inventory'), 403);

        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            $locked = $this->lockInStatus($transfer, StockTransfer::StatusRequested);
            $source = $this->branches->resolveWritableBranch($actor, (int) $locked->source_branch_id);
            $product = Product::query()->whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $stock = $this->branches->ensureBranchStock($source, $product);
            $previous = (int) $product->stock;
            $taken = $this->lots->take($stock, $product, (int) $locked->quantity, [
                'kind' => StockLots::KindTransferOut,
                'actor_id' => $actor->id,
                'stock_transfer_id' => $locked->id,
                'reference' => $locked->number,
            ], errorField: 'transfer');
            $this->afterStockMoved($actor, $product, $previous, $source, -(int) $locked->quantity, 'transfer_out', 'transfer-out:'.$locked->number, $locked->number);
            $locked->forceFill(['status' => StockTransfer::StatusInTransit, 'sent_by' => $actor->id, 'sent_at' => now()])->save();
            $this->audit($actor, 'stock.transfer_sent', $locked, $source, null, ['lots' => $taken]);

            return $locked;
        }, 3);
    }

    /**
     * Stock enters the destination in the same batches, expiries and costs it left with.
     */
    public function receive(User $actor, StockTransfer $transfer): StockTransfer
    {
        abort_unless($actor->allows('inventory'), 403);

        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            $locked = $this->lockInStatus($transfer, StockTransfer::StatusInTransit);
            $destination = $this->branches->resolveWritableBranch($actor, (int) $locked->destination_branch_id);
            $product = Product::query()->whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $stock = $this->branches->ensureBranchStock($destination, $product);
            $previous = (int) $product->stock;
            $moved = DB::table('stock_lot_movements')
                ->where('stock_transfer_id', $locked->id)
                ->where('kind', StockLots::KindTransferOut)
                ->orderBy('id')
                ->get();
            $sourceLots = StockLot::query()->whereIn('id', $moved->pluck('lot_id'))->get()->keyBy('id');
            foreach ($moved as $movement) {
                $lot = $sourceLots[$movement->lot_id];
                $this->lots->receive($stock, $product, -(int) $movement->quantity, [
                    'batch_number' => $lot->batch_number,
                    'expires_on' => $lot->expires_on?->toDateString(),
                    'unit_cost' => $lot->unit_cost,
                    'source' => StockLots::SourceTransfer,
                    'parent_lot_id' => $lot->id,
                ], [
                    'kind' => 'transfer_in',
                    'actor_id' => $actor->id,
                    'stock_transfer_id' => $locked->id,
                    'reference' => $locked->number,
                ]);
            }
            $this->afterStockMoved($actor, $product, $previous, $destination, (int) $locked->quantity, 'transfer_in', 'transfer-in:'.$locked->number, $locked->number);
            $locked->forceFill(['status' => StockTransfer::StatusReceived, 'received_by' => $actor->id, 'received_at' => now()])->save();
            $this->audit($actor, 'stock.transfer_received', $locked, null, $destination);

            return $locked;
        }, 3);
    }

    /**
     * Only a request that has not been sent can be cancelled; stock in transit must be received.
     */
    public function cancel(User $actor, StockTransfer $transfer): StockTransfer
    {
        abort_unless($actor->allows('inventory'), 403);

        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            $locked = $this->lockInStatus($transfer, StockTransfer::StatusRequested);
            $this->branches->resolveWritableBranch($actor, (int) $locked->source_branch_id);
            $locked->forceFill(['status' => StockTransfer::StatusCancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();
            $this->audit($actor, 'stock.transfer_cancelled', $locked);

            return $locked;
        }, 3);
    }

    private function lockInStatus(StockTransfer $transfer, string $status): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $status) {
            throw ValidationException::withMessages(['transfer' => __('stock_lots.err_transfer_status')]);
        }

        return $locked;
    }

    private function afterStockMoved(User $actor, Product $product, int $previous, CompanyBranch $branch, int $quantity, string $kind, string $reference, string $number): void
    {
        $companyTotal = (int) BranchProductStock::query()->where('product_id', $product->id)->sum('quantity');
        $product->forceFill(['stock' => $companyTotal])->save();
        DB::table('stock_movements')->insert([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $quantity,
            'kind' => $kind,
            'reference' => $reference,
            'reason' => $number,
            'actor_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->notifications->recordChange($product, $previous, $previous > 0 && ! $product->is_stock_paused);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $actor, string $action, StockTransfer $transfer, ?CompanyBranch $source = null, ?CompanyBranch $destination = null, array $extra = []): void
    {
        Audit::record($actor->id, $action, (string) $transfer->id, [
            'number' => $transfer->number,
            'product_id' => $transfer->product_id,
            'quantity' => $transfer->quantity,
            'source_branch_id' => $transfer->source_branch_id,
            'destination_branch_id' => $transfer->destination_branch_id,
            ...($source ? ['source_branch_code' => $source->code] : []),
            ...($destination ? ['destination_branch_code' => $destination->code] : []),
            ...$extra,
        ]);
    }
}
