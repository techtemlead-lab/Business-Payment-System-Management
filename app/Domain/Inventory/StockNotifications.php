<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StockNotifications
{
    public function recordChange(Product $product, int $previousStock, bool $wasAvailable): void
    {
        if (! $product->active) {
            return;
        }
        if (! $wasAvailable && $product->stock > 0 && ! $product->is_stock_paused) {
            User::where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->select('id')
                ->chunkById(500, function (Collection $users) use ($product): void {
                    $timestamp = now();
                    DB::table('account_notices')->insert($users->map(fn (User $user): array => [
                        'user_id' => $user->id, 'title' => 'Product available',
                        'body' => $product->name.' ('.$product->sku.') is now available in the product showcase.',
                        'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ])->all());
                });
        }
        if (($previousStock > $product->low_stock_at && $product->stock <= $product->low_stock_at)
            || ($previousStock > 0 && $product->stock === 0)) {
            User::where('status', 'active')->whereNotIn('role', ['dealer', 'sub_dealer'])->chunkById(500, function (Collection $users) use ($product): void {
                foreach ($users as $user) {
                    if ($user->allows('inventory')) {
                        Audit::notice($user->id, $product->stock === 0 ? 'Product out of stock' : 'Low stock alert',
                            $product->name.' ('.$product->sku.'): '.$product->stock.' units remaining.');
                    }
                }
            });
        }
    }
}
