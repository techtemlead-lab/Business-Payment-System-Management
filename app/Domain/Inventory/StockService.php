<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockService
{
    public function __construct(private StockNotifications $notifications) {}

    /**
     * @param  array{product_id: int, quantity: int, reason: string, reference: string}  $data
     */
    public function adjust(User $actor, array $data): Product
    {
        abort_unless($actor->allows('inventory'), 403);
        try {
            return DB::transaction(function () use ($actor, $data): Product {
                $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
                if (DB::table('stock_movements')->where('reference', $data['reference'])->exists()) {
                    throw ValidationException::withMessages(['reference' => __('portal.err_stock_ref_dup')]);
                }
                $quantity = (int) $data['quantity'];
                if ($quantity === 0 || abs($quantity) > 100000 || $product->stock + $quantity < 0 || $product->stock + $quantity > 2147483647) {
                    throw ValidationException::withMessages(['quantity' => __('portal.err_stock_nonnegative')]);
                }
                $previousStock = $product->stock;
                $product->increment('stock', $quantity);
                DB::table('stock_movements')->insert([
                    'product_id' => $product->id, 'quantity' => $quantity, 'reason' => $data['reason'],
                    'reference' => $data['reference'], 'kind' => $quantity > 0 ? 'receipt' : 'adjustment',
                    'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                Audit::record($actor->id, 'stock.adjusted', (string) $product->id, ['quantity' => $quantity, 'reason' => $data['reason']]);
                $this->notifications->recordChange($product, $previousStock, $previousStock > 0 && ! $product->is_stock_paused);

                return $product->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['reference' => __('portal.err_stock_ref_dup')]);
            }
            throw $exception;
        }
    }
}
