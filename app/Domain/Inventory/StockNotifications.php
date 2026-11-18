<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;

final class StockNotifications
{
    /**
     * Whether this product can be ordered and sent right now: ordering is not paused and some
     * units can really be sent. Callers ask before changing the stock and hand the answer to recordChange().
     */
    public function isAvailable(Product $product): bool
    {
        return ! $product->is_stock_paused && $product->sellableStock() > 0;
    }

    public function recordChange(Product $product, int $previousStock, bool $wasAvailable): void
    {
        if (! $product->active) {
            return;
        }
        // Dealers and sub-dealers are deliberately not told when a product runs out, comes back or is
        // paused: stock is for company staff only. $wasAvailable is kept so callers need not change.
        if (($previousStock > $product->low_stock_at && $product->stock <= $product->low_stock_at)
            || ($previousStock > 0 && $product->stock === 0)) {
            User::where('status', 'active')->whereNotIn('role', ['dealer', 'sub_dealer'])->chunkById(500, function (Collection $users) use ($product): void {
                foreach ($users as $user) {
                    if ($user->allows('inventory')) {
                        $locale = $user->preferredLocale();
                        $titleKey = $product->stock === 0
                            ? 'portal.notice_product_out_of_stock_title'
                            : 'portal.notice_low_stock_title';
                        Audit::notice(
                            $user->id,
                            __($titleKey, [], $locale),
                            __('portal.notice_stock_units_body', [
                                'name' => $product->name,
                                'sku' => $product->sku,
                                'count' => $product->stock,
                            ], $locale),
                        );
                    }
                }
            });
        }
    }
}
