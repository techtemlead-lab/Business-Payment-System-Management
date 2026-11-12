<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Jobs\BookShipmentCourier;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Courier drivers against each courier's published request shape, the manual courier mode, and
 * the staff decision on an order with one parcel delivered and another returned. Couriers are
 * never called for real: every request goes to Http::fake().
 */
class CourierDriversAndManualModeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    private User $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000000']);
        $this->store = User::factory()->staff('inventory')->create(['status' => 'active']);
    }

    public function test_redx_is_sent_its_own_token_header(): void
    {
        $this->enableCourier('redx', ['api_key' => 'Bearer abc']);
        Http::fake(['redx.test/*' => Http::response(['tracking_id' => 'RX-1'])]);

        $this->assertTrue(app(CourierGatewayClient::class)->create('redx', $this->parcel())['success']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('API-ACCESS-TOKEN', 'Bearer abc') && ! $request->hasHeader('Authorization'));
    }

    public function test_pathao_signs_in_for_a_token_and_reuses_it(): void
    {
        $this->enableCourier('pathao', ['api_key' => 'client-1', 'api_secret' => 'secret-1', 'username' => 'shop@example.test', 'password' => 'pw-1']);
        Http::fake([
            'courier.test/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'token-9', 'expires_in' => 432000]),
            'courier.test/aladdin/api/v1/orders' => Http::response(['data' => ['consignment_id' => 'DP-1', 'order_status' => 'Pending']]),
        ]);
        $client = app(CourierGatewayClient::class);

        $this->assertSame('DP-1', $client->create('pathao', $this->parcel())['tracking']);
        $client->create('pathao', $this->parcel());

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issue-token')
            && $request['client_id'] === 'client-1' && $request['client_secret'] === 'secret-1'
            && $request['username'] === 'shop@example.test' && $request['password'] === 'pw-1' && $request['grant_type'] === 'password');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders') && $request->hasHeader('Authorization', 'Bearer token-9'));
    }

    public function test_pathao_without_a_login_uses_the_key_as_a_token(): void
    {
        $this->enableCourier('pathao', ['api_key' => 'pasted-token']);
        Http::fake(['courier.test/*' => Http::response(['data' => ['consignment_id' => 'DP-2']])]);

        $this->assertTrue(app(CourierGatewayClient::class)->create('pathao', $this->parcel())['success']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer pasted-token'));
    }

    public function test_ecourier_gets_the_mandatory_fields_from_its_published_document(): void
    {
        $this->enableCourier('ecourier', ['user_id' => 'U1', 'package_code' => '#7777']);
        Http::fake(['courier.test/*' => Http::response(['response_code' => 200, 'ID' => 'ECR-1'])]);

        $result = app(CourierGatewayClient::class)->create('ecourier', [...$this->parcel(), 'recipient_city' => 'Rajshahi', 'recipient_thana' => 'Boalia', 'recipient_area' => 'Kazla']);

        $this->assertSame('ECR-1', $result['tracking']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/order-place')
            && $request->hasHeader('USER-ID', 'U1') && $request['recipient_city'] === 'Rajshahi' && $request['recipient_thana'] === 'Boalia'
            && $request['recipient_area'] === 'Kazla' && $request['package_code'] === '#7777' && $request['recipient_zip'] === ''
            && $request['product_price'] === 0 && strlen($request['product_id']) <= 20);
    }

    public function test_ecourier_tracking_posts_the_number_and_reads_the_newest_step(): void
    {
        $this->enableCourier('ecourier', ['user_id' => 'U1']);
        Http::fake(['courier.test/*' => Http::response(['response_code' => 200, 'query_data' => [['status' => [
            ['Delivered', '', '2017-01-20 12:04:55'],
            ['On the way to Delivery', 'Status has been changed', '2017-01-20 12:03:39'],
            ['Initiated', 'An order has been made', '2017-01-20 12:00:09'],
        ]]]])]);

        $result = app(CourierGatewayClient::class)->track('ecourier', 'ECR-1');

        $this->assertSame('Delivered', $result['status']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/track') && $request['ecr'] === 'ECR-1');
        $this->assertSame('in_transit', ShipmentTracking::normalize('In Destination Branch'));
        $this->assertSame('waiting_pickup', ShipmentTracking::normalize('Initiated'));
    }

    public function test_paperfly_uses_the_v2_order_and_tracking_calls(): void
    {
        $this->enableCourier('paperfly', ['username' => 'merchant', 'password' => 'pw']);
        Http::fake(['courier.test/*' => Http::response(['response_code' => 200, 'success' => ['tracking_number' => 'PF-1']])]);
        $client = app(CourierGatewayClient::class);

        $this->assertSame('PF-1', $client->create('paperfly', $this->parcel())['tracking']);
        $client->track('paperfly', 'INV-1');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merchant/api/service/new_order_v2.php')
            && $request->hasHeader('paperflykey', 'key') && $request['merchantOrderReference'] === 'INV-1' && $request['customerPhone'] === '01711000000');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/API-Order-Tracking') && $request['ReferenceNumber'] === 'INV-1');
    }

    public function test_a_manual_courier_is_switched_on_without_keys_and_offered_on_the_order_form(): void
    {
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.courier_choices.0.key', 'company'));

        foreach (IntegrationCatalog::ManualCouriers as $courier) {
            $this->enableManual($courier);
        }

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->count('data.courier_choices', 6)
                ->where('data.courier_choices.0.key', 'sundarban')
                ->where('data.courier_choices.0.manual', true));
    }

    public function test_approving_a_manual_courier_order_books_nothing_and_asks_staff_to_send_it(): void
    {
        $this->enableManual('sundarban');
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('sundarban');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame('approved', $order->fresh()->status);
        Bus::assertNotDispatched(BookShipmentCourier::class);
        Http::assertNothingSent();
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->owner->id, 'title' => __('courier_more.notice_manual_dispatch_title', ['number' => $order->number])]);
    }

    public function test_staff_send_a_manual_parcel_with_a_consignment_number_and_the_dealer_follows_it(): void
    {
        $this->enableManual('sundarban');
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->approvedManualOrder();
        $line = $order->lines()->first();
        $payload = ['courier' => 'Sundarban Courier', 'courier_provider' => 'sundarban', 'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line->id, 'quantity' => 2]]];

        $this->actingAs($this->store)->onPortal('manage')->post('/commands/orders/'.$order->id.'/dispatch', $payload)->assertSessionHasErrors('tracking');
        $this->assertDatabaseCount('shipments', 0);

        $this->actingAs($this->store)->onPortal('manage')->post('/commands/orders/'.$order->id.'/dispatch', [...$payload, 'tracking' => 'SB-100'])->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('manual', $shipment->courier_booking_status);
        $this->assertSame('sundarban', $shipment->courier_provider);
        $this->assertSame('SB-100', $shipment->tracking);
        $this->assertSame('waiting_pickup', $shipment->courier_status);
        Bus::assertNotDispatched(BookShipmentCourier::class);
        $this->assertFalse(ShipmentTracking::isTrackable($shipment));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OrderDetail')
                ->where('shipments.0.tracking', 'SB-100')
                ->where('shipments.0.courier_status', 'waiting_pickup')
                ->where('shipments.0.tracking_url', 'https://tracking.sundarbancourierltd.com/'));
        Http::assertNothingSent();
    }

    public function test_staff_move_a_manual_parcel_forward_only_and_delivered_releases_nothing(): void
    {
        [$order, $shipmentId] = $this->sentManualParcel();
        $url = '/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/manual-update';

        $this->actingAs($this->store)->onPortal('manage')->post($url)->assertSessionHasErrors('status');
        $this->actingAs($this->store)->onPortal('manage')->post($url, ['status' => 'in_transit', 'tracking' => 'SB-200'])->assertSessionHasNoErrors();
        $this->assertSame('SB-200', DB::table('shipments')->where('id', $shipmentId)->value('tracking'));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'shipment.consignment_updated']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'shipment.courier_status']);

        $this->actingAs($this->store)->onPortal('manage')->post($url, ['status' => 'picked_up'])->assertSessionHasErrors('status');
        $this->assertSame('in_transit', DB::table('shipments')->where('id', $shipmentId)->value('courier_status'));

        $this->actingAs($this->store)->onPortal('manage')->post($url, ['status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertSame('dispatched', DB::table('shipments')->where('id', $shipmentId)->value('status'));
        $this->assertSame('dispatched', $order->fresh()->status);

        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id, 'title' => __('courier.notice_status_title', ['number' => $order->number], $locale)]);
        Http::assertNothingSent();
    }

    public function test_only_store_staff_can_update_a_manual_parcel_and_only_a_manual_one(): void
    {
        [$order, $shipmentId] = $this->sentManualParcel();
        $url = '/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/manual-update';

        $this->actingAs($this->dealer)->onPortal('dealer')->post($url, ['status' => 'picked_up'])->assertForbidden();
        $this->actingAs(User::factory()->staff('finance')->create(['status' => 'active']))->onPortal('manage')->post($url, ['status' => 'picked_up'])->assertForbidden();

        DB::table('shipments')->where('id', $shipmentId)->update(['courier_booking_status' => 'booked']);
        $this->actingAs($this->store)->onPortal('manage')->post($url, ['status' => 'picked_up'])->assertSessionHasErrors('status');
        $this->assertSame('waiting_pickup', DB::table('shipments')->where('id', $shipmentId)->value('courier_status'));
    }

    public function test_a_failed_booking_switched_to_a_manual_courier_is_not_sent_to_any_booking(): void
    {
        $this->enableManual('sundarban');
        $this->enableCourier('steadfast', []);
        Bus::fake([BookShipmentCourier::class]);
        $order = $this->paidOrder('steadfast');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_booking_status' => 'failed']);
        Bus::assertDispatchedTimes(BookShipmentCourier::class, 1);

        $this->actingAs($this->store)->onPortal('manage')->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'sundarban'])->assertSessionHasNoErrors();

        $shipment = DB::table('shipments')->where('id', $shipmentId)->first();
        $this->assertSame('manual', $shipment->courier_booking_status);
        $this->assertSame('waiting_pickup', $shipment->courier_status);
        Bus::assertDispatchedTimes(BookShipmentCourier::class, 1);
    }

    public function test_a_mixed_order_waits_on_the_board_until_staff_record_a_decision(): void
    {
        $this->enableFinancialPosting();
        $order = $this->mixedOrder();
        $reviewer = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.courier_board.needs_decision.0.order_id', $order->id)
                ->where('data.courier_board.can_decide', true));

        $url = '/commands/orders/'.$order->id.'/mixed-outcome-decision';
        $this->reasonFields(['order.commission_decision' => 'required']);
        $this->actingAs($reviewer)->onPortal('manage')->post($url, ['decision' => 'no_commission'])->assertSessionHasErrors('reason');
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.mixed_outcome_decided']);

        $this->actingAs($reviewer)->onPortal('manage')->post($url, ['decision' => 'no_commission', 'reason' => 'Dealer agreed on the phone'])->assertSessionHasNoErrors();

        $event = DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.mixed_outcome_decided')->first();
        $this->assertSame(['decision' => 'no_commission', 'reason' => 'Dealer agreed on the phone', 'fund_amount' => '112.00'], json_decode($event->metadata, true));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $reviewer->id, 'action' => 'order.mixed_outcome_decided', 'subject' => $order->id]);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id, 'title' => __('courier_more.notice_decision_title', ['number' => $order->number], $locale)]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->count('data.courier_board.needs_decision', 0));
        $this->actingAs($reviewer)->onPortal('manage')->post($url, ['decision' => 'delivered_part', 'reason' => 'Changed my mind'])->assertSessionHasErrors('decision');

        // Nobody was paid: the commission sits in the company profit fund, and the order did not change state.
        app(CommissionPosting::class)->process($order->fresh(), $this->owner->id);
        $this->assertDatabaseCount('commission_batches', 1);
        $this->assertSame(0, CommissionAllocation::query()->whereNotNull('user_id')->count());
        $this->assertSame(1, CommissionAllocation::query()->where('category', CommissionPosting::NoCommissionCategory)->count());
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    public function test_a_decision_is_refused_without_permission_or_when_the_order_is_not_mixed(): void
    {
        $order = $this->mixedOrder();
        $url = '/commands/orders/'.$order->id.'/mixed-outcome-decision';
        $body = ['decision' => 'no_commission', 'reason' => 'Dealer agreed on the phone'];

        $this->actingAs($this->store)->onPortal('manage')->post($url, $body)->assertForbidden();
        $this->actingAs($this->dealer)->onPortal('dealer')->post($url, $body)->assertForbidden();

        DB::table('shipments')->where('order_id', $order->id)->where('status', 'returned')->update(['status' => 'dispatched']);
        $this->actingAs($this->owner)->onPortal('sadmin')->post($url, $body)->assertSessionHasErrors('decision');
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.mixed_outcome_decided']);
    }

    /** @return array{recipient_name: string, recipient_phone: string, recipient_address: string, amount: string, invoice: string, note: string} */
    private function parcel(): array
    {
        return ['recipient_name' => 'Test Dealer', 'recipient_phone' => '01711000000', 'recipient_address' => 'House 1, Rajshahi', 'amount' => '0', 'invoice' => 'INV-1', 'note' => 'MN-1'];
    }

    /** @param  array<string, string>  $overrides */
    private function enableCourier(string $provider, array $overrides): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, [
            'enabled' => true,
            'base_url' => $provider === 'redx' ? 'https://redx.test' : 'https://courier.test',
            'api_key' => 'key',
            'api_secret' => 'secret',
            ...$overrides,
        ], $settings->snapshot()['version'], 'Courier test');
    }

    private function enableManual(string $provider): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, ['enabled' => true], $settings->snapshot()['version'], 'Courier test');
    }

    private function paidOrder(?string $courier): Order
    {
        $product = Product::create(['sku' => 'CDM-'.Str::random(6), 'name' => 'Courier Product', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20]);
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => $courier]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);

        return $order->fresh();
    }

    private function approvedManualOrder(): Order
    {
        $order = $this->paidOrder('sundarban');
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        return $order->fresh();
    }

    /** @return array{0: Order, 1: int} */
    private function sentManualParcel(): array
    {
        $this->enableManual('sundarban');
        $order = $this->approvedManualOrder();
        app(OrderService::class)->dispatch($this->store, $order, ['courier' => 'Sundarban Courier', 'tracking' => 'SB-100', 'courier_provider' => 'sundarban',
            'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $order->lines()->first()->id, 'quantity' => 2]]]);

        return [$order->fresh(), (int) DB::table('shipments')->where('order_id', $order->id)->value('id')];
    }

    /** An order with one parcel delivered and another received back. */
    private function mixedOrder(): Order
    {
        [$order, $shipmentId] = $this->sentManualParcel();
        DB::table('shipments')->where('id', $shipmentId)->update(['status' => 'returned', 'courier_status' => 'returned']);
        $delivered = (array) DB::table('shipments')->where('id', $shipmentId)->first();
        unset($delivered['id']);
        DB::table('shipments')->insert([...$delivered, 'status' => 'delivered', 'courier_status' => 'delivered', 'idempotency_key' => (string) Str::uuid()]);

        return $order;
    }
}
