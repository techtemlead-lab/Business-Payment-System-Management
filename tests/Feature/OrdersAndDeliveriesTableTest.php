<?php

namespace Tests\Feature;

use App\Domain\Pricing\ProductPricing;
use App\Http\Portals\Shared\Sections\OrdersSection;
use App\Http\Portals\Shared\Sections\SectionContext;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrdersAndDeliveriesTableTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->dealer = User::factory()->dealer()->create(['name' => 'Rahim Traders']);
    }

    public function test_the_office_order_list_totals_count_delivered_money_only_and_keep_closed_orders(): void
    {
        $this->order('delivered', '1000.00', '1000.00');
        $this->order('submitted', '700.00');
        $this->order('cancelled', '300.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 3)
                ->where('data.orders_table.totals.count', 3)
                ->where('data.orders_table.totals.sums.value', '1000.00')
                ->where('data.orders_table.totals.sums.paid', '1000.00')
                ->where('data.orders_table.totals.sums.to_pay', '700.00')
                ->where('data.orders_table.state.sort', 'created_at')
                ->where('data.orders_table.state.dir', 'desc')
                ->where('data.orders_table.state.period', '')
                ->where('data.orders_table.extra_periods', ['last_month']));
    }

    public function test_a_row_carries_what_its_side_panel_shows(): void
    {
        $order = $this->order('dispatched', '500.00', '500.00');
        DB::table('payment_claims')->insert(['order_id' => $order->id, 'channel' => 'bKash', 'reference' => 'TRX-77', 'amount' => '500.00', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        $this->parcel($order, ['courier_provider' => 'steadfast', 'tracking' => 'ST-1', 'courier_status' => 'in_transit']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.orders.data.0.payment_claims.0.reference', 'TRX-77')
                ->where('data.orders.data.0.shipments.0.tracking', 'ST-1')
                ->where('data.orders.data.0.shipments.0.tracking_url', fn (?string $url): bool => $url !== null && str_contains($url, 'ST-1')));
    }

    public function test_sorting_runs_over_every_page_and_ignores_unknown_columns(): void
    {
        foreach (range(1, 27) as $index) {
            $this->order('submitted', $index.'0.00');
        }

        $cheapestFirst = $this->sectionData('orders', ['sort' => 'total', 'dir' => 'asc']);
        $this->assertSame('10.00', $cheapestFirst['orders']->first()->total);
        $this->assertCount(25, $cheapestFirst['orders']->items());
        $this->assertSame(27, $cheapestFirst['orders_table']['totals']['count']);
        $this->assertSame(['sort' => 'total', 'dir' => 'asc'], array_intersect_key($cheapestFirst['orders_table']['state'], ['sort' => 1, 'dir' => 1]));

        $mostExpensiveFirst = $this->sectionData('orders', ['sort' => 'total', 'dir' => 'desc']);
        $this->assertSame('270.00', $mostExpensiveFirst['orders']->first()->total);

        $unknown = $this->sectionData('orders', ['sort' => 'orders.id; drop table users', 'dir' => 'sideways']);
        $this->assertSame('created_at', $unknown['orders_table']['state']['sort']);
        $this->assertSame('desc', $unknown['orders_table']['state']['dir']);
    }

    public function test_orders_can_be_sorted_by_partner_name(): void
    {
        $other = User::factory()->dealer()->create(['name' => 'Aslam Store']);
        $this->order('submitted', '10.00');
        $this->order('submitted', '20.00', user: $other);

        $byPartner = $this->sectionData('orders', ['sort' => 'partner', 'dir' => 'asc']);

        $this->assertSame($other->id, $byPartner['orders']->first()->user_id);
    }

    public function test_the_deliveries_send_view_lists_orders_to_send_with_no_money_sums(): void
    {
        $order = $this->order('approved', '900.00');
        $order->lines()->create($this->lineFields(4, 1));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.delivery_view', 'send')
                ->has('data.orders.data', 1)
                ->where('data.orders_table.totals.count', 1)
                ->where('data.orders_table.totals.sums', null)
                ->where('data.delivery_summary.units', 3)
                ->missing('data.parcels_table'));
    }

    public function test_the_parcel_view_is_one_table_with_search_step_filter_and_stage_counts(): void
    {
        $first = $this->order('dispatched', '500.00');
        $second = $this->order('dispatched', '600.00', user: User::factory()->dealer()->create(['name' => 'Karim Store']));
        $this->parcel($first, ['courier_provider' => 'steadfast', 'tracking' => 'ST-1', 'courier_status' => 'in_transit']);
        $this->parcel($second, ['courier_provider' => 'steadfast', 'tracking' => 'ST-2', 'courier_status' => 'delivered', 'status' => 'delivered']);
        $this->parcel($second, ['courier_provider' => 'steadfast', 'tracking' => 'ST-3', 'courier_status' => 'returned', 'status' => 'returned']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=parcels')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.delivery_view', 'parcels')
                ->missing('data.orders')
                ->has('data.parcels_table.rows.data', 3)
                ->where('data.parcel_stages.with_courier', 1)
                ->where('data.parcel_stages.delivered', 1)
                ->where('data.parcel_stages.returned', 1));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=parcels&q=ST-2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.parcels_table.rows.data', 1)
                ->where('data.parcels_table.rows.data.0.partner', 'Karim Store'));

        // An old link names only the filter.
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?ship_status=in_transit')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.delivery_view', 'parcels')
                ->has('data.parcels_table.rows.data', 1)
                ->where('data.parcels_table.rows.data.0.tracking', 'ST-1')
                ->where('data.parcels_table.state.filters.ship_status', 'in_transit'));
    }

    public function test_a_search_on_the_parcel_view_leaves_the_to_send_count_alone(): void
    {
        $this->order('approved', '900.00')->lines()->create($this->lineFields(2, 0));
        $this->parcel($this->order('dispatched', '500.00'), ['courier_provider' => 'steadfast', 'tracking' => 'ST-9']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=parcels&q=ST-9')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.delivery_summary.orders', 1));
    }

    public function test_the_refused_bookings_view_lists_only_refused_bookings(): void
    {
        $order = $this->order('dispatched', '500.00');
        $this->parcel($order, ['courier_provider' => 'steadfast', 'tracking' => 'ST-1', 'courier_booking_status' => 'failed', 'courier_booking_error' => 'Address missing']);
        $this->parcel($order, ['courier_provider' => 'steadfast', 'tracking' => 'ST-2', 'courier_booking_status' => 'booked']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries?view=failed')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.parcels_table.rows.data', 1)
                ->where('data.parcels_table.rows.data.0.courier_booking_error', 'Address missing')
                ->where('data.parcels_table.rows.data.0.step', 'failed')
                ->where('data.courier_board.counts.failed', 1)
                ->where('data.parcel_stages.with_courier', 2)
                ->where('data.parcel_stages.waiting_pickup', 1));
    }

    public function test_parcels_outside_the_branch_are_not_listed(): void
    {
        $staff = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders']]]);
        $this->parcel($this->order('dispatched', '500.00'), ['courier_provider' => 'steadfast', 'tracking' => 'ST-1']);

        $this->actingAs($staff)->onPortal('manage')->get('/deliveries?view=parcels')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.parcels_table.rows.data', 0)
                ->where('data.parcels_table.totals.count', 0)
                ->where('data.parcel_stages.with_courier', 0));
    }

    public function test_partners_keep_their_own_list_with_no_office_tables(): void
    {
        foreach (range(1, 14) as $index) {
            $this->order('submitted', '50.00');
        }

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 12)
                ->missing('data.orders_table')
                ->missing('data.orders.data.0.payment_claims'));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/deliveries?view=parcels')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders')
                ->missing('data.parcels_table')
                ->missing('data.parcel_stages')
                ->missing('data.courier_board'));
    }

    /**
     * Sorting is read straight from the section: the portal's request rules are combined later.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function sectionData(string $section, array $query): array
    {
        $request = Request::create('/'.$section, 'GET', $query);
        $request->setUserResolver(fn (): User => $this->owner);

        return app(OrdersSection::class)->data(new SectionContext(
            request: $request,
            user: $this->owner,
            portal: 'sadmin',
            section: $section,
            isStaff: true,
            usingStaffWorkspace: false,
            catalogPortal: 'sadmin',
            orders: Order::query(),
            pricing: app(ProductPricing::class),
        ), []);
    }

    private function order(string $status, string $total, string $paid = '0.00', ?User $user = null): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => ($user ?? $this->dealer)->id,
            'status' => $status,
            'total' => $total,
            'paid' => $paid,
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFields(int $quantity, int $dispatched): array
    {
        $product = Product::factory()->create();

        return ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'quantity' => $quantity, 'dispatched' => $dispatched, 'mrp' => '10.00', 'unit_price' => '10.00', 'total' => '10.00'];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function parcel(Order $order, array $fields): void
    {
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'courier' => 'Steadfast',
            'tracking' => '',
            'lines' => '[]',
            'actor_id' => $this->owner->id,
            'status' => 'dispatched',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
            ...$fields,
        ]);
    }
}
