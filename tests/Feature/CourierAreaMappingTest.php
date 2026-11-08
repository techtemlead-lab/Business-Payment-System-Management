<?php

namespace Tests\Feature;

use App\Domain\Integrations\CourierAreaMapping;
use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Jobs\BookShipmentCourier;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Matching place codes to Pathao and RedX area numbers, booking with them, and the Paperfly
 * and eCourier settings. Couriers are never called for real: every request goes to Http::fake().
 */
class CourierAreaMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PlaceCode $division;

    private PlaceCode $district;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->division = PlaceCode::factory()->division()->create();
        $this->district = PlaceCode::factory()->district($this->division)->create();
        $this->union = PlaceCode::factory()->union($this->district)->create();
    }

    public function test_a_pathao_booking_sends_the_mapped_city_zone_and_area(): void
    {
        $this->enableCourier('pathao');
        $this->map('pathao', $this->union, ['city_id' => '1', 'zone_id' => '52', 'area_id' => '908']);
        Http::fake(['courier.test/*' => Http::response(['data' => ['consignment_id' => 'DP-1']])]);

        $shipment = $this->book('pathao', $this->union);

        $this->assertSame('booked', $shipment->courier_booking_status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/aladdin/api/v1/orders')
            && $request['recipient_city'] === '1' && $request['recipient_zone'] === '52' && $request['recipient_area'] === '908');
    }

    public function test_a_place_with_no_match_uses_the_nearest_place_above_it(): void
    {
        $this->enableCourier('pathao');
        $this->map('pathao', $this->division, ['city_id' => '9', 'zone_id' => '9']);
        $this->map('pathao', $this->district, ['city_id' => '2', 'zone_id' => '77']);
        Http::fake(['courier.test/*' => Http::response(['data' => ['consignment_id' => 'DP-2']])]);

        $shipment = $this->book('pathao', $this->union);

        $this->assertSame('booked', $shipment->courier_booking_status);
        Http::assertSent(fn (Request $request): bool => $request['recipient_city'] === '2' && $request['recipient_zone'] === '77' && ! isset($request['recipient_area']));
    }

    public function test_a_redx_booking_sends_the_mapped_area_number_and_name(): void
    {
        $this->enableCourier('redx');
        $this->map('redx', $this->district, ['area_id' => '14', 'area_name' => 'Mirpur']);
        Http::fake(['courier.test/*' => Http::response(['tracking_id' => 'RX-1'])]);

        $this->assertSame('booked', $this->book('redx', $this->union)->courier_booking_status);

        Http::assertSent(fn (Request $request): bool => $request['delivery_area_id'] === '14' && $request['delivery_area'] === 'Mirpur');
    }

    public function test_an_unmapped_place_is_held_without_calling_the_courier_and_can_be_retried(): void
    {
        $this->enableCourier('pathao');
        Http::fake(['courier.test/*' => Http::response(['data' => ['consignment_id' => 'DP-9']])]);

        $shipment = $this->book('pathao', $this->union);

        $this->assertSame('failed', $shipment->courier_booking_status);
        $this->assertStringContainsString('Needs area mapping', (string) $shipment->courier_booking_error);
        $this->assertStringContainsString((string) $this->union->code, (string) $shipment->courier_booking_error);
        Http::assertNothingSent();

        $this->map('pathao', $this->union, ['city_id' => '1', 'zone_id' => '52']);
        $order = Order::query()->findOrFail($shipment->order_id);
        app(OrderService::class)->retryCourierBooking($this->owner, $order, (int) $shipment->id);

        $this->assertSame('booked', DB::table('shipments')->where('id', $shipment->id)->value('courier_booking_status'));
    }

    public function test_an_unmapped_booking_can_be_moved_to_another_courier(): void
    {
        $this->enableCourier('pathao');
        $this->enableCourier('steadfast');
        Http::fake(['courier.test/*' => Http::response(['consignment' => ['consignment_id' => 'S-1', 'tracking_code' => 'S-1']])]);
        $shipment = $this->book('pathao', $this->union);
        $this->assertSame('failed', $shipment->courier_booking_status);

        $order = Order::query()->findOrFail($shipment->order_id);
        app(OrderService::class)->changeCourier($this->owner, $order, 'steadfast', 'Pathao area not matched');

        $this->assertSame('booked', DB::table('shipments')->where('id', $shipment->id)->value('courier_booking_status'));
    }

    public function test_a_partner_without_a_place_code_is_held_with_a_clear_reason(): void
    {
        $this->enableCourier('pathao');
        Http::fake();

        $shipment = $this->book('pathao', null);

        $this->assertSame('failed', $shipment->courier_booking_status);
        $this->assertStringContainsString(__('courier_areas.no_place_code'), (string) $shipment->courier_booking_error);
        Http::assertNothingSent();
    }

    public function test_owner_fetches_pathao_city_zone_and_area_lists_one_city_at_a_time(): void
    {
        $this->enableCourier('pathao');
        Http::fake([
            'courier.test/aladdin/api/v1/city-list' => Http::response(['data' => ['data' => [['city_id' => 1, 'city_name' => 'Dhaka'], ['city_id' => 2, 'city_name' => 'Rajshahi']]]]),
            'courier.test/aladdin/api/v1/cities/1/zone-list' => Http::response(['data' => ['data' => [['zone_id' => 52, 'zone_name' => 'Mirpur']]]]),
            'courier.test/aladdin/api/v1/cities/2/zone-list' => Http::response(['data' => ['data' => []]]),
            'courier.test/aladdin/api/v1/zones/52/area-list' => Http::response(['data' => ['data' => [['area_id' => 908, 'area_name' => 'Pallabi']]]]),
        ]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/fetch', ['provider' => 'pathao'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('courier_area_options', ['provider' => 'pathao', 'level' => 'city', 'external_id' => '1', 'name' => 'Dhaka']);
        $this->assertDatabaseHas('courier_area_options', ['provider' => 'pathao', 'level' => 'zone', 'external_id' => '52', 'parent_external_id' => '1']);
        $this->assertDatabaseHas('courier_area_options', ['provider' => 'pathao', 'level' => 'area', 'external_id' => '908', 'parent_external_id' => '52']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->owner->id, 'title' => __('courier_areas.notice_fetch_done_title', ['courier' => 'Pathao'])]);
    }

    public function test_redx_list_is_fetched_and_gives_the_name_when_the_owner_only_types_a_number(): void
    {
        $this->enableCourier('redx');
        Http::fake(['courier.test/v1.0.0-beta/areas' => Http::response(['areas' => [['id' => 14, 'name' => 'Mirpur DOHS', 'post_code' => 1216]]])]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/fetch', ['provider' => 'redx'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/mappings', ['provider' => 'redx', 'place_code_id' => $this->district->id, 'area_id' => '14'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('courier_area_mappings', ['provider' => 'redx', 'place_code_id' => $this->district->id, 'area_id' => '14', 'area_name' => 'Mirpur DOHS']);
    }

    public function test_a_failed_fetch_tells_the_owner_and_keeps_manual_entry_open(): void
    {
        $this->enableCourier('redx');
        Http::fake(['courier.test/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/fetch', ['provider' => 'redx'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('account_notices', ['user_id' => $this->owner->id, 'title' => __('courier_areas.notice_fetch_failed_title', ['courier' => 'RedX'])]);
        $this->assertDatabaseCount('courier_area_options', 0);
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/operations/courier-areas/mappings', ['provider' => 'redx', 'place_code_id' => $this->district->id, 'area_id' => '14', 'area_name' => 'Mirpur'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('courier_area_mappings', ['provider' => 'redx', 'place_code_id' => $this->district->id]);
    }

    public function test_fetching_needs_the_courier_switched_on_with_keys(): void
    {
        Http::fake();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/fetch', ['provider' => 'pathao'])->assertSessionHasErrors('provider');

        Http::assertNothingSent();
    }

    public function test_pathao_needs_city_and_zone_and_redx_needs_a_name(): void
    {
        $post = fn (array $data) => $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/mappings', ['place_code_id' => $this->district->id, ...$data]);

        $post(['provider' => 'pathao', 'zone_id' => '5'])->assertSessionHasErrors('city_id');
        $post(['provider' => 'pathao', 'city_id' => '5'])->assertSessionHasErrors('zone_id');
        $post(['provider' => 'redx', 'area_id' => '5'])->assertSessionHasErrors('area_name');
        $post(['provider' => 'pathao', 'city_id' => '1;drop', 'zone_id' => '5'])->assertSessionHasErrors('city_id');
        $this->assertDatabaseCount('courier_area_mappings', 0);
    }

    public function test_only_the_owner_portal_and_owner_can_use_the_page(): void
    {
        $staff = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/operations/courier-areas/mappings', ['provider' => 'pathao', 'place_code_id' => $this->district->id, 'city_id' => '1', 'zone_id' => '2'])->assertForbidden();
        $this->actingAs($staff)->onPortal('manage')->get('/operations/courier-areas')->assertNotFound();
        $this->assertDatabaseCount('courier_area_mappings', 0);
    }

    public function test_the_page_lists_places_with_partners_and_shows_inherited_matches(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $partner->forceFill(['profile' => [...(array) $partner->getAttribute('profile'), 'place_code' => $this->union->code, 'place_code_id' => $this->union->id]])->save();
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/operations/courier-areas?provider=pathao')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('CourierAreas')
                ->where('summary.unmapped', fn ($count): bool => $count >= 1)
                ->where('places.data', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['code'] === $this->union->code && $row['partners'] === 1)));

        $this->map('pathao', $this->district, ['city_id' => '2', 'zone_id' => '77']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/operations/courier-areas?provider=pathao&view=in_use')
            ->assertInertia(fn (Assert $page) => $page
                ->where('places.data', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['code'] === $this->union->code && $row['inherited_from'] === $this->district->code && $row['mapping'] === null)));
    }

    public function test_paperfly_status_words_and_place_can_be_set_by_the_owner(): void
    {
        $this->enableCourier('paperfly', [
            'username' => 'm', 'password' => 'p',
            'status_field' => 'success.trackingStatus.0',
            'status_map' => "Pick = picked_up\ninTransit = in_transit\nDelivered = delivered",
        ]);
        Http::fake(['courier.test/*' => Http::response(['success' => ['trackingStatus' => [['Pick' => '2026-11-01', 'inTransit' => '2026-11-02', 'Delivered' => '']]]])]);

        $result = app(CourierGatewayClient::class)->track('paperfly', 'INV-1');

        $this->assertSame('inTransit', $result['status']);
        $this->assertSame('in_transit', $result['step']);
    }

    public function test_tracking_uses_the_owners_word_before_its_own_guess(): void
    {
        $this->enableCourier('paperfly', ['username' => 'm', 'password' => 'p', 'status_map' => 'Point Received = picked_up']);
        Http::fake(['courier.test/*' => Http::response(['status' => 'Point Received'])]);
        $shipment = $this->bookedPaperflyShipment();

        $this->assertNull(ShipmentTracking::normalize('Point Received'));
        $this->assertSame('picked_up', app(ShipmentTracking::class)->refresh((int) $shipment->id));
    }

    public function test_a_status_word_list_with_a_wrong_step_is_refused(): void
    {
        $settings = app(IntegrationSettings::class);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'couriers', 'provider' => 'paperfly', 'version' => $settings->snapshot()['version'], 'reason' => 'Setting the status words', 'enabled' => false,
            'status_map' => 'Delivered = done',
        ])->assertSessionHasErrors('status_map');
    }

    public function test_ecourier_with_no_package_code_is_held_without_calling_it(): void
    {
        $this->enableCourier('ecourier', ['user_id' => 'U1']);
        Http::fake();

        $result = app(CourierGatewayClient::class)->create('ecourier', ['invoice' => 'INV-1']);

        $this->assertFalse($result['success']);
        $this->assertSame(__('courier_areas.needs_package_code'), $result['message']);
        $this->assertArrayNotHasKey('outcome_unknown', $result);
        Http::assertNothingSent();
    }

    /** @param  array<string, string>  $overrides */
    private function enableCourier(string $provider, array $overrides = []): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, [
            'enabled' => true,
            'base_url' => 'https://courier.test',
            'api_key' => 'key',
            'api_secret' => 'secret',
            ...$overrides,
        ], $settings->snapshot()['version'], 'Courier test');
    }

    /** @param  array<string, string>  $ids */
    private function map(string $provider, PlaceCode $place, array $ids): void
    {
        app(CourierAreaMapping::class)->save($this->owner, ['provider' => $provider, 'place_code_id' => $place->id, ...$ids]);
    }

    private function book(string $provider, ?PlaceCode $place): object
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000000']);
        $dealer->forceFill(['profile' => $place ? ['place_code' => $place->code, 'place_code_id' => $place->id] : []])->save();
        $product = Product::create(['sku' => 'CAM-'.Str::random(6), 'name' => 'Area Product', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20]);
        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => $provider]);
        $order->update(['paid' => $order->total, 'status' => 'pending_approval']);
        $shipmentId = DB::table('shipments')->insertGetId(['order_id' => $order->id, 'actor_id' => $this->owner->id, 'courier' => ucfirst($provider), 'courier_provider' => $provider,
            'courier_booking_status' => 'pending', 'tracking' => 'PENDING-'.Str::random(6), 'status' => 'dispatched', 'created_at' => now(), 'updated_at' => now()]);
        BookShipmentCourier::dispatchSync($shipmentId);

        return DB::table('shipments')->where('id', $shipmentId)->first();
    }

    private function bookedPaperflyShipment(): object
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = Product::create(['sku' => 'CAM-'.Str::random(6), 'name' => 'Area Product', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20]);
        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $shipmentId = DB::table('shipments')->insertGetId(['order_id' => $order->id, 'actor_id' => $this->owner->id, 'courier' => 'Paperfly', 'courier_provider' => 'paperfly',
            'courier_booking_status' => 'booked', 'tracking' => 'PF-1', 'courier_status' => 'waiting_pickup', 'status' => 'dispatched', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('shipments')->where('id', $shipmentId)->first();
    }
}
