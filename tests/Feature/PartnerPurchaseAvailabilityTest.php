<?php

namespace Tests\Feature;

use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The purchase page tells a partner how many units are free now (stock minus what approved
 * orders already hold) so it can warn about a back order. The product rows themselves still
 * carry no stock figure, and the order is never blocked on the server.
 */
class PartnerPurchaseAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
    }

    public function test_available_now_is_stock_minus_units_held_by_approved_orders_not_yet_sent(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = $this->stockedProduct(20);
        $this->order($dealer, 'approved', $product, 5, dispatched: 0);
        $this->order($dealer, 'partially_dispatched', $product, 6, dispatched: 4, closed: 1);
        // Not yet approved, or already finished: these hold nothing.
        $this->order($dealer, 'submitted', $product, 9);
        $this->order($dealer, 'delivered', $product, 3, dispatched: 3);
        $this->order($dealer, 'cancelled', $product, 7);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.available_now.'.$product->id, 14)
                ->where('data.products.0.id', $product->id)
                ->missing('data.products.0.stock')
                ->missing('data.products.0.stock_status'));
    }

    public function test_available_now_never_goes_below_zero(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = $this->stockedProduct(4);
        $this->order($dealer, 'approved', $product, 10);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.available_now.'.$product->id, 0));
    }

    public function test_an_order_above_the_available_units_is_still_accepted(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = $this->stockedProduct(2);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 50]],
            'channel' => 'Bank',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionDoesntHaveErrors(['items', 'items.0.quantity']);

        $this->assertDatabaseHas('order_lines', ['product_id' => $product->id, 'quantity' => 50]);
    }

    public function test_reorder_cards_carry_the_total_and_the_product_names(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $first = Product::factory()->create(['active' => true, 'name' => 'Honey Jar', 'dealer_price' => '100.00', 'stock' => 5]);
        $second = Product::factory()->create(['active' => true, 'name' => 'Black Seed Oil', 'dealer_price' => '200.00', 'stock' => 5]);
        $order = $this->order($dealer, 'delivered', $first, 2);
        $order->lines()->create(['product_id' => $second->id, 'name' => $second->name, 'sku' => $second->sku, 'quantity' => 1, 'mrp' => '250.00', 'unit_price' => '200.00', 'total' => '200.00']);
        $order->forceFill(['total' => '400.00'])->save();

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.repeat_orders.0.total', '400.00')
                ->where('data.repeat_orders.0.names', ['Honey Jar', 'Black Seed Oil']));
    }

    public function test_the_new_words_exist_in_both_languages(): void
    {
        $bangla = require lang_path('bn/purchase_page.php');
        $english = require lang_path('en/purchase_page.php');

        $this->assertNotEmpty($bangla);
        $this->assertSame(array_keys($bangla), array_keys($english));
        $this->assertSame([], array_keys(array_filter($bangla, fn (string $word): bool => trim($word) === '')));
        $this->assertSame([], array_keys(array_filter($english, fn (string $word): bool => trim($word) === '')));
    }

    private function stockedProduct(int $units): Product
    {
        $product = Product::factory()->create(['active' => true, 'dealer_price' => '100.00', 'mrp' => '150.00', 'stock' => 0, 'low_stock_at' => 5]);
        BranchProductStock::query()
            ->firstOrCreate(['branch_id' => $this->headOffice->id, 'product_id' => $product->id], ['quantity' => 0, 'low_stock_at' => 5])
            ->increment('quantity', $units);
        $product->increment('stock', $units);
        StockLot::factory()->create(['branch_id' => $this->headOffice->id, 'product_id' => $product->id, 'quantity' => $units, 'received_quantity' => $units]);

        return $product;
    }

    private function order(User $buyer, string $status, Product $product, int $quantity, int $dispatched = 0, int $closed = 0): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'dispatched' => $dispatched,
            'closed' => $closed,
            'mrp' => $product->mrp,
            'unit_price' => '100.00',
            'total' => '100.00',
        ]);

        return $order;
    }
}
