<?php

namespace App\Domain\Inventory;

use App\Models\BranchProductStock;
use App\Models\Product;
use App\Models\StockLot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that moves a branch's stock. Every change to a branch_product_stocks
 * quantity and to its lots happens here together, so the two can never disagree.
 *
 * Callers must already hold the same locks as all other stock code, in the same order:
 * the product row, then the branch stock row ({@see BranchOperations::ensureBranchStock()}).
 * New stock should come in through {@see StockService::stockIn()}, which takes those locks.
 *
 * @phpstan-type LotContext array{kind?: string, reference?: ?string, actor_id?: ?int, order_id?: ?string, order_line_id?: ?int, shipment_id?: ?int, prescription_id?: ?int, stock_transfer_id?: ?int}
 * @phpstan-type Allocation array{movement_id: int, lot_id: int, batch_number: ?string, expires_on: ?string, quantity: int}
 */
final class StockLots
{
    public const SourceOpening = 'opening';

    public const SourceStockIn = 'stock_in';

    public const SourceReturn = 'return';

    public const SourceTransfer = 'transfer';

    public const KindDispatch = 'dispatch';

    public const KindClinicDispense = 'clinic_dispense';

    public const KindManualOut = 'manual_out';

    public const KindTransferOut = 'transfer_out';

    public const KindReturn = 'return';

    /**
     * Stock-out reasons where goods are being thrown away or corrected, not handed to anyone.
     * Only these may touch expired lots: that is how expired stock leaves the books.
     *
     * @var list<string>
     */
    public const DisposalReasons = ['expired', 'write_off', 'damage', 'correction'];

    /**
     * Puts received goods on the shelf as a new lot and raises the branch total.
     *
     * @param  array{batch_number?: ?string, expires_on?: ?string, received_on?: ?string, unit_cost?: string|int|float|null, source?: string, parent_lot_id?: ?int}  $details
     * @param  LotContext  $context
     */
    public function receive(BranchProductStock $stock, Product $product, int $quantity, array $details = [], array $context = []): StockLot
    {
        $this->reconcile($stock, $product);
        $batchNumber = trim((string) ($details['batch_number'] ?? ''));
        $unitCost = $details['unit_cost'] ?? null;
        $source = $details['source'] ?? self::SourceStockIn;

        /** @var StockLot $lot */
        $lot = StockLot::query()->create([
            'branch_id' => $stock->branch_id,
            'product_id' => $stock->product_id,
            'batch_number' => $batchNumber !== '' ? $batchNumber : null,
            'expires_on' => $details['expires_on'] ?? null,
            'quantity' => $quantity,
            'received_quantity' => $quantity,
            'received_on' => $details['received_on'] ?? StockLot::today(),
            'unit_cost' => $unitCost === null || $unitCost === '' ? $product->cost_price : $unitCost,
            'source' => $source,
            'reference' => $context['reference'] ?? null,
            'parent_lot_id' => $details['parent_lot_id'] ?? null,
            'created_by' => $context['actor_id'] ?? null,
        ]);
        $this->ledger($lot, $quantity, ['kind' => $source, ...$context]);
        $stock->forceFill(['quantity' => (int) $stock->quantity + $quantity])->save();

        return $lot;
    }

    /**
     * Takes stock out, earliest-expiring lot first, and records which lot went where.
     * Expired lots are refused unless the goods are being disposed of. Lots on hold are refused
     * too; a disposal reaches them only after every other lot is used up.
     *
     * @param  LotContext  $context
     * @return list<Allocation>
     */
    public function take(BranchProductStock $stock, Product $product, int $quantity, array $context, bool $allowExpired = false, string $errorField = 'quantity'): array
    {
        $this->reconcile($stock, $product);
        $lots = StockLot::query()
            ->where('branch_id', $stock->branch_id)
            ->where('product_id', $stock->product_id)
            ->where('quantity', '>', 0)
            ->fefo()
            ->lockForUpdate()
            ->get();
        $candidates = $allowExpired
            ? $lots->sortBy(fn (StockLot $lot): int => $lot->isOnHold() ? 1 : 0)
            : $lots->reject(fn (StockLot $lot): bool => $lot->isExpired() || $lot->isOnHold());
        $available = (int) $candidates->sum('quantity');
        if ($available < $quantity) {
            $this->refuseShortfall($lots, $product, $available, $errorField);
        }

        $left = $quantity;
        $allocations = [];
        foreach ($candidates as $lot) {
            if ($left === 0) {
                break;
            }
            $portion = min($left, (int) $lot->quantity);
            $allocations[] = $this->takePortion($lot, $portion, $context);
            $left -= $portion;
        }
        $stock->forceFill(['quantity' => (int) $stock->quantity - $quantity])->save();

        return $allocations;
    }

    /**
     * Takes stock out of one named lot, for when the goods themselves were chosen.
     *
     * @param  LotContext  $context
     * @return list<Allocation>
     */
    public function takeFromLot(BranchProductStock $stock, Product $product, int $lotId, int $quantity, array $context): array
    {
        $this->reconcile($stock, $product);
        $lot = StockLot::query()
            ->whereKey($lotId)
            ->where('branch_id', $stock->branch_id)
            ->where('product_id', $stock->product_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ((int) $lot->quantity < $quantity) {
            throw ValidationException::withMessages(['quantity' => __('stock_lots.err_not_enough', ['product' => $product->name, 'usable' => (int) $lot->quantity])]);
        }
        $allocation = $this->takePortion($lot, $quantity, $context);
        $stock->forceFill(['quantity' => (int) $stock->quantity - $quantity])->save();

        return [$allocation];
    }

    /**
     * Puts returned goods back into the exact lots they left from.
     *
     * @param  list<array{lot_id: int, quantity: int}>  $allocations
     * @param  LotContext  $context
     */
    public function restore(BranchProductStock $stock, Product $product, array $allocations, array $context): void
    {
        $this->reconcile($stock, $product);
        $restored = 0;
        foreach ($allocations as $allocation) {
            $lot = StockLot::query()
                ->whereKey($allocation['lot_id'])
                ->where('branch_id', $stock->branch_id)
                ->where('product_id', $stock->product_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lot->forceFill(['quantity' => (int) $lot->quantity + (int) $allocation['quantity']])->save();
            $this->ledger($lot, (int) $allocation['quantity'], ['kind' => self::KindReturn, ...$context]);
            $restored += (int) $allocation['quantity'];
        }
        $stock->forceFill(['quantity' => (int) $stock->quantity + $restored])->save();
    }

    /**
     * How many units may still leave the branch for a customer.
     */
    public function usableQuantity(BranchProductStock $stock, Product $product): int
    {
        $this->reconcile($stock, $product);

        return (int) StockLot::query()
            ->where('branch_id', $stock->branch_id)
            ->where('product_id', $stock->product_id)
            ->usable()
            ->sum('quantity');
    }

    /**
     * Stamps the shipment on the lot records once the shipment row exists.
     *
     * @param  list<array{lots?: list<array{movement_id?: int}>}>  $shipmentLines
     */
    public function linkShipment(array $shipmentLines, int $shipmentId): void
    {
        $ids = [];
        foreach ($shipmentLines as $line) {
            foreach ($line['lots'] ?? [] as $lot) {
                if (isset($lot['movement_id'])) {
                    $ids[] = (int) $lot['movement_id'];
                }
            }
        }
        if ($ids !== []) {
            DB::table('stock_lot_movements')->whereIn('id', $ids)->update(['shipment_id' => $shipmentId, 'updated_at' => now()]);
        }
    }

    /**
     * Keeps the lots equal to the branch total when something moved the total without
     * going through here: legacy attribution in ensureBranchStock(), a direct import, or
     * another feature. A surplus becomes an opening lot with the product's batch and
     * expiry; a deficit comes off the lots that would have gone out last.
     */
    public function reconcile(BranchProductStock $stock, Product $product): void
    {
        $lots = StockLot::query()
            ->where('branch_id', $stock->branch_id)
            ->where('product_id', $stock->product_id)
            ->where('quantity', '>', 0)
            ->fefo()
            ->lockForUpdate()
            ->get();
        $difference = (int) $stock->quantity - (int) $lots->sum('quantity');
        if ($difference > 0) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->create([
                'branch_id' => $stock->branch_id,
                'product_id' => $stock->product_id,
                'batch_number' => $product->batch_number,
                'expires_on' => $product->getAttribute('expires_on')?->toDateString(),
                'quantity' => $difference,
                'received_quantity' => $difference,
                'received_on' => StockLot::today(),
                'unit_cost' => $product->cost_price,
                'source' => self::SourceOpening,
            ]);
            $this->ledger($lot, $difference, ['kind' => self::SourceOpening]);

            return;
        }
        $excess = -$difference;
        foreach ($lots->reverse()->sortBy(fn (StockLot $lot): int => $lot->isOnHold() ? 1 : 0) as $lot) {
            if ($excess === 0) {
                break;
            }
            $portion = min($excess, (int) $lot->quantity);
            $lot->forceFill(['quantity' => (int) $lot->quantity - $portion])->save();
            $this->ledger($lot, -$portion, ['kind' => 'reconcile']);
            $excess -= $portion;
        }
    }

    /**
     * The batch and expiry the catalog shows: the lot that will go out next, company-wide.
     *
     * @return array{batch_number?: string, expires_on?: string}
     */
    public function nextBatchSummary(Product $product): array
    {
        $next = StockLot::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->where(fn ($lots) => $lots->whereNotNull('batch_number')->orWhereNotNull('expires_on'))
            ->usable()
            ->fefo()
            ->first();

        return array_filter([
            'batch_number' => $next?->batch_number,
            'expires_on' => $next?->expires_on?->toDateString(),
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * Explains a shortfall. When expired stock is the reason, the message says so and names the batches.
     *
     * @param  Collection<int, StockLot>  $lots
     */
    private function refuseShortfall(Collection $lots, Product $product, int $available, string $errorField): never
    {
        $expired = $lots->filter(fn (StockLot $lot): bool => $lot->isExpired());
        if ($expired->isNotEmpty()) {
            throw ValidationException::withMessages([$errorField => __('stock_lots.err_expired_refused', [
                'product' => $product->name,
                'expired' => (int) $expired->sum('quantity'),
                'batches' => $expired->map(fn (StockLot $lot): string => $lot->batch_number ?? __('stock_lots.no_batch'))->unique()->implode(', '),
                'usable' => $available,
            ])]);
        }

        $held = $lots->filter(fn (StockLot $lot): bool => $lot->isOnHold());
        if ($held->isNotEmpty()) {
            throw ValidationException::withMessages([$errorField => __('stock_lots.err_on_hold_refused', [
                'product' => $product->name,
                'held' => (int) $held->sum('quantity'),
                'batches' => $held->map(fn (StockLot $lot): string => $lot->batch_number ?? __('stock_lots.no_batch'))->unique()->implode(', '),
                'usable' => $available,
            ])]);
        }

        throw ValidationException::withMessages([$errorField => __('stock_lots.err_not_enough', ['product' => $product->name, 'usable' => $available])]);
    }

    /**
     * @param  LotContext  $context
     * @return Allocation
     */
    private function takePortion(StockLot $lot, int $portion, array $context): array
    {
        $lot->forceFill(['quantity' => (int) $lot->quantity - $portion])->save();

        return [
            'movement_id' => $this->ledger($lot, -$portion, $context),
            'lot_id' => (int) $lot->id,
            'batch_number' => $lot->batch_number,
            'expires_on' => $lot->expires_on?->toDateString(),
            'quantity' => $portion,
        ];
    }

    /**
     * @param  LotContext  $context
     */
    private function ledger(StockLot $lot, int $quantity, array $context): int
    {
        return (int) DB::table('stock_lot_movements')->insertGetId([
            'lot_id' => $lot->id,
            'branch_id' => $lot->branch_id,
            'product_id' => $lot->product_id,
            'quantity' => $quantity,
            'kind' => $context['kind'] ?? self::KindManualOut,
            'reference' => $context['reference'] ?? null,
            'order_id' => $context['order_id'] ?? null,
            'order_line_id' => $context['order_line_id'] ?? null,
            'shipment_id' => $context['shipment_id'] ?? null,
            'prescription_id' => $context['prescription_id'] ?? null,
            'stock_transfer_id' => $context['stock_transfer_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
