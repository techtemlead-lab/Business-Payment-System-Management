<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockBookingAndPaymentCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_inventory_shows_units_booked_on_approved_orders_not_yet_sent(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['stock' => 20]);
        $this->orderWithLine($dealer, $product, 'approved', quantity: 5, dispatched: 2);
        $this->orderWithLine($dealer, $product, 'partially_dispatched', quantity: 4, dispatched: 1);
        $this->orderWithLine($dealer, $product, 'submitted', quantity: 9, dispatched: 0);
        $this->orderWithLine($dealer, $product, 'delivered', quantity: 6, dispatched: 6);

        $this->actingAs($owner)->onPortal('sadmin')->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products', fn ($products) => collect($products)
                    ->firstWhere('id', $product->id)['booked_units'] === 6));
    }

    public function test_payment_list_shows_who_checked_a_payment_and_why_it_was_rejected(): void
    {
        $owner = User::factory()->staff()->create();
        $checker = User::factory()->staff('finance')->create(['name' => 'Rina Finance']);
        $order = $this->orderWithLine(User::factory()->dealer()->create(), Product::factory()->create(), 'approved', quantity: 1, dispatched: 0);
        DB::table('payment_claims')->insert([
            'order_id' => $order->id, 'channel' => 'bKash', 'reference' => 'TRX-REJECTED-1', 'amount' => '100.00',
            'status' => 'rejected', 'reason' => 'Transaction ID not found in statement', 'verified_by' => $checker->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.claims.data.0.checked_by', 'Rina Finance')
                ->where('data.claims.data.0.reason', 'Transaction ID not found in statement')
                ->has('data.claims.data.0.checked_at'));
    }

    private function orderWithLine(User $buyer, Product $product, string $status, int $quantity, int $dispatched): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $order->lines()->create([
            'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
            'quantity' => $quantity, 'dispatched' => $dispatched, 'mrp' => '100.00', 'unit_price' => '100.00', 'total' => '100.00',
        ]);

        return $order;
    }
}
