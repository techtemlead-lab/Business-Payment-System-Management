<?php

namespace Tests\Feature;

use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Jobs\BookShipmentCourier;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CourierBookingAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->admin, 'couriers', 'steadfast', [
            'enabled' => true,
            'base_url' => 'https://courier.test/api/v1',
            'api_key' => 'key',
            'api_secret' => 'secret',
        ], $settings->snapshot()['version'], 'Courier booking test');
    }

    public function test_the_courier_is_booked_once_after_the_dispatch_is_saved(): void
    {
        Http::fake(['courier.test/*' => Http::response(['consignment' => ['consignment_id' => 'CN-1', 'tracking_code' => 'SF-TRACK-1']])]);
        $order = $this->approvedOrder();
        $payload = $this->dispatchPayload($order);

        app(OrderService::class)->dispatch($this->admin, $order, $payload);

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('SF-TRACK-1', $shipment->tracking);
        $this->assertSame('booked', $shipment->courier_booking_status);
        $this->assertSame('CN-1', $shipment->courier_consignment_id);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['invoice'] === $order->number.'-'.$shipment->id);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booked']);
        $notice = DB::table('account_notices')->where('user_id', $this->dealer->id)->orderByDesc('id')->first();
        $this->assertStringContainsString('SF-TRACK-1', (string) $notice->body);

        // Replaying the same dispatch, or running the job again, never books a second parcel.
        app(OrderService::class)->dispatch($this->admin, $order->fresh(), $payload);
        (new BookShipmentCourier($shipment->id))->handle(app(CourierGatewayClient::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_a_rolled_back_dispatch_never_books_the_courier(): void
    {
        Http::fake(['courier.test/*' => Http::response(['consignment' => ['consignment_id' => 'CN-2', 'tracking_code' => 'SF-TRACK-2']])]);
        $order = $this->approvedOrder();

        try {
            DB::transaction(function () use ($order): void {
                app(OrderService::class)->dispatch($this->admin, $order, $this->dispatchPayload($order));
                throw new RuntimeException('Something later in the same save failed.');
            });
            $this->fail('The outer save should have failed.');
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
    }

    public function test_a_refused_booking_is_marked_failed_without_undoing_the_dispatch(): void
    {
        Http::fake(['courier.test/*' => Http::response(['message' => 'Rejected'], 422)]);
        $order = $this->approvedOrder();

        app(OrderService::class)->dispatch($this->admin, $order, $this->dispatchPayload($order));

        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $this->assertSame('failed', $shipment->courier_booking_status);
        $this->assertNotNull($shipment->courier_booking_error);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'dispatched']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_booking_failed']);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $this->dealer->id, 'title' => __('portal.notice_shipment_dispatched_title', ['number' => $order->number])]);

        // A failed booking is not sent again by a repeated job.
        (new BookShipmentCourier($shipment->id))->handle(app(CourierGatewayClient::class));
        Http::assertSentCount(1);
    }

    private function approvedOrder(): Order
    {
        $product = Product::create([
            'sku' => 'CRR-'.Str::random(6),
            'name' => 'Courier Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($this->dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->admin, $order->fresh(), 'approved', 'Paid in full');

        return $order->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchPayload(Order $order): array
    {
        return [
            'courier' => 'Steadfast',
            'courier_provider' => 'steadfast',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $order->lines()->value('id'), 'quantity' => 2]],
        ];
    }
}
