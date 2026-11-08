<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Jobs\BookShipmentCourier;
use App\Jobs\RefreshShipmentTracking;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Stream F: the dealer picks a courier on the order form, the parcel is booked on approval,
 * staff can retry or switch couriers, and the dealer follows the parcel on the order page.
 * Couriers are never called for real: every request goes to Http::fake().
 */
class CourierAutoBookingTest extends TestCase
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
        $this->enableCourier('steadfast');
        $this->enableCourier('redx');
    }

    public function test_the_order_form_offers_only_enabled_couriers_and_saves_the_choice(): void
    {
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.courier_choices.0.key', 'steadfast')
                ->where('data.courier_choices.1.key', 'redx')
                ->where('data.courier_choices.2.key', 'company')
                ->count('data.courier_choices', 3));

        $product = $this->product();
        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => 'steadfast',
        ])->assertSessionHasNoErrors();
        $this->assertSame('steadfast', Order::query()->latest('created_at')->first()->courier_choice);

        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => 'company',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Order::query()->whereNull('courier_choice')->count());

        // Pathao is not switched on, so the dealer cannot pick it.
        $this->actingAs($this->dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => 'pathao',
        ])->assertSessionHasErrors('courier_choice');
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_approval_creates_the_shipment_and_queues_the_booking(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('steadfast');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertNotNull($shipment);
        $this->assertSame('steadfast', $shipment->courier_provider);
        $this->assertSame('pending', $shipment->courier_booking_status);
        $this->assertNotNull($shipment->branch_id);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'dispatched']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.dispatched']);
        Bus::assertDispatched(BookShipmentCourier::class, fn (BookShipmentCourier $job): bool => $job->shipmentId === (int) $shipment->id);
    }

    public function test_company_decides_orders_keep_the_manual_dispatch(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder(null);

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
        Bus::assertNotDispatched(BookShipmentCourier::class);
    }

    public function test_a_failed_booking_is_shown_to_staff_and_can_be_retried(): void
    {
        Http::fakeSequence('courier.test/*')
            ->push(['message' => 'Area not served'], 422)
            ->push(['consignment' => ['consignment_id' => 'CN-9', 'tracking_code' => 'SF-9']]);
        $order = $this->paidOrder('steadfast');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('failed', $shipment->courier_booking_status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booking_failed']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->owner->id, 'title' => __('courier.notice_booking_failed_title', ['number' => $order->number])]);

        $store = User::factory()->staff('inventory')->create(['status' => 'active']);
        $this->actingAs($store)->onPortal('manage')->get('/deliveries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.courier_board.failed.0.order_id', $order->id));

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipment->id.'/courier-retry')
            ->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('id', $shipment->id)->first();
        $this->assertSame('booked', $shipment->courier_booking_status);
        $this->assertSame('SF-9', $shipment->tracking);
        $this->assertSame('waiting_pickup', $shipment->courier_status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booking_retried']);
        Http::assertSentCount(2);
    }

    public function test_changing_the_courier_with_a_note_tells_the_dealer_and_books_the_new_courier(): void
    {
        Http::fake([
            'courier.test/*' => Http::response(['message' => 'Closed today'], 503),
            'redx.test/*' => Http::response(['tracking_id' => 'RX-77']),
        ]);
        $order = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('failed', $shipment->courier_booking_status);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'redx', 'note' => 'Steadfast is closed today'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('courier.flash_changed'));

        $shipment = DB::table('shipments')->where('id', $shipment->id)->first();
        $this->assertSame('redx', $shipment->courier_provider);
        $this->assertSame('booked', $shipment->courier_booking_status);
        $this->assertSame('RX-77', $shipment->tracking);
        $this->assertSame('redx', $order->fresh()->courier_choice);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $this->dealer->id,
            'title' => __('courier.notice_changed_title', ['number' => $order->number], $locale),
            'body' => __('courier.notice_changed_body_with_note', ['number' => $order->number, 'from' => CourierOptions::label('steadfast', $locale), 'to' => CourierOptions::label('redx', $locale), 'note' => 'Steadfast is closed today'], $locale),
        ]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'order.courier_changed', 'subject' => $order->id]);
        $event = DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.courier_changed')->first();
        $this->assertSame('Steadfast is closed today', json_decode($event->metadata, true)['note']);

        // The dealer's order page shows the change and the note.
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OrderDetail')
                ->where('shipments.0.courier', 'RedX')
                ->where('shipments.0.tracking', 'RX-77')
                ->missing('shipments.0.courier_booking_error'));
    }

    public function test_changing_the_courier_before_approval_without_a_note_tells_the_dealer(): void
    {
        $order = $this->paidOrder('steadfast');
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'redx'])
            ->assertSessionHasNoErrors();

        $this->assertSame('redx', $order->fresh()->courier_choice);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $this->dealer->id,
            'body' => __('courier.notice_changed_body', ['number' => $order->number, 'from' => CourierOptions::label('steadfast', $locale), 'to' => CourierOptions::label('redx', $locale)], $locale),
        ]);
        $event = DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.courier_changed')->first();
        $this->assertNull(json_decode($event->metadata, true)['note']);

        // A booked parcel can no longer be switched.
        Bus::fake([BookShipmentCourier::class]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        DB::table('shipments')->where('order_id', $order->id)->update(['courier_booking_status' => 'booked', 'tracking' => 'RX-1']);
        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'steadfast'])
            ->assertSessionHasErrors('courier');

        // Dealers cannot change the courier themselves.
        $this->actingAs($this->dealer)->onPortal('dealer')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'steadfast'])
            ->assertForbidden();
    }

    public function test_the_tracking_refresh_moves_the_parcel_forward_and_tells_the_dealer(): void
    {
        Http::fake([
            'courier.test/*/create_order' => Http::response(['consignment' => ['consignment_id' => 'CN-55', 'tracking_code' => 'SF-55']]),
            'courier.test/*/status_by_trackingcode/SF-55' => Http::sequence()
                ->push(['status' => 200, 'delivery_status' => 'in_review'])
                ->push(['status' => 200, 'delivery_status' => 'Delivered']),
        ]);
        $order = $this->bookedOrder();
        $this->artisan('couriers:refresh-tracking')->assertSuccessful();
        $this->assertSame('waiting_pickup', DB::table('shipments')->where('order_id', $order->id)->value('courier_status'));

        // Checked a moment ago: a run now leaves it alone until it is due again.
        $this->artisan('couriers:refresh-tracking')->assertSuccessful();
        Http::assertSentCount(2);
        $this->travel(25)->minutes();
        $this->artisan('couriers:refresh-tracking')->assertSuccessful();

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('delivered', $shipment->courier_status);
        $this->assertSame('Delivered', $shipment->courier_status_raw);
        $this->assertNotNull($shipment->courier_tracked_at);
        $this->assertSame(['waiting_pickup', 'delivered'], array_column(json_decode($shipment->courier_timeline, true), 'status'));
        // Staff still confirm the delivery themselves.
        $this->assertSame('dispatched', $shipment->status);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $this->dealer->id,
            'body' => __('courier.notice_status_body', ['courier' => CourierOptions::label('steadfast'), 'tracking' => 'SF-55', 'status' => __('courier.status_delivered', [], $locale)], $locale),
        ]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'shipment.courier_status']);

        // A delivered parcel is never asked about again.
        $this->travel(2)->hours();
        $this->artisan('couriers:refresh-tracking')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_staff_can_mark_a_parcel_picked_up(): void
    {
        $order = $this->bookedOrder('SF-56');
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/picked-up')
            ->assertSessionHasNoErrors();

        $this->assertSame('picked_up', DB::table('shipments')->where('id', $shipmentId)->value('courier_status'));
        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/picked-up')
            ->assertSessionHasErrors('shipment');
    }

    public function test_the_dealer_sees_tracking_only_on_their_own_order(): void
    {
        Bus::fake([RefreshShipmentTracking::class]);
        $order = $this->bookedOrder('SF-57');

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OrderDetail')
                ->where('shipments.0.tracking', 'SF-57')
                ->where('shipments.0.courier_status', 'waiting_pickup')
                ->where('shipments.0.tracking_url', 'https://steadfast.com.bd/t/SF-57')
                ->missing('shipments.0.courier_consignment_id'));
        // Opening the page asks the courier for news in the background, once.
        Bus::assertDispatchedTimes(RefreshShipmentTracking::class, 1);
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk();
        Bus::assertDispatchedTimes(RefreshShipmentTracking::class, 1);

        $otherDealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($otherDealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertNotFound();
    }

    public function test_courier_wording_is_turned_into_the_five_steps(): void
    {
        $this->assertSame('waiting_pickup', ShipmentTracking::normalize('Pickup_Requested'));
        $this->assertSame('picked_up', ShipmentTracking::normalize('Picked'));
        $this->assertSame('in_transit', ShipmentTracking::normalize('At_the_Sorting_HUB'));
        $this->assertSame('in_transit', ShipmentTracking::normalize('delivery-in-progress'));
        $this->assertSame('delivered', ShipmentTracking::normalize('delivered_approval_pending'));
        $this->assertSame('returned', ShipmentTracking::normalize('Paid_Return'));
        $this->assertNull(ShipmentTracking::normalize('200'));
        $this->assertNull(ShipmentTracking::normalize(''));
    }

    public function test_a_booking_job_that_dies_after_its_claim_gives_staff_a_way_out(): void
    {
        Http::fake(['courier.test/*' => Http::response(['consignment' => ['consignment_id' => 'CN-70', 'tracking_code' => 'SF-70']])]);
        $order = $this->paidOrder('steadfast');
        $workerDies = true;
        Order::retrieved(function () use (&$workerDies): void {
            if ($workerDies && DB::table('shipments')->where('courier_booking_status', 'booking')->exists()) {
                $workerDies = false;
                throw new RuntimeException('The worker died');
            }
        });

        try {
            app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
            $this->fail('The job should have died after claiming the booking.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The worker died', $exception->getMessage());
        }

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('failed', $shipment->courier_booking_status);
        $this->assertSame(__('courier.booking_outcome_unknown'), $shipment->courier_booking_error);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booking_failed']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->owner->id, 'title' => __('courier.notice_booking_failed_title', ['number' => $order->number])]);
        Http::assertNothingSent();

        // Staff can now send it again, and it is booked exactly once.
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);
        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipment->id.'/courier-retry')
            ->assertSessionHasNoErrors();
        $this->assertSame('booked', DB::table('shipments')->where('id', $shipment->id)->value('courier_booking_status'));
        Http::assertSentCount(1);
    }

    public function test_a_finished_job_cannot_be_turned_into_a_failed_booking(): void
    {
        $order = $this->bookedOrder('SF-71');
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');

        (new BookShipmentCourier($shipmentId))->failed(new RuntimeException('SMS gateway down'));

        $this->assertSame('booked', DB::table('shipments')->where('id', $shipmentId)->value('courier_booking_status'));
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booking_failed']);
    }

    public function test_the_scheduled_run_frees_a_booking_stuck_without_a_failed_job(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $stuck = $this->paidOrder('steadfast');
        $working = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $stuck, 'approved', 'Paid in full');
        app(OrderService::class)->decision($this->owner, $working, 'approved', 'Paid in full');
        DB::table('shipments')->where('order_id', $stuck->id)->update(['courier_booking_status' => 'booking', 'updated_at' => now()->subMinutes(BookShipmentCourier::StuckAfterMinutes + 1)]);
        DB::table('shipments')->where('order_id', $working->id)->update(['courier_booking_status' => 'booking', 'updated_at' => now()]);

        $this->artisan('couriers:refresh-tracking')->assertSuccessful();

        $this->assertSame('failed', DB::table('shipments')->where('order_id', $stuck->id)->value('courier_booking_status'));
        $this->assertSame('booking', DB::table('shipments')->where('order_id', $working->id)->value('courier_booking_status'));
    }

    public function test_a_parcel_cannot_be_marked_picked_up_before_it_is_booked(): void
    {
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        foreach (['pending', 'booking', 'failed'] as $bookingStatus) {
            DB::table('shipments')->where('id', $shipmentId)->update(['courier_booking_status' => $bookingStatus]);
            $this->actingAs($store)->onPortal('manage')
                ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/picked-up')
                ->assertSessionHasErrors('shipment');
            $this->assertNull(DB::table('shipments')->where('id', $shipmentId)->value('courier_status'));
        }
    }

    public function test_a_pathao_booking_answered_with_only_a_consignment_id_counts_as_booked(): void
    {
        $this->enableCourier('pathao');
        Http::fake(['courier.test/*' => Http::response(['message' => 'Order Created Successfully', 'type' => 'success', 'code' => 200, 'data' => ['consignment_id' => 'DP-81', 'merchant_order_id' => 'MN-1', 'order_status' => 'Pending', 'delivery_fee' => 80]])]);
        $order = $this->paidOrder('pathao');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('booked', $shipment->courier_booking_status);
        $this->assertSame('DP-81', $shipment->tracking);
        $this->assertSame('DP-81', $shipment->courier_consignment_id);
        $this->assertTrue(ShipmentTracking::isTrackable($shipment));
        Http::assertSentCount(1);
    }

    public function test_a_courier_that_never_answers_warns_staff_to_check_before_booking_again(): void
    {
        Http::fake(['courier.test/*' => Http::failedConnection('Operation timed out')]);
        $order = $this->paidOrder('steadfast');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('failed', $shipment->courier_booking_status);
        $this->assertSame(__('courier.booking_outcome_unknown'), $shipment->courier_booking_error);
    }

    public function test_a_parcel_already_confirmed_delivered_is_never_sent_to_a_courier_again(): void
    {
        Http::fake(['courier.test/*' => Http::response(['message' => 'Area not served'], 422)]);
        $order = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        DB::table('shipments')->where('id', $shipmentId)->update(['status' => 'delivered', 'delivered_at' => now()]);
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/courier-retry')
            ->assertSessionHasErrors('shipment');
        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'redx'])
            ->assertSessionHasErrors('courier');

        $this->assertSame('failed', DB::table('shipments')->where('id', $shipmentId)->value('courier_booking_status'));
        Http::assertSentCount(1);
    }

    public function test_the_dealer_does_not_receive_the_courier_error_or_consignment_in_the_order_history(): void
    {
        Http::fakeSequence('courier.test/*')
            ->push(['message' => 'Area not served'], 422)
            ->push(['consignment' => ['consignment_id' => 'CN-90', 'tracking_code' => 'SF-90']]);
        $order = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        app(OrderService::class)->retryCourierBooking($this->owner, $order->fresh(), $shipmentId);
        $metadata = fn (mixed $events, string $action): array => json_decode(collect($events)->firstWhere('action', $action)['metadata'], true);

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('events', function (mixed $events) use ($metadata): bool {
                return ! array_key_exists('message', $metadata($events, 'order.courier_booking_failed'))
                    && ! array_key_exists('consignment_id', $metadata($events, 'order.courier_booked'));
            }));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('events', fn (mixed $events): bool => $metadata($events, 'order.courier_booked')['consignment_id'] === 'CN-90'));
    }

    public function test_a_parcel_the_courier_returned_cannot_be_confirmed_as_delivered(): void
    {
        $returned = $this->bookedOrder('SF-95');
        $returnedShipmentId = (int) DB::table('shipments')->where('order_id', $returned->id)->value('id');
        $this->assertSame('returned', app(ShipmentTracking::class)->apply($returnedShipmentId, 'returned', 'Paid_Return', null));
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$returned->id.'/shipments/'.$returnedShipmentId.'/delivered', ['reason' => 'Dealer says it arrived'])
            ->assertSessionHasErrors('shipment');

        $shipment = DB::table('shipments')->where('id', $returnedShipmentId)->first();
        $this->assertSame('dispatched', $shipment->status);
        $this->assertNull($shipment->delivered_at);
        $this->assertSame('dispatched', $returned->fresh()->status);
        $this->assertDatabaseMissing('order_events', ['order_id' => $returned->id, 'action' => 'shipment.delivered']);

        // The order page gets the step that hides the button and shows why.
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders/'.$returned->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('shipments.0.courier_status', 'returned'));

        // A parcel the courier did not return is still confirmed by staff as before.
        $delivered = $this->bookedOrder('SF-95');
        $deliveredShipmentId = (int) DB::table('shipments')->where('order_id', $delivered->id)->value('id');
        app(ShipmentTracking::class)->apply($deliveredShipmentId, 'delivered', 'Delivered', null);

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$delivered->id.'/shipments/'.$deliveredShipmentId.'/delivered', ['reason' => 'Courier delivered it'])
            ->assertSessionHasNoErrors();

        $this->assertSame('delivered', DB::table('shipments')->where('id', $deliveredShipmentId)->value('status'));
        $this->assertSame('delivered', $delivered->fresh()->status);
    }

    private function enableCourier(string $provider): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, [
            'enabled' => true,
            'base_url' => $provider === 'redx' ? 'https://redx.test' : 'https://courier.test/api/v1',
            'api_key' => 'key',
            'api_secret' => 'secret',
        ], $settings->snapshot()['version'], 'Courier test');
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'CAB-'.Str::random(6),
            'name' => 'Courier Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
    }

    private function paidOrder(?string $courier): Order
    {
        $order = app(OrderService::class)->create($this->dealer, [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'courier_choice' => $courier,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);

        return $order->fresh();
    }

    /** An approved order booked with Steadfast; without a tracking number the test fakes the courier itself. */
    private function bookedOrder(?string $tracking = null): Order
    {
        if ($tracking !== null) {
            Http::fake(['courier.test/*/create_order' => Http::response(['consignment' => ['consignment_id' => 'CN-'.$tracking, 'tracking_code' => $tracking]])]);
        }
        $order = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
        $this->assertSame('booked', DB::table('shipments')->where('order_id', $order->id)->value('courier_booking_status'));

        return $order->fresh();
    }
}
