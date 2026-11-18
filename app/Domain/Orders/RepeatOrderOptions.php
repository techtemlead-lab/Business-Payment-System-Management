<?php

namespace App\Domain\Orders;

use App\Domain\Catalog\ProductSizeGroups;
use App\Domain\Pricing\ProductPricing;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A partner's recent orders, ready to be put back in the cart: every line carries today's price,
 * never the old one. Nothing is saved here; the order still goes through the
 * normal checkout, which prices it again on the server and asks for payment and proof.
 */
final class RepeatOrderOptions
{
    /** How many past orders the purchase page offers. */
    public const RecentOrders = 3;

    public function __construct(private ProductPricing $pricing) {}

    /**
     * @return list<array{
     *   order_id: string,
     *   number: string,
     *   ordered_on: string,
     *   total: string,
     *   names: list<string>,
     *   items: list<array{product: array<string, mixed>, quantity: int, last_quantity: int, last_unit_price: string, price_changed: bool}>,
     *   skipped: list<array{name: string, reason: string}>
     * }>
     */
    public function for(User $buyer): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('user_id', $buyer->id)
            ->where('is_opening_balance', false)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->latest()
            ->orderByDesc('id')
            ->limit(self::RecentOrders)
            ->with('lines:id,order_id,product_id,name,quantity,unit_price,is_free')
            ->get(['id', 'number', 'created_at', 'total']);
        if ($orders->isEmpty()) {
            return [];
        }
        $productIds = $orders->flatMap(fn (Order $order) => $order->lines->pluck('product_id'))->unique()->values()->all();
        /** @var Collection<int, Product> $products */
        $products = Product::query()->withSellableStock()->whereIn('id', $productIds)->get()->keyBy('id');

        return $orders->map(fn (Order $order): array => $this->option($order, $products, $buyer))
            ->filter(fn (array $option): bool => $option['items'] !== [] || $option['skipped'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     */
    private function option(Order $order, Collection $products, User $buyer): array
    {
        $items = [];
        $skipped = [];
        /** @var OrderLine $line */
        foreach ($order->chargedLines() as $line) {
            $product = $products->get($line->product_id);
            $reason = $this->unavailableReason($product);
            $unitPrice = $reason === null ? $this->priceFor($product, $buyer) : null;
            if ($reason === null && $unitPrice === null) {
                $reason = 'no_price';
            }
            if ($reason !== null) {
                $skipped[] = ['name' => (string) $line->name, 'reason' => $reason];

                continue;
            }
            $items[] = $this->item($line, $product, $unitPrice);
        }

        return [
            'order_id' => (string) $order->id,
            'number' => (string) $order->number,
            'ordered_on' => $order->created_at?->toDateString() ?? '',
            'total' => Money::decimal(Money::cents((string) $order->total)),
            'names' => $order->chargedLines()->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(),
            'items' => $items,
            'skipped' => $skipped,
        ];
    }

    /** @return array<string, mixed> */
    private function item(OrderLine $line, Product $product, int $unitPrice): array
    {
        $lastQuantity = (int) $line->quantity;

        // No stock figure or stock status is returned: a partner must not learn from this list
        // whether a product is in stock. The company decides at fulfilment what can be sent.
        return [
            'product' => [
                ...$product->only(['id', 'sku', 'name', 'category', 'sub_category', 'strength', 'unit', 'mrp']),
                'price' => Money::decimal($unitPrice),
                'size_label' => $product->product_group_id !== null ? ProductSizeGroups::buttonLabel($product) : null,
            ],
            'quantity' => $lastQuantity,
            'last_quantity' => $lastQuantity,
            'last_unit_price' => (string) $line->unit_price,
            'price_changed' => Money::cents((string) $line->unit_price) !== $unitPrice,
        ];
    }

    private function unavailableReason(?Product $product): ?string
    {
        // A product whose ordering is paused cannot be ordered, so it is reported like one no longer sold
        // rather than as out of stock.
        return match (true) {
            $product === null, ! $product->active, (bool) $product->is_stock_paused => 'not_sold',
            default => null,
        };
    }

    private function priceFor(Product $product, User $buyer): ?int
    {
        try {
            return $this->pricing->unitPrice($product, $buyer);
        } catch (ValidationException) {
            return null;
        }
    }
}
