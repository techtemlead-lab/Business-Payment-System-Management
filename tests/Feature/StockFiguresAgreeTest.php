<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLevels;
use App\Domain\Inventory\StockService;
use App\Domain\Inventory\StockTransfers;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The catalogue list, the Stock page tiles and the staff dashboard speak of the same stock:
 * "available now" is the sellable figure, goods with the courier are their own stage, and the
 * dashboard's low-or-out count is the Stock page's low plus out tiles.
 */
class StockFiguresAgreeTest extends TestCase
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

    public function test_dashboard_stock_stage_and_catalogue_figures_agree(): void
    {
        $full = $this->product('Full one', 50);
        $low = $this->product('Low one', 4);
        $out = $this->product('Out one', 6);
        $this->product('Never stocked', 0);
        $order = $this->approvedOrder($out, 6);
        $this->dispatch($order, 6);
        $onRoad = $this->approvedOrder($full, 5);
        $this->dispatch($onRoad, 5);

        $this->assertSame(['low' => 1, 'out' => 2], StockLevels::counts(null), 'The whole company counts a product no branch has ever held as out.');
        $levels = StockLevels::counts(new BranchScope(false, [$this->hub->id], $this->hub));
        $this->assertSame(['low' => 1, 'out' => 1], $levels);

        $response = $this->actingAs($this->owner)->onPortal('sadmin')->get('/inventory');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.stock_table.totals.sums.low_count', (string) $levels['low'])
            ->where('data.stock_table.totals.sums.out_count', (string) $levels['out'])
            ->where('data.stock_table.totals.sums.with_courier', '11.00')
            ->where('data.stock_table.totals.sums.in_stock', '49.00'));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.admin_home.work_queue', fn ($queue) => (int) collect($queue)->firstWhere('key', 'low_stock_products')['count'] === $levels['low'] + $levels['out']));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.products', fn ($products) => collect($products)->pluck('stock', 'name')->only(['Full one', 'Low one', 'Out one'])->all() === ['Full one' => 45, 'Low one' => 4, 'Out one' => 0]));
    }

    public function test_the_courier_figure_is_the_units_in_parcels_still_on_the_road(): void
    {
        $product = $this->product('Road product', 30);
        $this->dispatch($first = $this->approvedOrder($product, 7), 7);
        $this->dispatch($this->approvedOrder($product, 4), 4);
        $parcel = (int) DB::table('shipments')->where('order_id', $first->id)->value('id');
        DB::table('shipments')->where('id', $parcel)->update(['status' => 'delivered']);

        $onRoad = (int) DB::table('shipments')->where('status', 'dispatched')->get()->sum(fn ($shipment) => collect(json_decode($shipment->lines, true))->sum('quantity'));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/inventory')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.stock_table.rows.data.0.with_courier', $onRoad)
            ->where('data.stock_table.totals.sums.with_courier', number_format($onRoad, 2, '.', '')));
        $this->assertSame(4, $onRoad);
    }

    public function test_the_stock_page_lists_goods_moving_between_branches_until_they_are_received(): void
    {
        $other = CompanyBranch::query()->create(['code' => 'BR-TWO', 'name' => 'Branch Two', 'is_active' => true, 'is_stock_hub' => true, 'is_delivery_point' => true]);
        $product = $this->product('Moving product', 20);
        $transfers = app(StockTransfers::class);
        $request = $transfers->request($this->owner, ['product_id' => $product->id, 'quantity' => 8, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $other->id]);
        $this->openTransfers()->assertInertia(fn (Assert $page) => $page
            ->where('data.open_transfers.0.status', 'requested')->where('data.open_transfers.0.quantity', 8)->where('data.open_transfers.0.destination', 'Branch Two'));

        $transfers->send($this->owner, $request);
        $this->openTransfers()->assertInertia(fn (Assert $page) => $page
            ->where('data.open_transfers.0.status', 'in_transit')
            ->where('data.stock_table.rows.data.0.in_stock', 12));

        $transfers->receive($this->owner, $request->fresh());
        $this->openTransfers()->assertInertia(fn (Assert $page) => $page->where('data.open_transfers', []));
    }

    private function openTransfers(): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->get('/inventory?all_branches=1')->assertOk();
    }

    private function product(string $name, int $stock): Product
    {
        $product = Product::factory()->create(['name' => $name, 'mrp' => '200.00', 'stock' => 0, 'low_stock_at' => 5, 'active' => true]);
        if ($stock > 0) {
            app(StockService::class)->stockIn($this->owner, [
                'product_id' => $product->id, 'quantity' => $stock, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id,
                'batch_number' => 'LOT', 'expires_on' => now()->addDays(200)->toDateString(),
            ]);
        }

        return $product;
    }

    private function approvedOrder(Product $product, int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');

        return $order->fresh();
    }

    private function dispatch(Order $order, int $quantity): void
    {
        app(OrderService::class)->dispatch($this->owner, $order, [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-1', 'branch_id' => $this->hub->id,
        ]);
    }
}
