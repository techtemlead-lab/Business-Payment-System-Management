<?php

namespace Tests\Feature;

use App\Domain\Integrations\CourierAreaMapping;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\OrderService;
use App\Jobs\BookShipmentCourier;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The courier steps of an order: a dealer can pick only a courier whose booking system is connected,
 * approving sends staff to the courier page without sending anything, and Next on that page books
 * the order with the courier by itself. Couriers are never called for real: Http::fake().
 */
class OrderCourierPageTest extends TestCase
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
        $this->saveCourier('steadfast', ['api_key' => 'key', 'api_secret' => 'secret']);
        $this->saveCourier('redx', ['api_key' => 'key']);
    }

    public function test_approving_sends_staff_to_the_courier_page_and_sends_nothing(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Paid in full'])
            ->assertRedirect('/orders/'.$order->id.'/courier')
            ->assertSessionHas('success', __('courier.flash_approved_pick_courier'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
        $this->assertDatabaseCount('shipments', 0);
        Bus::assertNotDispatched(BookShipmentCourier::class);
    }

    public function test_holding_or_rejecting_an_order_does_not_open_the_courier_page(): void
    {
        $order = $this->paidOrder('steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/decision', ['status' => 'held', 'reason' => 'Waiting for stock'])
            ->assertRedirect('/orders/'.$order->id)
            ->assertSessionHas('success', __('portal.flash_order_decision'));
    }

    public function test_the_courier_page_has_the_dealers_pick_ready_and_lists_every_switched_on_courier(): void
    {
        $this->saveCourier('sundarban', []);
        $this->saveCourier('paperfly', ['api_key' => 'key']);
        $order = $this->approvedOrder('redx');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders/'.$order->id.'/courier')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OrderCourier')
                ->where('dealerChoice', 'redx')
                ->where('order.number', $order->number)
                ->where('receiver.name', $this->dealer->name)
                ->where('couriers', fn (mixed $couriers): bool => collect($couriers)->pluck('key')->all() === ['steadfast', 'redx', 'paperfly', 'sundarban']
                    && collect($couriers)->firstWhere('key', 'steadfast')['api_ready'] === true
                    && collect($couriers)->firstWhere('key', 'paperfly')['api_ready'] === false
                    && collect($couriers)->firstWhere('key', 'sundarban')['manual'] === true)
                ->count('order.lines', 1));
    }

    public function test_the_courier_page_is_only_for_orders_still_to_send_and_only_for_store_staff(): void
    {
        $submitted = $this->paidOrder('steadfast');
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders/'.$submitted->id.'/courier')->assertRedirect('/orders/'.$submitted->id);

        $order = $this->approvedOrder('steadfast');
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->actingAs($finance)->onPortal('manage')->get('/orders/'.$order->id.'/courier')->assertForbidden();
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id.'/courier')->assertNotFound();
    }

    public function test_next_with_the_dealers_courier_books_the_order_and_fills_in_the_tracking_number(): void
    {
        Http::fake(['courier.test/*' => Http::response(['consignment' => ['consignment_id' => 'CN-31', 'tracking_code' => 'SF-31']])]);
        $order = $this->approvedOrder('steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'steadfast', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/orders/'.$order->id);

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('steadfast', $shipment->courier_provider);
        $this->assertSame('booked', $shipment->courier_booking_status);
        $this->assertSame('SF-31', $shipment->tracking);
        $this->assertSame('CN-31', $shipment->courier_consignment_id);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'dispatched']);
        // The dealer's own pick was kept, so there is no change to announce.
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.courier_changed']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booked']);
        Http::assertSentCount(1);
    }

    public function test_choosing_another_courier_tells_the_dealer_and_books_that_one(): void
    {
        Http::fake(['redx.test/*' => Http::response(['tracking_id' => 'RX-32'])]);
        // RedX needs the dealer's place matched to its own area numbers before it takes a parcel.
        $place = PlaceCode::factory()->division()->create();
        $this->dealer->forceFill(['profile' => [...(array) $this->dealer->getAttribute('profile'), 'place_code' => $place->code, 'place_code_id' => $place->id]])->save();
        app(CourierAreaMapping::class)->save($this->owner, ['provider' => 'redx', 'place_code_id' => $place->id, 'area_id' => '14', 'area_name' => 'Mirpur']);
        $order = $this->approvedOrder('steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'redx', 'note' => 'Cheaper to your area', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('redx', $shipment->courier_provider);
        $this->assertSame('RX-32', $shipment->tracking);
        $this->assertSame('redx', $order->fresh()->courier_choice);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $this->dealer->id,
            'body' => __('courier.notice_changed_body_with_note', ['number' => $order->number, 'from' => CourierOptions::label('steadfast', $locale), 'to' => CourierOptions::label('redx', $locale), 'note' => 'Cheaper to your area'], $locale),
        ]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_changed']);
    }

    public function test_a_company_decides_order_gets_its_courier_on_the_page(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->approvedOrder(null);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders/'.$order->id.'/courier')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('dealerChoice', null));
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'steadfast', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $this->assertSame('steadfast', $order->fresh()->courier_choice);
        Bus::assertDispatchedTimes(BookShipmentCourier::class, 1);
    }

    public function test_a_manual_courier_needs_its_tracking_number_and_is_not_booked_by_api(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $this->saveCourier('sundarban', []);
        $order = $this->approvedOrder('steadfast');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'sundarban', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('tracking');
        $this->assertDatabaseCount('shipments', 0);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'sundarban', 'tracking' => 'SB-5', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('sundarban', $shipment->courier_provider);
        $this->assertSame('manual', $shipment->courier_booking_status);
        $this->assertSame('SB-5', $shipment->tracking);
        Bus::assertNotDispatched(BookShipmentCourier::class);
    }

    public function test_pressing_next_twice_sends_the_order_once(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->approvedOrder('steadfast');
        $key = (string) Str::uuid();

        foreach ([1, 2] as $press) {
            $this->actingAs($this->owner)->onPortal('sadmin')
                ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'steadfast', 'idempotency_key' => $key])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('shipments', 1);
        Bus::assertDispatchedTimes(BookShipmentCourier::class, 1);
    }

    public function test_a_refusal_for_missing_stock_saves_nothing_and_keeps_the_dealers_pick(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->approvedOrder('steadfast', stock: 1);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'redx', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('shipment');

        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame('steadfast', $order->fresh()->courier_choice);
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.courier_changed']);
        Bus::assertNotDispatched(BookShipmentCourier::class);
    }

    public function test_only_store_staff_can_send_and_a_switched_off_courier_is_refused(): void
    {
        $order = $this->approvedOrder('steadfast');
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->actingAs($finance)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'steadfast', 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
        $this->actingAs($this->dealer)->onPortal('dealer')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'steadfast', 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier-ship', ['courier_provider' => 'pathao', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('courier_provider');
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_the_service_still_books_on_approval_for_callers_that_have_no_screen_to_follow(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('steadfast');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $this->assertDatabaseCount('shipments', 1);
        Bus::assertDispatchedTimes(BookShipmentCourier::class, 1);
    }

    /** @param  array<string, string>  $details */
    private function saveCourier(string $provider, array $details): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, [
            'enabled' => true,
            'base_url' => $provider === 'redx' ? 'https://redx.test' : 'https://courier.test/api/v1',
            ...$details,
        ], $settings->snapshot()['version'], 'Courier test');
    }

    private function product(int $stock = 20): Product
    {
        return Product::create([
            'sku' => 'CAB-'.Str::random(6),
            'name' => 'Courier Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => $stock,
        ]);
    }

    private function paidOrder(?string $courier, int $stock = 20): Order
    {
        $order = app(OrderService::class)->create($this->dealer, [
            'items' => [['product_id' => $this->product($stock)->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'courier_choice' => $courier,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);

        return $order->fresh();
    }

    /** An approved, fully paid order that nothing has been sent for yet. */
    private function approvedOrder(?string $courier, int $stock = 20): Order
    {
        $order = $this->paidOrder($courier, $stock);
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full', bookNow: false);

        return $order->fresh();
    }
}
