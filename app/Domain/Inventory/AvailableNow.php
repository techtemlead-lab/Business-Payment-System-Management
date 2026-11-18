<?php

namespace App\Domain\Inventory;

use App\Domain\Orders\OrderLines;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * How many units of a product are free to promise to a new order today: what can be sold
 * ({@see SellableStock}, all branches) minus the units already booked by approved orders that
 * are not sent yet. The purchase page shows it, and warns when an order asks for more.
 */
final class AvailableNow
{
    /** Orders whose unsent units are already promised to a partner. */
    public const BookingStatuses = ['approved', 'partially_dispatched'];

    /**
     * @param  iterable<Product>  $products  Products loaded with {@see Product::withSellableStock()}.
     * @return array<int, int> units free now, by product id
     */
    public static function forProducts(iterable $products): array
    {
        $sellable = [];
        foreach ($products as $product) {
            $sellable[(int) $product->id] = $product->sellableStock();
        }
        if ($sellable === []) {
            return [];
        }
        $booked = DB::table('order_lines as booked_line')
            ->join('orders as booked_order', 'booked_order.id', '=', 'booked_line.order_id')
            ->whereIn('booked_order.status', self::BookingStatuses)
            ->whereIn('booked_line.product_id', array_keys($sellable))
            ->whereRaw(OrderLines::unsentSql('booked_line'))
            ->groupBy('booked_line.product_id')
            ->selectRaw('booked_line.product_id, SUM('.OrderLines::unsentUnitsSql('booked_line').') AS units')
            ->pluck('units', 'product_id');

        $free = [];
        foreach ($sellable as $productId => $units) {
            $free[$productId] = max(0, $units - (int) ($booked[$productId] ?? 0));
        }

        return $free;
    }
}
