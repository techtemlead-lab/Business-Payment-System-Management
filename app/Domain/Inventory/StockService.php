<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class StockService
{
    public function __construct(private BranchOperations $branches) {}

    /**
     * @param  array{
     *   product_id: int,
     *   quantity: int,
     *   reason: string,
     *   reference: string,
     *   branch_id?: int,
     *   kind?: string,
     *   reason_code?: string,
     *   expires_on?: string|null,
     *   batch_number?: string|null,
     *   received_on?: string|null,
     *   unit_cost?: string|int|float|null
     * }  $data
     */
    public function adjust(User $actor, array $data): Product
    {
        try {
            return $this->branches->adjustStock($actor, $data);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['reference' => __('portal.err_stock_ref_dup')]);
            }
            throw $exception;
        }
    }

    /**
     * The one way to bring new goods into a branch with their lot details. Creates the
     * lot, raises the branch and company totals, and writes the movement and audit rows.
     * Purchase receipts, stock counts and anything else adding stock should call this.
     * The reference must be unique, so a repeated call is refused instead of counted twice.
     *
     * @param  array{
     *   product_id: int,
     *   quantity: int,
     *   reference: string,
     *   branch_id?: int|null,
     *   batch_number?: string|null,
     *   expires_on?: string|null,
     *   received_on?: string|null,
     *   unit_cost?: string|int|float|null,
     *   reason?: string,
     *   reason_code?: string
     * }  $data
     * @return StockLot The lot that was created.
     */
    public function stockIn(User $actor, array $data): StockLot
    {
        if (filled($data['expires_on'] ?? null) && (string) $data['expires_on'] < StockLot::today()) {
            throw ValidationException::withMessages(['expires_on' => __('portal.err_stock_expiry_past')]);
        }
        $reasonCode = $data['reason_code'] ?? 'supplier_receipt';
        $this->adjust($actor, [
            ...$data,
            'kind' => BranchOperations::KindProductIn,
            'reason_code' => $reasonCode,
            'reason' => $data['reason'] ?? $reasonCode,
        ]);

        return StockLot::query()->where('reference', $data['reference'])->firstOrFail();
    }
}
