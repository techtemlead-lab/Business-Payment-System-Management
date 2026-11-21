<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The order page works out each line's discount from the list price saved on the line when the
 * order was placed, so the page must carry that saved price, not today's product price.
 */
class OrderLineDiscountColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_order_page_lines_carry_the_list_price_saved_at_order_time(): void
    {
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['stock' => 5, 'mrp' => 200]);
        $order = $this->placeOrder($dealer, $product);
        $line = $order->lines->first();
        $savedMrp = (string) $line->mrp;
        $savedPrice = (string) $line->unit_price;

        // The company changes the list price afterwards: the saved line must not follow.
        $product->update(['mrp' => 400]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('OrderDetail')
                ->where('order.lines.0.mrp', $savedMrp)
                ->where('order.lines.0.unit_price', $savedPrice)
                ->where('order.lines.0.is_free', false));

        $this->assertEquals(200.0, (float) $savedMrp);
        $this->assertLessThan((float) $savedMrp, (float) $savedPrice);
    }

    public function test_invoice_remark_shows_up_to_two_decimals_of_the_discount(): void
    {
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['stock' => 5, 'mrp' => 200]);
        $order = $this->placeOrder($dealer, $product);
        $order->lines()->update(['mrp' => '200.00', 'unit_price' => '125.00', 'total' => '125.00']);

        $payload = app(OrderInvoice::class)->payload($order->fresh(), 'dealer');

        $this->assertSame('37.5%', $payload['lines'][0]['remark']);
    }

    private function placeOrder(User $dealer, Product $product): Order
    {
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return $order->load('lines');
    }
}
