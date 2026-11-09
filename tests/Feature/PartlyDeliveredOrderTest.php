<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OverdueDeliveries;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartlyDeliveredOrderTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = 'migrations/2026_11_09_124038_mark_finished_mixed_orders_partly_delivered.php';

    private User $owner;

    private User $dealer;

    private CompanyBranch $hub;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 0]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $this->product->id, 'quantity' => 100, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id, 'batch_number' => 'B1', 'expires_on' => now()->addYear()->toDateString()]);
    }

    public function test_the_order_is_partly_delivered_when_the_returned_parcel_finishes_last(): void
    {
        [$order, $parcels] = $this->sentOrder([3, 2]);

        $this->confirmDelivered($order, $parcels[0]);
        $this->assertSame('dispatched', $order->fresh()->status, 'The other parcel is still on the road.');

        $this->receiveBack($order, $parcels[1]);

        $this->assertSame('partly_delivered', $order->fresh()->status);
    }

    public function test_the_order_is_partly_delivered_when_the_delivered_parcel_finishes_last(): void
    {
        [$order, $parcels] = $this->sentOrder([3, 2]);

        $this->receiveBack($order, $parcels[1]);
        $this->assertSame('dispatched', $order->fresh()->status);

        $this->confirmDelivered($order, $parcels[0]);

        $this->assertSame('partly_delivered', $order->fresh()->status);
    }

    public function test_an_order_with_goods_still_to_send_stays_open(): void
    {
        [$order, $parcels] = $this->sentOrder([3, 2], ordered: 8);

        $this->confirmDelivered($order, $parcels[0]);
        $this->receiveBack($order, $parcels[1]);

        $this->assertSame('partially_dispatched', $order->fresh()->status);
    }

    public function test_all_delivered_and_all_returned_keep_their_own_final_status(): void
    {
        [$delivered, $deliveredParcels] = $this->sentOrder([3, 2]);
        foreach ($deliveredParcels as $parcel) {
            $this->confirmDelivered($delivered, $parcel);
        }
        [$returned, $returnedParcels] = $this->sentOrder([3, 2]);
        foreach ($returnedParcels as $parcel) {
            $this->receiveBack($returned, $parcel);
        }

        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertSame('cancelled', $returned->fresh()->status);
    }

    public function test_commission_posts_on_the_delivered_goods_by_itself_and_the_status_stays(): void
    {
        $order = $this->partlyDelivered();
        $orderIds = DB::table('orders')->select('id');

        $this->assertSame('partly_delivered', $order->fresh()->status);
        $this->assertSame('6000.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('cash_basis'));
        $this->assertSame([], app(MixedOutcomeDecisions::class)->pending($orderIds));
    }

    public function test_it_is_not_listed_as_overdue_any_more(): void
    {
        $order = $this->partlyDelivered();
        DB::table('shipments')->where('order_id', $order->id)->update(['created_at' => now()->subDays(OverdueDeliveries::Days + 3)]);

        $overdue = app(OverdueDeliveries::class)->apply(Order::query())->pluck('id')->all();

        $this->assertNotContains($order->id, $overdue);
    }

    public function test_the_partner_finds_it_on_the_order_list_filter_and_order_page(): void
    {
        $order = $this->partlyDelivered();

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders?status=partly_delivered')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.orders.data', 1)
                ->where('data.orders.data.0.id', $order->id)
                ->where('data.orders.data.0.status', 'partly_delivered')
                ->where('data.order_status_counts.partly_delivered', 1));
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.status', 'partly_delivered'));
    }

    public function test_the_status_reads_plainly_in_both_languages(): void
    {
        $this->assertSame('আংশিক ডেলিভার্ড', __('portal.status_partly_delivered', [], 'bn'));
        $this->assertSame('Partly delivered', __('portal.status_partly_delivered', [], 'en'));
        $this->assertSame('আংশিক ডেলিভার্ড', PartnerFullReport::statusWord('partly_delivered', 'bn'));
    }

    public function test_the_partner_statement_keeps_the_order_and_home_counts_the_delivered_part(): void
    {
        $order = $this->partlyDelivered();

        $report = app(PartnerFullReport::class)->for($this->dealer);
        $home = app(PartnerDashboardHome::class)->for($this->dealer);

        $this->assertContains($order->number, collect($report['orders'])->pluck('number')->all());
        $this->assertSame('6000.00', $home['snapshot']['own_sales_today']);
    }

    public function test_orders_already_stuck_are_marked_and_their_money_is_left_alone(): void
    {
        $stuck = $this->partlyDelivered();
        DB::table('orders')->where('id', $stuck->id)->update(['status' => 'dispatched']);
        [$stillMoving, $moving] = $this->sentOrder([3, 2]);
        $this->confirmDelivered($stillMoving, $moving[0]);
        $money = fn (): array => [
            DB::table('commission_batches')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            DB::table('commission_allocations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            DB::table('wallets')->orderBy('user_id')->get()->map(fn (object $row): array => (array) $row)->all(),
            DB::table('journal_entries')->count(),
        ];
        $before = $money();

        (require database_path(self::Migration))->up();

        $this->assertSame('partly_delivered', $stuck->fresh()->status);
        $this->assertSame('dispatched', $stillMoving->fresh()->status);
        $this->assertEquals($before, $money());
    }

    /**
     * An approved, paid order of the given size, with each parcel sent and still on the road.
     *
     * @param  list<int>  $quantities  units in each parcel
     * @return array{0: Order, 1: list<int>}
     */
    private function sentOrder(array $quantities, ?int $ordered = null): array
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => $ordered ?? array_sum($quantities)]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        foreach ($quantities as $quantity) {
            app(OrderService::class)->dispatch($this->owner, $order->fresh(), [
                'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
                'idempotency_key' => (string) Str::uuid(),
                'courier' => 'Test courier',
                'tracking' => 'TRK-'.Str::random(6),
                'branch_id' => $this->hub->id,
            ]);
        }

        return [$order->fresh(), DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all()];
    }

    private function partlyDelivered(): Order
    {
        [$order, $parcels] = $this->sentOrder([3, 2]);
        $this->confirmDelivered($order, $parcels[0]);
        $this->receiveBack($order, $parcels[1]);

        return $order->fresh();
    }

    private function confirmDelivered(Order $order, int $shipmentId): void
    {
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $shipmentId, 'Dealer confirmed');
    }

    private function receiveBack(Order $order, int $shipmentId): void
    {
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_status' => 'returned']);
        app(OrderService::class)->receiveReturnedParcel($this->owner, $order->fresh(), $shipmentId, 'Back at the hub');
    }
}
