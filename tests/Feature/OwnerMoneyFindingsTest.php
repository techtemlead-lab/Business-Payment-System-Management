<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockService;
use App\Domain\Inventory\StockStages;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the browser check found on the owner's money, orders and deliveries pages. A parcel sent
 * with a typed tracking number has no courier system behind it, and is the default way to send.
 */
class OwnerMoneyFindingsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
    }

    public function test_a_typed_tracking_parcel_can_be_recorded_as_returned_and_goes_back_to_stock_with_no_commission(): void
    {
        [$order, $shipmentId, $product] = $this->sentTypedParcel();
        $this->assertSame(['in_stock' => 7, 'with_courier' => 3], $this->stockStages($product));

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/manual-update', ['status' => 'returned'])
            ->assertSessionHasNoErrors();
        $this->assertSame('returned', DB::table('shipments')->where('id', $shipmentId)->value('courier_status'));

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/received-back', ['note' => 'Dealer shop was closed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('returned', DB::table('shipments')->where('id', $shipmentId)->value('status'));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(['in_stock' => 10, 'with_courier' => 0], $this->stockStages($product));
        $this->assertDatabaseMissing('commission_batches', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('journal_entries', ['event_key' => 'commissions.order.'.$order->id]);
    }

    public function test_a_booked_parcel_still_cannot_be_moved_by_hand(): void
    {
        [$order, $shipmentId] = $this->sentTypedParcel();
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_provider' => 'steadfast', 'courier_booking_status' => 'booked']);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipmentId.'/manual-update', ['status' => 'returned'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_typed_tracking_parcel_shows_on_deliveries_in_its_own_stage(): void
    {
        [$order] = $this->sentTypedParcel();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=parcels')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.parcels_table.rows.data', 1)
                ->where('data.parcels_table.rows.data.0.number', $order->number)
                ->where('data.parcels_table.rows.data.0.step', 'waiting_pickup')
                ->where('data.parcel_stages.with_courier', 1)
                ->where('data.parcel_stages.waiting_pickup', 1));
    }

    public function test_the_sent_filter_on_deliveries_lists_orders_with_all_their_goods_out(): void
    {
        [$order] = $this->sentTypedParcel();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?status=dispatched')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', $order->number));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.orders.data', 0)->where('data.delivery_summary.orders', 0));
    }

    public function test_confirming_delivery_moves_the_parcel_to_delivered_on_every_figure(): void
    {
        [$order, $shipmentId] = $this->sentTypedParcel();
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_status' => 'waiting_pickup']);

        app(OrderService::class)->deliver($this->owner, $order->fresh(), $shipmentId, 'Signed by the dealer');

        $parcel = DB::table('shipments')->where('id', $shipmentId)->first();
        $this->assertSame('delivered', $parcel->courier_status);
        $this->assertSame('delivered', collect(json_decode((string) $parcel->courier_timeline, true))->last()['status']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=parcels&ship_status=delivered')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.parcels_table.rows.data', 1)
                ->where('data.parcels_table.rows.data.0.step', 'delivered')
                ->where('data.parcel_stages.delivered', 1)
                ->where('data.parcel_stages.waiting_pickup', 0)
                ->where('data.courier_board.counts.delivered', 1));
    }

    public function test_a_finished_parcel_saved_with_an_old_step_is_counted_as_finished(): void
    {
        [, $shipmentId] = $this->sentTypedParcel();
        DB::table('shipments')->where('id', $shipmentId)->update(['status' => 'delivered', 'courier_status' => 'waiting_pickup']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=parcels')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.parcels_table.rows.data', 0)
                ->where('data.parcel_stages.with_courier', 0)
                ->where('data.parcel_stages.waiting_pickup', 0)
                ->where('data.parcel_stages.delivered', 1)
                ->where('data.courier_board.counts.delivered', 1)
                ->missing('data.courier_board.counts.waiting_pickup'));
    }

    public function test_the_month_end_journal_shows_the_order_number_and_no_system_code(): void
    {
        [$order] = $this->sentTypedParcel();
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'commissions.order.'.$order->id, 'description' => 'Commission earned', 'created_at' => now()]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/settlements')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.settlement_state.settled_history.0.order_number', $order->number)
                ->where('data.settlement_state.settled_history.0.event_word', __('portal.st_event_order'))
                ->missing('data.settlement_state.settled_history.0.event_key'));
    }

    public function test_a_fully_returned_order_has_its_own_label_and_stays_visible(): void
    {
        [$returned, $shipmentId] = $this->sentTypedParcel();
        DB::table('orders')->where('id', $returned->id)->update(['status' => 'cancelled']);
        DB::table('shipments')->where('id', $shipmentId)->update(['status' => 'returned', 'courier_status' => 'returned']);
        $cancelled = $this->order('cancelled');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders?status=returned')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', $returned->number)
                ->where('data.orders.data.0.came_back', true)
                ->where('data.order_status_counts.returned', 1)
                ->where('data.order_status_counts.cancelled', 1));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders?status=cancelled')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', $cancelled->number)
                ->where('data.orders.data.0.came_back', false));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders?status=returned')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.orders.data', 1)->where('data.orders.data.0.came_back', true));
    }

    public function test_an_order_placed_just_after_midnight_in_bangladesh_is_numbered_with_that_date(): void
    {
        Carbon::setTestNow('2026-11-09 18:30:00');
        $product = $this->stockedProduct();

        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);

        $this->assertStringStartsWith('MN-261110-', $order->number);
        Carbon::setTestNow();
    }

    public function test_the_office_row_of_a_refunded_order_carries_the_refund(): void
    {
        $order = $this->order('cancelled', '400.00');
        DB::table('order_refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'user_id' => $this->dealer->id, 'amount' => '400.00', 'paid_before' => '400.00', 'reason' => 'Customer changed mind', 'refunded_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.orders.data.0.refunded_amount', '400.00'));
    }

    /** @return array{0: Order, 1: int, 2: Product} */
    private function sentTypedParcel(): array
    {
        $product = $this->stockedProduct();
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        app(OrderService::class)->dispatch($this->owner, $order->fresh(), ['courier' => 'Local Van', 'tracking' => 'TYPED-1', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 3]]]);

        return [$order->fresh(), (int) DB::table('shipments')->where('order_id', $order->id)->value('id'), $product];
    }

    private function stockedProduct(): Product
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0, 'active' => true]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $product->id, 'quantity' => 10, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id,
            'batch_number' => 'LOT-A', 'expires_on' => now()->addDays(200)->toDateString()]);

        return $product;
    }

    private function order(string $status, string $paid = '0.00'): Order
    {
        return Order::create(['number' => 'ORD-'.Str::upper(Str::random(8)), 'user_id' => $this->dealer->id, 'status' => $status, 'total' => '400.00', 'paid' => $paid, 'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
    }

    /** @return array{in_stock: int, with_courier: int} */
    private function stockStages(Product $product): array
    {
        $row = collect(app(StockStages::class)->forProduct($product)['branches'])->firstWhere('branch_id', $this->hub->id);

        return ['in_stock' => $row['in_stock'], 'with_courier' => $row['with_courier']];
    }
}
