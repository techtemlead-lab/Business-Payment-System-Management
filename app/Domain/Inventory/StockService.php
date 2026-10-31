<?php

namespace App\Domain\Inventory;

use App\Models\Product;
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
     *   expires_on?: string|null
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
}
