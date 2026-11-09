<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Jobs\RefreshShipmentTracking;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as CourierRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A dealer who opens their order sees fresh parcel news: the courier is asked in the background
 * when the last question is more than a few minutes old, and the open page picks up the answer.
 * Couriers are never called for real: every request goes to Http::fake().
 */
class DealerLiveCourierTrackingTest extends TestCase
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
        $settings->saveProvider($this->owner, 'couriers', 'steadfast', [
            'enabled' => true,
            'base_url' => 'https://courier.test/api/v1',
            'api_key' => 'key',
            'api_secret' => 'secret',
        ], $settings->snapshot()['version'], 'Courier test');
    }

    public function test_a_dealer_or_sub_dealer_opening_a_moving_parcel_asks_the_courier_in_the_queue_not_in_the_page(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new RefreshShipmentTracking(1));
        Bus::fake([RefreshShipmentTracking::class]);
        $subDealer = User::factory()->subDealer()->create(['status' => 'active', 'phone' => '01711000001']);

        foreach ([[$this->dealer, 'dealer'], [$subDealer, 'subdealer']] as [$partner, $portal]) {
            $order = $this->movingParcel($partner, 'SF-'.$portal, ['courier_tracked_at' => now()->subMinutes(10)]);

            $this->actingAs($partner)->onPortal($portal)->get('/orders/'.$order->id)->assertOk();

            Bus::assertDispatched(RefreshShipmentTracking::class, fn (RefreshShipmentTracking $job): bool => $job->shipmentId === $this->shipmentId($order));
        }
        $this->assertCourierAskedTimes(0);
    }

    public function test_a_parcel_asked_about_a_few_minutes_ago_is_left_alone_and_an_older_one_is_not(): void
    {
        Bus::fake([RefreshShipmentTracking::class]);
        $checkedJustNow = $this->movingParcel($this->dealer, 'SF-RECENT', ['courier_tracked_at' => now()->subMinutes(2)]);
        $checkedLongAgo = $this->movingParcel($this->dealer, 'SF-OLD', ['courier_tracked_at' => now()->subMinutes(6)]);

        $this->openAs($this->dealer, $checkedJustNow);
        Bus::assertNotDispatched(RefreshShipmentTracking::class);

        $this->openAs($this->dealer, $checkedLongAgo);
        Bus::assertDispatchedTimes(RefreshShipmentTracking::class, 1);
    }

    public function test_opening_the_page_again_and_again_cannot_flood_the_courier(): void
    {
        Bus::fake([RefreshShipmentTracking::class]);
        $order = $this->movingParcel($this->dealer, 'SF-FLOOD');

        foreach (range(1, 5) as $visit) {
            $this->openAs($this->dealer, $order);
        }
        Bus::assertDispatchedTimes(RefreshShipmentTracking::class, 1);

        $this->travel(6)->minutes();
        $this->openAs($this->dealer, $order);
        Bus::assertDispatchedTimes(RefreshShipmentTracking::class, 2);
    }

    public function test_a_job_that_waited_in_the_queue_does_not_ask_the_courier_twice(): void
    {
        $order = $this->movingParcel($this->dealer, 'SF-QUEUED', ['courier_tracked_at' => now()->subMinute()]);
        Http::fake(['courier.test/*/status_by_trackingcode/SF-QUEUED' => Http::response(['status' => 200, 'delivery_status' => 'in_transit'])]);

        $this->runRefreshJob($order);

        $this->assertCourierAskedTimes(0);
        $this->assertSame('waiting_pickup', DB::table('shipments')->where('order_id', $order->id)->value('courier_status'));

        DB::table('shipments')->where('order_id', $order->id)->update(['courier_tracked_at' => now()->subMinutes(6)]);
        $this->runRefreshJob($order);

        $this->assertCourierAskedTimes(1);
        $this->assertSame('in_transit', DB::table('shipments')->where('order_id', $order->id)->value('courier_status'));
    }

    public function test_a_partner_can_only_trigger_a_refresh_for_their_own_order(): void
    {
        Bus::fake([RefreshShipmentTracking::class]);
        $order = $this->movingParcel($this->dealer, 'SF-OWN');
        $otherDealer = User::factory()->dealer()->create(['status' => 'active']);
        $sponsor = User::factory()->dealer()->create(['status' => 'active']);
        $this->dealer->forceFill(['sponsor_id' => $sponsor->id])->save();
        $invitedSubDealer = User::factory()->subDealer()->create(['status' => 'active', 'sponsor_id' => $this->dealer->id]);

        foreach ([[$otherDealer, 'dealer'], [$sponsor, 'dealer'], [$invitedSubDealer, 'subdealer']] as [$stranger, $portal]) {
            $this->actingAs($stranger)->onPortal($portal)->get('/orders/'.$order->id)->assertNotFound();
        }

        Bus::assertNotDispatched(RefreshShipmentTracking::class);
        $this->assertCourierAskedTimes(0);
        $this->assertNull(DB::table('shipments')->where('order_id', $order->id)->value('courier_tracked_at'));

        $this->openAs($this->dealer, $order);
        Bus::assertDispatchedTimes(RefreshShipmentTracking::class, 1);
    }

    public function test_finished_parcels_are_never_asked_about_again(): void
    {
        Bus::fake([RefreshShipmentTracking::class]);
        $finished = [
            'the courier delivered it' => ['courier_status' => 'delivered'],
            'the courier returned it' => ['courier_status' => 'returned'],
            'staff confirmed the delivery' => ['status' => 'delivered', 'delivered_at' => now()],
            'it came back to the store' => ['status' => 'returned'],
        ];

        foreach ($finished as $case => $changes) {
            $order = $this->movingParcel($this->dealer, 'SF-'.Str::random(6), ['courier_tracked_at' => now()->subHours(2), ...$changes]);

            $this->openAs($this->dealer, $order);
            Bus::assertNotDispatched(RefreshShipmentTracking::class);
            $this->runRefreshJob($order);
            $this->assertCourierAskedTimes(0, $case);
        }

        $this->artisan('couriers:refresh-tracking')->assertSuccessful();
        $this->assertCourierAskedTimes(0, 'the scheduled run');
    }

    public function test_the_open_page_picks_up_the_new_step_on_its_next_poll(): void
    {
        $order = $this->movingParcel($this->dealer, 'SF-LIVE', ['courier_tracked_at' => now()->subMinutes(10)]);
        Http::fake(['courier.test/*/status_by_trackingcode/SF-LIVE' => Http::response(['status' => 200, 'delivery_status' => 'in_transit'])]);

        // The test queue runs the job straight after the page has read the parcel, as a worker would.
        $this->openAs($this->dealer, $order)->assertInertia(fn (Assert $page) => $page->where('shipments.0.courier_status', 'waiting_pickup'));

        $this->assertCourierAskedTimes(1);
        $this->openAs($this->dealer, $order)->assertInertia(fn (Assert $page) => $page
            ->where('shipments.0.courier_status', 'in_transit')
            ->where('shipments.0.courier_answered_minutes_ago', 0)
            ->reloadOnly(['shipments', 'events'], fn (Assert $polled) => $polled
                ->where('shipments.0.courier_status', 'in_transit')
                ->where('events', fn (mixed $events): bool => collect($events)->contains('action', 'shipment.courier_status'))));
        $this->assertCourierAskedTimes(1);
    }

    public function test_a_courier_that_does_not_answer_is_not_shown_as_an_update(): void
    {
        $order = $this->movingParcel($this->dealer, 'SF-DOWN');
        Http::fake(['courier.test/*/status_by_trackingcode/SF-DOWN' => Http::sequence()
            ->push(['message' => 'Server busy'], 500)
            ->push(['status' => 200, 'delivery_status' => 'in_transit'])
            ->push(['message' => 'Server busy'], 500)]);

        $this->assertSame(null, app(ShipmentTracking::class)->refresh($this->shipmentId($order)));

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertNotNull($shipment->courier_tracked_at, 'The question is remembered so the throttle holds.');
        $this->assertNull($shipment->courier_answered_at);
        Bus::fake([RefreshShipmentTracking::class]);
        $this->openAs($this->dealer, $order)->assertInertia(fn (Assert $page) => $page->where('shipments.0.courier_answered_minutes_ago', null));

        $this->travel(6)->minutes();
        app(ShipmentTracking::class)->refresh($this->shipmentId($order));
        $answeredAt = DB::table('shipments')->where('order_id', $order->id)->value('courier_answered_at');
        $this->assertNotNull($answeredAt);

        $this->travel(6)->minutes();
        app(ShipmentTracking::class)->refresh($this->shipmentId($order));
        $this->assertSame($answeredAt, DB::table('shipments')->where('order_id', $order->id)->value('courier_answered_at'));
        $this->openAs($this->dealer, $order)->assertInertia(fn (Assert $page) => $page->where('shipments.0.courier_answered_minutes_ago', 6));
    }

    public function test_the_order_page_says_how_long_ago_the_courier_answered(): void
    {
        Bus::fake([RefreshShipmentTracking::class]);
        $order = $this->movingParcel($this->dealer, 'SF-AGE', ['courier_answered_at' => now()->subMinutes(7)->subSeconds(20)]);

        $this->openAs($this->dealer, $order)->assertInertia(fn (Assert $page) => $page->where('shipments.0.courier_answered_minutes_ago', 7));
    }

    public function test_the_plain_words_exist_in_both_languages(): void
    {
        foreach (['checked_just_now', 'checked_one_minute_ago', 'checked_minutes_ago', 'checked_one_hour_ago', 'checked_hours_ago', 'checked_no_answer_yet'] as $key) {
            $english = __('courier.'.$key, [], 'en');
            $bangla = __('courier.'.$key, [], 'bn');

            $this->assertNotSame('courier.'.$key, $english);
            $this->assertNotSame('courier.'.$key, $bangla);
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', $bangla, $key.' must be written in Bangla.');
        }
    }

    /**
     * A partner's approved order with a parcel booked at Steadfast and waiting for pickup.
     *
     * @param  array<string, mixed>  $changes  Columns to set on the parcel afterwards.
     */
    private function movingParcel(User $partner, string $tracking, array $changes = []): Order
    {
        Http::fake(['courier.test/*/create_order' => Http::response(['consignment' => ['consignment_id' => 'CN-'.$tracking, 'tracking_code' => $tracking]])]);
        $product = Product::create([
            'sku' => 'LIVE-'.Str::random(6),
            'name' => 'Courier Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($partner, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'courier_choice' => 'steadfast',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        $this->assertSame('booked', DB::table('shipments')->where('order_id', $order->id)->value('courier_booking_status'));
        if ($changes !== []) {
            DB::table('shipments')->where('order_id', $order->id)->update($changes);
        }

        return $order->fresh();
    }

    private function openAs(User $partner, Order $order): TestResponse
    {
        return $this->actingAs($partner)->onPortal($partner->role === 'sub_dealer' ? 'subdealer' : 'dealer')->get('/orders/'.$order->id)->assertOk();
    }

    private function shipmentId(Order $order): int
    {
        return (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
    }

    private function runRefreshJob(Order $order): void
    {
        (new RefreshShipmentTracking($this->shipmentId($order)))->handle(app(ShipmentTracking::class));
    }

    private function assertCourierAskedTimes(int $times, string $message = ''): void
    {
        $this->assertCount($times, Http::recorded(fn (CourierRequest $request): bool => str_contains($request->url(), 'status_by_trackingcode')), $message);
    }
}
