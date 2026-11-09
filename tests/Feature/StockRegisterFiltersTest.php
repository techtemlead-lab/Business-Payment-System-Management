<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLotHolds;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Stock and Product pages filter, sort and count on the server, across every page of results.
 */
class StockRegisterFiltersTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private CompanyBranch $otherBranch;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->otherBranch = CompanyBranch::query()->create(['code' => 'BR-TWO', 'name' => 'Branch Two', 'is_active' => true, 'is_stock_hub' => true, 'is_delivery_point' => true]);
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
    }

    public function test_stock_level_filters_split_products_by_what_can_really_go_out(): void
    {
        $empty = $this->product('Empty Syrup', low: 5);
        $low = $this->product('Low Syrup', low: 5);
        $full = $this->product('Full Syrup', low: 5);
        $this->stockIn($low, 3);
        $this->stockIn($full, 40);

        $this->assertSame(['Full Syrup', 'Low Syrup'], $this->names('level=in_stock'));
        $this->assertSame(['Empty Syrup'], $this->names('level=out_of_stock'));
        $this->assertSame(['Low Syrup'], $this->names('level=low'));
        $this->assertSame(['Full Syrup'], $this->names('level=full'));
        $this->assertSame(['Empty Syrup', 'Full Syrup', 'Low Syrup'], $this->names('level=teleport'), 'A value that is not offered is ignored.');
    }

    public function test_quantity_expiry_and_price_ranges(): void
    {
        $small = $this->product('Small Lot', mrp: '50.00');
        $large = $this->product('Large Lot', mrp: '500.00');
        $this->stockIn($small, 10, batch: 'AA-11', expires: '+20 days');
        $this->stockIn($large, 100, batch: 'BB-22', expires: '+300 days');

        $this->assertSame(['Small Lot'], $this->names('qty_max=50'));
        $this->assertSame(['Large Lot'], $this->names('qty_min=50&qty_max=150'));
        $this->assertSame(['Large Lot', 'Small Lot'], $this->names('qty_min=abc'), 'Text in a number box is ignored.');
        $this->assertSame(['Small Lot'], $this->names('expiry_from='.now()->toDateString().'&expiry_to='.now()->addDays(40)->toDateString()));
        $this->assertSame(['Large Lot'], $this->names('expiry_from='.now()->addDays(100)->toDateString()));
        $this->assertSame(['Small Lot'], $this->names('max_mrp=100'));
        $this->assertSame(['Large Lot'], $this->names('min_mrp=100'));
        $this->assertSame(['Large Lot'], $this->names('batch=bb-2'));
    }

    public function test_expiry_filter_looks_at_every_batch_on_hand_and_shows_the_one_that_matched(): void
    {
        $product = $this->product('Two Batches');
        $this->stockIn($product, 5, batch: 'NEAR', expires: '+10 days');
        $this->stockIn($product, 5, batch: 'FAR', expires: '+300 days');

        $table = $this->table('expiry_from='.now()->addDays(200)->toDateString());

        $this->assertSame(1, $table['totals']['count']);
        $this->assertSame(now()->addDays(300)->toDateString(), $table['rows']['data'][0]['expires_on'], 'The row names the batch that matched, not the one that goes out next.');
    }

    public function test_expiry_states_and_missing_details(): void
    {
        $soon = $this->product('Soon Lot');
        $later = $this->product('Later Lot');
        $bare = $this->product('Bare Lot');
        $none = $this->product('No Stock');
        $gone = $this->product('Gone Lot');
        $this->stockIn($soon, 5, batch: 'S1', expires: '+25 days');
        $this->stockIn($later, 5, batch: 'L1', expires: '+300 days');
        $this->stockIn($bare, 5, batch: null, expires: null);
        $this->stockIn($gone, 4, batch: 'G1', expires: '+300 days');
        DB::table('stock_lots')->where('batch_number', 'G1')->update(['expires_on' => now()->subDays(3)->toDateString()]);

        $this->assertSame(['Gone Lot'], $this->names('expiry=expired'));
        $this->assertSame(['Soon Lot'], $this->names('expiry=soon_30'));
        $this->assertSame(['Bare Lot'], $this->names('expiry=no_date'));
        $this->assertSame(['Bare Lot'], $this->names('missing=no_batch'));
        $this->assertSame(['Bare Lot'], $this->names('missing=no_expiry'));
        $this->assertNotContains('No Stock', $this->names('missing=either'), 'Nothing on hand means nothing is missing.');
    }

    public function test_stage_filters_find_goods_with_the_courier_on_hold_and_booked(): void
    {
        $sent = $this->product('Sent Product');
        $held = $this->product('Held Product');
        $booked = $this->product('Booked Product');
        $this->stockIn($sent, 10);
        $lot = $this->stockIn($held, 10);
        $this->stockIn($booked, 2);
        $this->dispatch($this->approvedOrder($sent, 4), 4);
        app(StockLotHolds::class)->hold($this->owner, $lot, 'Checking this batch');
        $this->approvedOrder($booked, 2);
        DB::table('order_lines')->where('product_id', $booked->id)->update(['quantity' => 6]);

        $this->assertSame(['Sent Product'], $this->names('stage=with_courier'));
        $this->assertSame(['Held Product'], $this->names('stage=on_hold'));
        $this->assertSame(['Booked Product'], $this->names('stage=booked'));
        $this->assertSame(['Booked Product'], $this->names('stage=short'));
    }

    public function test_free_to_sell_and_short_compare_bookings_with_the_whole_company(): void
    {
        $product = $this->product('Spread Out');
        $this->stockIn($product, 5, branch: $this->hub);
        $this->stockIn($product, 100, branch: $this->otherBranch);
        $this->approvedOrder($product, 8);

        $row = $this->table('')['rows']['data'][0];

        $this->assertSame(5, $row['in_stock']);
        $this->assertSame(97, $row['free_to_sell'], 'An order can be sent from any branch, so bookings are measured against all of them.');
        $this->assertSame([], $this->names('stage=short'));
    }

    public function test_product_details_filters(): void
    {
        $old = $this->product('Hidden Oil', ['active' => false, 'category' => 'Oil', 'sub_category' => 'Hair', 'unit' => 'Bottle']);
        $this->product('Shown Balm', ['featured' => true, 'category' => 'Balm', 'sub_category' => 'Skin', 'unit' => 'Jar']);
        $this->product('Paused Tea', ['is_stock_paused' => true, 'category' => 'Tea']);

        $this->assertSame(['Hidden Oil'], $this->names('product_state=archived'));
        $this->assertSame(['Paused Tea', 'Shown Balm'], $this->names('product_state=active'));
        $this->assertSame(['Shown Balm'], $this->names('featured=yes'));
        $this->assertSame(['Paused Tea'], $this->names('paused=yes'));
        $this->assertSame(['Hidden Oil'], $this->names('category=Oil'));
        $this->assertSame(['Shown Balm'], $this->names('sub_category=Skin'));
        $this->assertSame(['Hidden Oil'], $this->names('unit=Bottle'));
        $this->assertSame([$old->name], $this->names('q=Hidden'));
    }

    public function test_results_and_totals_cover_every_page_and_sorting_uses_the_whole_list(): void
    {
        foreach (range(1, 30) as $index) {
            $product = $this->product('Item '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), mrp: '10.00');
            $this->stockIn($product, $index);
        }

        $table = $this->table('level=in_stock');
        $this->assertSame(30, $table['totals']['count']);
        $this->assertCount(25, $table['rows']['data']);
        $this->assertEqualsWithDelta(465, (float) $table['totals']['sums']['in_stock'], 0.001);
        $this->assertEqualsWithDelta(4650, (float) $table['totals']['sums']['stock_value'], 0.001);
        $this->assertCount(5, $this->table('level=in_stock&page=2')['rows']['data']);

        $sorted = $this->table('sort=in_stock&dir=desc');
        $this->assertSame('Item 30', $sorted['rows']['data'][0]['name']);
        $this->assertSame('in_stock', $sorted['state']['sort']);
        $this->assertSame('Item 01', $this->table('sort=in_stock&dir=asc')['rows']['data'][0]['name']);
        $this->assertSame('Item 01', $this->table('sort=teleport')['rows']['data'][0]['name'], 'An unknown sort falls back to the default.');
    }

    public function test_view_choice_finds_products_whose_stock_moved_in_that_period(): void
    {
        $this->stockIn($this->product('Moved Today'), 5);
        $old = $this->product('Moved Long Ago');
        $this->stockIn($old, 5);
        DB::table('stock_movements')->where('product_id', $old->id)->update(['created_at' => now()->subYears(2)]);

        $this->assertSame(['Moved Today'], $this->names('period=today'));
        $this->assertSame(['Moved Long Ago', 'Moved Today'], $this->names(''));
    }

    public function test_the_filter_bar_lists_its_choices_and_reports_what_is_applied(): void
    {
        $this->product('Oil', ['category' => 'Oil']);

        $table = $this->table('level=low&qty_min=3&category=Oil');
        $keys = array_column($table['filter_bar']['fields'], 'key');

        foreach (['level', 'category', 'stage', 'sub_category', 'unit', 'qty', 'expiry_range', 'expiry', 'batch', 'product_state', 'featured', 'paused', 'mrp', 'missing', 'idle'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertSame(['level' => 'low', 'category' => 'Oil', 'qty_min' => '3'], $table['filter_bar']['active']);
        $this->assertSame(['level' => 'low', 'category' => 'Oil'], $table['state']['filters'], 'The shared table shows the main filters as chosen.');
        $this->assertContains('level', array_column($table['filters'], 'key'));
        $this->assertNotContains('sub_category', array_column($table['filters'], 'key'), 'The rest stay in the More filters sheet.');
    }

    public function test_all_branches_view_lists_each_branch_and_can_narrow_to_one(): void
    {
        $product = $this->product('Everywhere');
        $this->stockIn($product, 6, branch: $this->hub);
        $this->stockIn($product, 2, branch: $this->otherBranch);

        $table = $this->table('', allBranches: true);
        $this->assertSame(2, $table['totals']['count']);
        $this->assertEqualsCanonicalizing([6, 2], array_column($table['rows']['data'], 'in_stock'));

        $narrow = $this->table('branch_id='.$this->otherBranch->id, allBranches: true);
        $this->assertSame(1, $narrow['totals']['count']);
        $this->assertSame(2, $narrow['rows']['data'][0]['in_stock']);
        $this->assertContains('branch_id', array_column($table['filter_bar']['fields'], 'key'));
    }

    public function test_a_branch_view_counts_only_that_branch(): void
    {
        $product = $this->product('Everywhere');
        $this->stockIn($product, 6, branch: $this->hub);
        $this->stockIn($product, 2, branch: $this->otherBranch);

        $row = $this->table('')['rows']['data'][0];

        $this->assertSame(6, $row['in_stock']);
        $this->assertNotContains('branch_id', array_column($this->table('')['filter_bar']['fields'], 'key'));
    }

    public function test_staff_without_cost_rights_never_get_cost_figures(): void
    {
        $product = $this->product('Costly', ['cost_price' => '30.00']);
        $this->stockIn($product, 2);
        $this->assertArrayHasKey('cost_price', $this->table('')['rows']['data'][0]);

        $inventory = User::factory()->staff('inventory')->create(['active_branch_id' => $this->hub->id]);
        $row = $this->actingAs($inventory)->onPortal('manage')->get('/inventory')->assertOk()->original->getData()['page']['props']['data']['stock_table']['rows']['data'][0];

        $this->assertArrayNotHasKey('cost_price', $row);
    }

    public function test_the_catalog_page_shares_the_filters_and_pages_its_results(): void
    {
        foreach (range(1, 30) as $index) {
            $this->product('Bulk '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), ['category' => 'Bulk']);
        }
        $low = $this->product('Low One', low: 5);
        $this->stockIn($low, 2);

        $response = $this->actingAs($this->owner)->onPortal('sadmin')->get('/catalog?level=low')->assertOk()->original->getData()['page']['props']['data'];
        $this->assertSame(['Low One'], array_column($response['products'], 'name'));
        $this->assertSame('low', $response['catalog_filters']['active']['level']);

        $page = $this->actingAs($this->owner)->onPortal('sadmin')->get('/catalog?category=Bulk')->assertOk()->original->getData()['page']['props']['data'];
        $this->assertCount(24, $page['products']);
        $this->assertSame(30, $page['products_page']['total']);
        $second = $this->actingAs($this->owner)->onPortal('sadmin')->get('/catalog?category=Bulk&page=2')->assertOk()->original->getData()['page']['props']['data'];
        $this->assertCount(6, $second['products']);
    }

    public function test_staff_without_stock_rights_get_no_stock_filters_on_the_catalog(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $data = $this->actingAs($editor)->onPortal('manage')->get('/catalog')->assertOk()->original->getData()['page']['props']['data'];

        $keys = array_column($data['catalog_filters']['fields'], 'key');
        $this->assertNotContains('qty', $keys);
        $this->assertNotContains('stage', $keys);
        $this->assertContains('category', $keys);
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return array_column($this->table($query)['rows']['data'], 'name');
    }

    /** @return array<string, mixed> */
    private function table(string $query, bool $allBranches = false): array
    {
        $response = $this->actingAs($this->owner->refresh())->onPortal('sadmin')
            ->withSession($allBranches ? [BranchOperations::SessionView => 'all'] : [])
            ->get('/inventory'.($query !== '' ? '?'.$query : ''))
            ->assertOk();

        return $response->original->getData()['page']['props']['data']['stock_table'];
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(string $name, array $attributes = [], string $mrp = '200.00', int $low = 0): Product
    {
        return Product::factory()->create(['name' => $name, 'strength' => '', 'mrp' => $mrp, 'stock' => 0, 'active' => true, 'low_stock_at' => $low, 'expires_on' => null, 'batch_number' => null, ...$attributes]);
    }

    private function stockIn(Product $product, int $quantity, ?string $batch = 'LOT-A', ?string $expires = '+200 days', ?CompanyBranch $branch = null): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reference' => (string) Str::uuid(),
            'branch_id' => ($branch ?? $this->hub)->id,
            'batch_number' => $batch,
            'expires_on' => $expires !== null ? now()->modify($expires)->toDateString() : null,
        ]);
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
            'idempotency_key' => (string) Str::uuid(),
            'courier' => 'Test courier',
            'tracking' => 'TRK-1',
            'branch_id' => $this->hub->id,
        ]);
    }
}
