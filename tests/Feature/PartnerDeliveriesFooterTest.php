<?php

namespace Tests\Feature;

use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The owner's rule: money counts after delivery only. The partner's Deliveries page adds up the
 * delivered-goods value of its rows (the footer reads delivered_value and delivered_paid), so
 * goods that are only shipped must add nothing, exactly as on My Money and the orders list.
 */
class PartnerDeliveriesFooterTest extends TestCase
{
    use RefreshDatabase;

    private User $dealer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 100]);
    }

    public function test_shipped_goods_add_nothing_to_the_deliveries_footer_but_delivered_goods_do(): void
    {
        $shipped = $this->orderWithParcel(10, 4, 'in_transit');
        $arrived = $this->orderWithParcel(10, 3, 'delivered');

        $this->assertSame('0.00', DeliveredMoney::valueOf($shipped));
        $this->assertSame('6000.00', DeliveredMoney::valueOf($arrived));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/deliveries')->assertOk()
            ->assertInertia(function (Assert $page) use ($shipped, $arrived): void {
                $rows = collect($page->toArray()['props']['data']['orders']['data'])->keyBy('id');
                $this->assertCount(2, $rows);
                $this->assertEqualsWithDelta(0.0, (float) $rows[$shipped->id]['delivered_value'], 0.001);
                $this->assertEqualsWithDelta(0.0, (float) $rows[$shipped->id]['delivered_paid'], 0.001);
                $this->assertEqualsWithDelta(6000.0, (float) $rows[$arrived->id]['delivered_value'], 0.001);
                $this->assertEqualsWithDelta(6000.0, (float) $rows[$arrived->id]['delivered_paid'], 0.001);
                // The footer is the sum of these two columns.
                $this->assertEqualsWithDelta(6000.0, $rows->sum(fn (array $row): float => (float) $row['delivered_value']), 0.001);
            });
    }

    public function test_the_footer_is_labelled_as_delivered_goods_only(): void
    {
        foreach (['bn', 'en'] as $language) {
            $this->assertNotSame('', trim(__('portal.order_footer_counted', [], $language)));
        }
    }

    private function orderWithParcel(int $quantity, int $parcelUnits, string $parcelStatus): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'partially_dispatched', 'paid' => $order->total]);
        $line = $order->lines()->where('is_free', false)->firstOrFail();
        DB::table('order_lines')->where('id', $line->id)->update(['dispatched' => $parcelUnits]);
        DB::table('shipments')->insert(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => 'T-'.Str::random(6), 'status' => $parcelStatus, 'courier_status' => $parcelStatus,
            'lines' => json_encode([['line_id' => $line->id, 'name' => 'Item', 'quantity' => $parcelUnits, 'lots' => []]], JSON_THROW_ON_ERROR),
            'actor_id' => $this->dealer->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);

        return $order->fresh();
    }
}
