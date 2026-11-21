<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderDelivery;
use App\Domain\Orders\OrderService;
use App\Jobs\BookShipmentCourier;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Courier" or "collect from the office" at order time, and the office side of it: a pickup order
 * never opens the courier page or books a courier; staff hand the goods over at the office in one
 * step (out of stock and delivered); staff may switch an order between the two before anything is
 * sent, and the partner is told. Courier orders work as before.
 */
class OrderOfficePickupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000000']);
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', 'steadfast', ['enabled' => true, 'base_url' => 'https://courier.test/api/v1',
            'api_key' => 'key', 'api_secret' => 'secret'], $settings->snapshot()['version'], 'Courier test');
    }

    public function test_an_office_pickup_order_keeps_no_courier_and_skips_the_courier_checks(): void
    {
        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'delivery_method' => 'office',
            // Left over from the form: not checked and not saved for an office pickup.
            'courier_choice' => 'no-such-courier',
        ])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $this->dealer->id)->sole();
        $this->assertSame('office', $order->delivery_method);
        $this->assertNull($order->courier_choice);
        $this->assertSame('office_pending', app(OrderDelivery::class)->for($order)['kind']);
    }

    public function test_a_courier_order_is_unchanged_and_still_checks_the_courier(): void
    {
        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'courier_choice' => 'no-such-courier',
        ])->assertSessionHasErrors('courier_choice');

        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'delivery_method' => 'courier',
            'courier_choice' => 'steadfast',
        ])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $this->dealer->id)->sole();
        $this->assertSame('courier', $order->delivery_method);
        $this->assertSame('steadfast', $order->courier_choice);
    }

    public function test_an_unknown_delivery_method_is_refused(): void
    {
        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'delivery_method' => 'drone',
        ])->assertSessionHasErrors('delivery_method');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_approving_a_pickup_order_does_not_open_the_courier_page_and_tells_the_partner(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('office');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Paid in full'])
            ->assertRedirect('/orders/'.$order->id)
            ->assertSessionHas('success', __('order_delivery.flash_approved_pickup'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
        $this->assertDatabaseCount('shipments', 0);
        Bus::assertNotDispatched(BookShipmentCourier::class);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id,
            'title' => __('order_delivery.notice_approved_pickup_title', [], $locale)]);
        // The courier page itself sends the office back to the order.
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders/'.$order->id.'/courier')->assertRedirect('/orders/'.$order->id);
    }

    public function test_approving_through_the_api_never_books_a_courier_for_a_pickup_order(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('office');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $this->assertDatabaseCount('shipments', 0);
        Bus::assertNotDispatched(BookShipmentCourier::class);
    }

    public function test_a_courier_order_still_goes_to_the_courier_page(): void
    {
        $order = $this->paidOrder('courier', 'steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Paid in full'])
            ->assertRedirect('/orders/'.$order->id.'/courier');
    }

    public function test_a_pickup_order_cannot_be_booked_with_a_courier(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->approvedOrder('office');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'steadfast', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['courier_provider' => __('order_delivery.err_pickup_no_courier')]);
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'steadfast'])
            ->assertSessionHasErrors('courier');

        $this->assertDatabaseCount('shipments', 0);
        Bus::assertNotDispatched(BookShipmentCourier::class);
    }

    public function test_handing_over_a_pickup_order_takes_the_stock_and_delivers_it_once(): void
    {
        $order = $this->approvedOrder('office');
        $key = (string) Str::uuid();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => $key, 'note' => 'Taken by the dealer himself'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/orders/'.$order->id);
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => $key, 'note' => 'Taken by the dealer himself'])
            ->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('order_id', $order->id)->sole();
        $this->assertSame('Office pickup', $shipment->courier);
        $this->assertSame('', $shipment->tracking);
        $this->assertNull($shipment->courier_provider);
        $this->assertNull($shipment->courier_booking_status);
        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(2, (int) DB::table('order_lines')->where('order_id', $order->id)->value('dispatched'));
        $this->assertSame(1, DB::table('stock_movements')->where('reason', $order->number)->where('kind', 'dispatch')->count());
        foreach (['order.dispatched', 'order.office_handover', 'shipment.delivered'] as $action) {
            $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => $action]);
            $this->assertDatabaseHas('audit_events', ['subject' => $order->id, 'action' => $action]);
        }
        $this->assertSame('office', app(OrderDelivery::class)->for($order->fresh())['kind']);
        // No courier was booked and the partner got no "sent by courier" notice.
        $this->assertDatabaseMissing('account_notices', ['user_id' => $this->dealer->id, 'title' => __('portal.notice_shipment_dispatched_title', [], $this->dealer->preferredLocale())]);
    }

    public function test_a_courier_order_can_be_handed_over_at_the_office_and_the_partner_is_told(): void
    {
        $order = $this->approvedOrder('courier', 'steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('office', $order->delivery_method);
        $this->assertNull($order->courier_choice);
        $this->assertSame('delivered', $order->status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.delivery_changed']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id,
            'title' => __('order_delivery.notice_changed_title', [], $this->dealer->preferredLocale())]);
    }

    public function test_only_store_staff_hand_over_goods_and_never_for_their_own_order(): void
    {
        $order = $this->approvedOrder('office');
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
        $this->actingAs($this->dealer)->onPortal('dealer')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();

        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_an_order_not_yet_approved_cannot_be_handed_over(): void
    {
        $order = $this->paidOrder('office');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['handover' => __('order_delivery.err_handover_status')]);

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_staff_switch_a_courier_order_to_office_pickup_before_sending_and_the_partner_is_told(): void
    {
        $order = $this->paidOrder('courier', 'steadfast');
        $reviewer = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders', 'order_review']]]);

        $this->actingAs($reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/delivery-method', ['delivery_method' => 'office', 'note' => 'He will come himself'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('office', $order->delivery_method);
        $this->assertNull($order->courier_choice);
        $event = DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.delivery_changed')->sole();
        $this->assertSame(['courier', 'office', 'He will come himself'], [json_decode($event->metadata, true)['from'], json_decode($event->metadata, true)['to'], json_decode($event->metadata, true)['note']]);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id, 'title' => __('order_delivery.notice_changed_title', [], $locale)]);

        // And back to courier: the company then decides the courier.
        $this->actingAs($reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/delivery-method', ['delivery_method' => 'courier'])
            ->assertSessionHasNoErrors();
        $this->assertSame('courier', $order->fresh()->delivery_method);
        $this->actingAs($reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/delivery-method', ['delivery_method' => 'courier'])
            ->assertSessionHasErrors(['delivery_method' => __('order_delivery.err_same_method')]);
    }

    public function test_the_way_cannot_change_once_goods_were_sent_or_by_the_partner(): void
    {
        $order = $this->approvedOrder('office');
        $this->actingAs($this->dealer)->onPortal('dealer')
            ->post('/commands/orders/'.$order->id.'/delivery-method', ['delivery_method' => 'courier'])
            ->assertForbidden();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/office-handover', ['idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/delivery-method', ['delivery_method' => 'courier'])
            ->assertSessionHasErrors(['delivery_method' => __('order_delivery.err_change_too_late')]);
    }

    public function test_the_dispatch_form_saves_a_pickup_order_as_an_office_hand_over_without_tracking(): void
    {
        $order = $this->approvedOrder('office');
        $lineId = (int) DB::table('order_lines')->where('order_id', $order->id)->value('id');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/dispatch', [
            'courier' => 'Some courier',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $lineId, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('order_id', $order->id)->sole();
        $this->assertSame('Office pickup', $shipment->courier);
        $this->assertSame('', $shipment->tracking);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id,
            'title' => __('order_delivery.notice_handed_title', [], $this->dealer->preferredLocale())]);
    }

    public function test_an_older_order_without_a_choice_reads_as_a_courier_order(): void
    {
        $order = $this->paidOrder('courier');
        DB::table('orders')->where('id', $order->id)->update(['delivery_method' => DB::raw('DEFAULT')]);

        $this->assertSame('courier', $order->fresh()->delivery_method);
        $this->assertSame('none', app(OrderDelivery::class)->for($order->fresh())['kind']);
    }

    private function product(int $stock = 20): Product
    {
        return Product::create([
            'sku' => 'PCK-'.Str::random(6),
            'name' => 'Pickup Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => $stock,
        ]);
    }

    private function paidOrder(string $method, ?string $courier = null): Order
    {
        $order = app(OrderService::class)->create($this->dealer, [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'delivery_method' => $method,
            'courier_choice' => $courier,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);

        return $order->fresh();
    }

    private function approvedOrder(string $method, ?string $courier = null): Order
    {
        $order = $this->paidOrder($method, $courier);
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full', bookNow: false);

        return $order->fresh();
    }
}
