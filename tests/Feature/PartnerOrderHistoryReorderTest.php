<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerOrderHistoryReorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_new_order_page_lists_only_the_signed_in_partners_three_latest_orders(): void
    {
        $dealer = User::factory()->dealer()->create();
        $other = User::factory()->dealer()->create();
        $product = Product::factory()->create(['active' => true, 'stock' => 50]);
        $mine = [];
        foreach (range(1, 4) as $day) {
            $mine[] = $this->pastOrder($dealer, 'delivered', [[$product, 1, '10.00']], "2026-11-0{$day} 10:00:00");
        }
        $foreign = $this->pastOrder($other, 'delivered', [[$product, 1, '10.00']], '2026-11-20 10:00:00');

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.repeat_orders', 3)
                ->where('data.repeat_orders.0.order_id', $mine[3]->id)
                ->where('data.repeat_orders.1.order_id', $mine[2]->id)
                ->where('data.repeat_orders.2.order_id', $mine[1]->id)
                ->where('data.repeat_orders.0.status', 'delivered')
                ->where('data.repeat_orders', fn ($orders) => ! collect($orders)->pluck('order_id')->contains($foreign->id)));
    }

    public function test_reordering_another_partners_order_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create();
        $other = User::factory()->dealer()->create();
        $product = Product::factory()->create(['active' => true, 'stock' => 50]);
        $foreign = $this->pastOrder($other, 'delivered', [[$product, 2, '10.00']]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->getJson('/orders/'.$foreign->id.'/reorder')
            ->assertNotFound();
    }

    public function test_reordering_an_own_order_returns_the_same_quantities_and_skips_inactive_products(): void
    {
        $subDealer = User::factory()->subDealer()->create();
        $sold = Product::factory()->create(['active' => true, 'stock' => 50, 'sub_dealer_price' => '90.00', 'dealer_price' => '80.00']);
        $inactive = Product::factory()->create(['active' => false, 'stock' => 50]);
        $paused = Product::factory()->create(['active' => true, 'stock' => 50, 'is_stock_paused' => true]);
        $order = $this->pastOrder($subDealer, 'cancelled', [
            [$sold, 7, '90.00'],
            [$inactive, 1, '10.00'],
            [$paused, 1, '10.00'],
        ]);

        $this->actingAs($subDealer)->onPortal('subdealer')
            ->getJson('/orders/'.$order->id.'/reorder')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.product.id', $sold->id)
            ->assertJsonPath('items.0.quantity', 7)
            ->assertJsonPath('skipped', [
                ['name' => $inactive->name, 'reason' => 'not_sold'],
                ['name' => $paused->name, 'reason' => 'not_sold'],
            ]);
    }

    /**
     * @param  list<array{0: Product, 1: int, 2: string}>  $lines
     */
    private function pastOrder(User $buyer, string $status, array $lines, ?string $createdAt = null): Order
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
        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order;
    }
}
