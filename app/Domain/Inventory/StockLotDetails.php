<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff fill in the batch number or expiry date a lot was recorded without. Only blanks
 * are written: a batch or date already on a lot is what a recall traces, so it never changes here.
 */
final class StockLotDetails
{
    public function __construct(private BranchOperations $branches, private StockLots $lots) {}

    /**
     * @param  array{batch_number?: ?string, expires_on?: ?string}  $details
     */
    public function fillIn(User $actor, StockLot $lot, array $details): void
    {
        $branch = $this->branches->resolveWritableBranch($actor, (int) $lot->branch_id);

        DB::transaction(function () use ($actor, $lot, $details, $branch): void {
            $product = Product::query()->whereKey($lot->product_id)->lockForUpdate()->firstOrFail();
            $this->branches->ensureBranchStock($branch, $product);
            $locked = StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $filled = array_filter([
                'batch_number' => $locked->batch_number === null ? trim((string) ($details['batch_number'] ?? '')) : '',
                'expires_on' => $locked->expires_on === null ? trim((string) ($details['expires_on'] ?? '')) : '',
            ], fn (string $value): bool => $value !== '');
            if ($filled === []) {
                throw ValidationException::withMessages(['lot' => __('stock_lots.err_nothing_to_fill')]);
            }

            $locked->forceFill($filled)->save();
            if (isset($filled['batch_number']) && $locked->reference !== null) {
                DB::table('goods_receipt_lines')->where('stock_reference', $locked->reference)->whereNull('batch_number')
                    ->update(['batch_number' => $filled['batch_number'], 'updated_at' => now()]);
            }
            $product->forceFill($this->lots->nextBatchSummary($product))->save();
            Audit::record($actor->id, 'stock.lot_details_filled', (string) $locked->id, [...$filled, 'product_id' => (int) $locked->product_id, 'branch_code' => (string) $branch->code]);
        });
    }
}
