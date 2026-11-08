<?php

namespace App\Domain\Inventory;

use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quarantine: staff mark a lot on hold so it cannot go out, then release it or write it off.
 * Takes the usual stock locks in the usual order, so a hold and a dispatch cannot cross.
 */
final class StockLotHolds
{
    public function __construct(private BranchOperations $branches, private StockService $stock) {}

    public function hold(User $actor, StockLot $lot, string $reason): void
    {
        $this->withLockedLot($actor, $lot, function (StockLot $locked, CompanyBranch $branch) use ($actor, $reason): void {
            if ($locked->isOnHold()) {
                throw ValidationException::withMessages(['lot' => __('stock_lots.err_already_on_hold')]);
            }
            $this->assertHasStock($locked);
            $locked->forceFill(['held_at' => now(), 'held_by' => $actor->id, 'hold_reason' => $reason])->save();
            Audit::record($actor->id, 'stock.lot_held', (string) $locked->id, $this->auditDetails($locked, $branch, $reason));
        });
    }

    public function release(User $actor, StockLot $lot, string $reason): void
    {
        $this->withLockedLot($actor, $lot, function (StockLot $locked, CompanyBranch $branch) use ($actor, $reason): void {
            $this->assertOnHold($locked);
            $details = [...$this->auditDetails($locked, $branch, $reason), 'held_since' => $locked->held_at?->toIso8601String(), 'hold_reason' => $locked->hold_reason];
            $locked->forceFill(['held_at' => null, 'held_by' => null, 'hold_reason' => null])->save();
            Audit::record($actor->id, 'stock.lot_released', (string) $locked->id, $details);
        });
    }

    /**
     * Removes everything left in a held lot from the books, through the normal stock-out path.
     * Only a held lot can be written off here, so nothing leaves without first being checked.
     */
    public function writeOff(User $actor, StockLot $lot, string $reason): void
    {
        $this->withLockedLot($actor, $lot, function (StockLot $locked, CompanyBranch $branch) use ($actor, $reason): void {
            $this->assertOnHold($locked);
            $this->assertHasStock($locked);
            $details = $this->auditDetails($locked, $branch, $reason);
            $this->stock->adjust($actor, [
                'product_id' => (int) $locked->product_id,
                'branch_id' => (int) $locked->branch_id,
                'quantity' => (int) $locked->quantity,
                'kind' => BranchOperations::KindProductOut,
                'reason_code' => 'write_off',
                'reason' => $reason,
                'reference' => 'lot-write-off:'.$locked->id.':'.Str::uuid(),
                'from_lot_id' => (int) $locked->id,
            ]);
            Audit::record($actor->id, 'stock.lot_written_off', (string) $locked->id, $details);
        });
    }

    /**
     * @param  Closure(StockLot, CompanyBranch): void  $change
     */
    private function withLockedLot(User $actor, StockLot $lot, Closure $change): void
    {
        $branch = $this->branches->resolveWritableBranch($actor, (int) $lot->branch_id);

        DB::transaction(function () use ($lot, $branch, $change): void {
            $product = Product::query()->whereKey($lot->product_id)->lockForUpdate()->firstOrFail();
            $this->branches->ensureBranchStock($branch, $product);
            $change(StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail(), $branch);
        });
    }

    private function assertOnHold(StockLot $lot): void
    {
        if (! $lot->isOnHold()) {
            throw ValidationException::withMessages(['lot' => __('stock_lots.err_not_on_hold')]);
        }
    }

    private function assertHasStock(StockLot $lot): void
    {
        if ((int) $lot->quantity < 1) {
            throw ValidationException::withMessages(['lot' => __('stock_lots.err_lot_empty')]);
        }
    }

    /**
     * @return array{product_id: int, branch_id: int, branch_code: string, batch_number: ?string, expires_on: ?string, quantity: int, reason: string}
     */
    private function auditDetails(StockLot $lot, CompanyBranch $branch, string $reason): array
    {
        return [
            'product_id' => (int) $lot->product_id,
            'branch_id' => (int) $branch->id,
            'branch_code' => (string) $branch->code,
            'batch_number' => $lot->batch_number,
            'expires_on' => $lot->expires_on?->toDateString(),
            'quantity' => (int) $lot->quantity,
            'reason' => $reason,
        ];
    }
}
