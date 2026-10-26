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
            User::where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->select(['id', 'locale'])
                ->chunkById(500, function (Collection $users) use ($product): void {
                    $timestamp = now();
                    DB::table('account_notices')->insert($users->map(function (User $user) use ($product, $timestamp): array {
                        $locale = $user->preferredLocale();
                        $replace = ['name' => $product->name, 'sku' => $product->sku];

                        return [
                            'user_id' => $user->id,
                            'title' => __('portal.notice_product_available_title', [], $locale),
                            'body' => __('portal.notice_product_available_body', $replace, $locale),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    })->all());
                });
        }
        if ($wasAvailable && ($product->stock <= 0 || $product->is_stock_paused)) {
            User::where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->select(['id', 'locale'])
                ->chunkById(500, function (Collection $users) use ($product): void {
                    $timestamp = now();
                    $paused = (bool) $product->is_stock_paused;
                    DB::table('account_notices')->insert($users->map(function (User $user) use ($product, $timestamp, $paused): array {
                        $locale = $user->preferredLocale();
                        $replace = ['name' => $product->name, 'sku' => $product->sku];

                        return [
                            'user_id' => $user->id,
                            'title' => __(
                                $paused ? 'portal.notice_product_ordering_paused_title' : 'portal.notice_product_unavailable_title',
                                [],
                                $locale,
                            ),
                            'body' => __(
                                $paused ? 'portal.notice_product_ordering_paused_body' : 'portal.notice_product_unavailable_body',
                                $replace,
                                $locale,
                            ),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    })->all());
                });
        }
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
