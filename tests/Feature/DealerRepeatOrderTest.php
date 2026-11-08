<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DealerRepeatOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_repeat_order_uses_todays_price_and_stock(): void
    {
        $dealer = User::factory()->dealer()->create();
        $sameProduct = Product::factory()->create(['active' => true, 'dealer_price' => '100.00', 'stock' => 50]);
        $costlier = Product::factory()->create(['active' => true, 'dealer_price' => '150.00', 'stock' => 50]);
        $scarce = Product::factory()->create(['active' => true, 'dealer_price' => '10.00', 'stock' => 2]);
        $discontinued = Product::factory()->create(['active' => false, 'stock' => 50]);
        $soldOut = Product::factory()->create(['active' => true, 'stock' => 0]);
        $order = $this->pastOrder($dealer, 'delivered', [
            [$sameProduct, 3, '100.00'],
            [$costlier, 2, '120.00'],
            [$scarce, 9, '10.00'],
            [$discontinued, 1, '40.00'],
            [$soldOut, 1, '40.00'],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->has('data.repeat_orders', 1)
                ->where('data.repeat_orders.0.order_id', $order->id)
                ->has('data.repeat_orders.0.items', 3)
                ->where('data.repeat_orders.0.items.0.product.id', $sameProduct->id)
                ->where('data.repeat_orders.0.items.0.quantity', 3)
                ->where('data.repeat_orders.0.items.0.price_changed', false)
                ->where('data.repeat_orders.0.items.1.product.price', '150.00')
                ->where('data.repeat_orders.0.items.1.price_changed', true)
                ->where('data.repeat_orders.0.items.2.quantity', 2)
                ->where('data.repeat_orders.0.items.2.last_quantity', 9)
                ->where('data.repeat_orders.0.items.2.quantity_reduced', true)
                ->where('data.repeat_orders.0.skipped', [
                    ['name' => $discontinued->name, 'reason' => 'not_sold'],
                    ['name' => $soldOut->name, 'reason' => 'out_of_stock'],
                ]));
    }

    public function test_cancelled_orders_and_other_partners_orders_are_not_offered(): void
    {
        $dealer = User::factory()->dealer()->create();
        $other = User::factory()->dealer()->create();
        $product = Product::factory()->create(['active' => true, 'stock' => 50]);
        $this->pastOrder($dealer, 'cancelled', [[$product, 1, '10.00']]);
        $this->pastOrder($other, 'delivered', [[$product, 1, '10.00']]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.repeat_orders', []));
    }

    public function test_sub_dealer_gets_their_own_price_and_the_panel(): void
    {
        $subDealer = User::factory()->subDealer()->create();
        $product = Product::factory()->create(['active' => true, 'stock' => 50, 'sub_dealer_price' => '90.00', 'dealer_price' => '80.00']);
        $this->pastOrder($subDealer, 'delivered', [[$product, 1, '90.00']]);

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.repeat_orders.0.items.0.product.price', '90.00')
                ->where('data.repeat_orders.0.items.0.price_changed', false));
    }

    /**
     * @param  list<array{0: Product, 1: int, 2: string}>  $lines
     */
    private function pastOrder(User $buyer, string $status, array $lines): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        foreach ($lines as [$product, $quantity, $unitPrice]) {
            $order->lines()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $quantity,
                'mrp' => $product->mrp,
                'unit_price' => $unitPrice,
                'total' => $unitPrice,
            ]);
        }

        return $order;
    }
}
