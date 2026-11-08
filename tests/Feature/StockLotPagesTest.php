<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotReports;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockLotPagesTest extends TestCase
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
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000099']);
    }

    public function test_near_expiry_page_lists_lots_inside_the_window_and_expired_lots_apart(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Lot Syrup']);
        $this->stockIn($product, 3, 'IN-25', 25);
        $this->stockIn($product, 3, 'IN-80', 80);
        $this->stockIn($product, 3, 'OUT-200', 200);
        $this->stockIn($product, 3, 'GONE', 30)->forceFill(['expires_on' => now()->subDays(3)->toDateString()])->save();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?days=30&all_branches=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.stock_lots.tab', 'near')
                ->where('data.stock_lots.days', 30)
                ->count('data.stock_lots.expiry.near', 1)
                ->where('data.stock_lots.expiry.near.0.batch_number', 'IN-25')
                ->count('data.stock_lots.expiry.expired', 1)
                ->where('data.stock_lots.expiry.expired.0.batch_number', 'GONE'));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?days=90&all_branches=1')
            ->assertInertia(fn (Assert $page) => $page->count('data.stock_lots.expiry.near', 2));
    }

    public function test_the_warning_threshold_is_saved_audited_and_limited_to_the_three_choices(): void
    {
        $this->assertSame(StockLotReports::DefaultNearExpiryDays, app(StockLotReports::class)->nearExpiryDays());

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock-lots/near-expiry-days', ['days' => 60])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(60, app(StockLotReports::class)->nearExpiryDays());
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.near_expiry_days_set']);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock-lots/near-expiry-days', ['days' => 45])
            ->assertSessionHasErrors('days');
    }

    public function test_inventory_staff_get_one_portal_notice_per_lot_and_partners_get_none(): void
    {
        $inventory = User::factory()->staff('inventory')->create();
        $product = Product::factory()->create(['stock' => 0]);
        $this->stockIn($product, 3, 'SOON-A', 10);
        $this->stockIn($product, 3, 'GONE-B', 10)->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();

        Artisan::call('stock:near-expiry-notices');
        Artisan::call('stock:near-expiry-notices');

        $notices = DB::table('account_notices')->where('user_id', $inventory->id)
            ->whereIn('title', [__('stock_lots.notice_near_expiry_title'), __('stock_lots.notice_expired_title')])->get();
        $this->assertCount(2, $notices);
        $this->assertStringContainsString('SOON-A', (string) $notices->firstWhere('title', __('stock_lots.notice_near_expiry_title'))->body);
        $this->assertStringContainsString('GONE-B', (string) $notices->firstWhere('title', __('stock_lots.notice_expired_title'))->body);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $this->dealer->id, 'title' => __('stock_lots.notice_near_expiry_title')]);
    }

    public function test_recall_lists_orders_and_partners_for_a_batch_and_follows_the_branch(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->stockIn($product, 10, 'RECALL-1', 100);
        $order = $this->dispatchedOrder($product, 4);

        $recall = app(StockLotReports::class)->recall(new BranchScope(true, []), ' recall-1 ');

        $this->assertSame($order->number, $recall['orders'][0]['order_number']);
        $this->assertSame($this->dealer->dealer_code, $recall['orders'][0]['dealer_code']);
        $this->assertSame(4, $recall['orders'][0]['sent']);
        $this->assertArrayNotHasKey('phone', $recall['orders'][0]);
        $this->assertSame(6, $recall['in_stock'][0]['quantity']);

        $elsewhere = app(StockLotReports::class)->recall(new BranchScope(false, [$this->otherBranch->id], $this->otherBranch), 'RECALL-1');
        $this->assertSame([], $elsewhere['orders']);
        $this->assertSame([], $elsewhere['in_stock']);
        $this->assertSame([], app(StockLotReports::class)->recall(new BranchScope(true, []), 'NOPE')['orders']);
    }

    public function test_recall_page_works_for_inventory_staff(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->stockIn($product, 10, 'PAGE-1', 100);
        $order = $this->dispatchedOrder($product, 1);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?tab=recall&batch=PAGE-1&all_branches=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.stock_lots.recall.orders.0.order_number', $order->number));
    }

    public function test_staff_without_inventory_rights_cannot_open_the_page_or_move_stock(): void
    {
        $finance = User::factory()->staff('accountant')->create();
        $this->actingAs($finance)->onPortal('manage')->get('/stock-lots')->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/stock-transfers', [])->assertForbidden();
    }

    public function test_branch_staff_see_only_their_own_branch_transfers(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $third = CompanyBranch::query()->create(['code' => 'BR-THREE', 'name' => 'Branch Three', 'is_active' => true, 'is_stock_hub' => true, 'is_delivery_point' => true]);
        $mine = StockTransfer::query()->create(['number' => 'TR-MINE', 'product_id' => $product->id, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $third->id, 'quantity' => 1]);
        StockTransfer::query()->create(['number' => 'TR-OTHER', 'product_id' => $product->id, 'source_branch_id' => $third->id, 'destination_branch_id' => $this->otherBranch->id, 'quantity' => 1]);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?tab=transfers&branch_id='.$this->hub->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->count('data.stock_lots.transfers', 1)->where('data.stock_lots.transfers.0.id', $mine->id));
    }

    public function test_transfers_run_through_the_commands(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->stockIn($product, 5, 'CMD', 100);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock-transfers', [
            'product_id' => $product->id, 'quantity' => 2, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $transfer = StockTransfer::query()->firstOrFail();
        $this->post('/commands/stock-transfers/'.$transfer->id.'/send')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(StockTransfer::StatusInTransit, $transfer->refresh()->status);
        $this->post('/commands/stock-transfers/'.$transfer->id.'/receive')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(StockTransfer::StatusReceived, $transfer->refresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_reorder_list_suggests_from_average_sales(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'low_stock_at' => 10]);
        $healthy = Product::factory()->create(['stock' => 0, 'low_stock_at' => 2]);
        $this->stockIn($product, 8, 'R1', 200);
        $this->stockIn($healthy, 50, 'R2', 200);
        DB::table('branch_product_stocks')->where('product_id', $product->id)->update(['low_stock_at' => 10]);
        DB::table('branch_product_stocks')->where('product_id', $healthy->id)->update(['low_stock_at' => 2]);
        DB::table('stock_movements')->insert(['product_id' => $product->id, 'branch_id' => $this->hub->id, 'quantity' => -90, 'kind' => 'dispatch', 'reference' => (string) Str::uuid(), 'reason' => 'ORD', 'actor_id' => $this->owner->id, 'created_at' => now()->subDays(5), 'updated_at' => now()]);
        DB::table('stock_movements')->insert(['product_id' => $product->id, 'branch_id' => $this->hub->id, 'quantity' => -900, 'kind' => 'dispatch', 'reference' => (string) Str::uuid(), 'reason' => 'OLD', 'actor_id' => $this->owner->id, 'created_at' => now()->subDays(200), 'updated_at' => now()]);
        StockTransfer::query()->create(['number' => 'TR-WAY', 'product_id' => $product->id, 'source_branch_id' => $this->otherBranch->id, 'destination_branch_id' => $this->hub->id, 'quantity' => 2, 'status' => StockTransfer::StatusInTransit]);

        $rows = app(StockLotReports::class)->reorderSuggestions(new BranchScope(true, []));

        $this->assertCount(1, $rows);
        $this->assertSame($product->id, (int) $rows[0]['product_id']);
        $this->assertSame('1.00', $rows[0]['average_daily']);
        $this->assertSame(2, $rows[0]['incoming']);
        // One a day for 30 days, plus the minimum of 10, less 8 in stock and 2 on the way.
        $this->assertSame(30, $rows[0]['suggested']);
    }

    public function test_public_batch_check_leaves_out_the_maker_until_one_is_set(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Plain Syrup']);
        $this->stockIn($product, 3, 'PUB-78', 100);

        $this->get('/batch-check?batch=pub-78')->assertOk()
            ->assertSee('Plain Syrup')
            ->assertDontSee(__('stock_lots.public_maker'));
    }

    public function test_public_batch_check_shows_only_product_expiry_and_maker(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Public Syrup', 'cost_price' => '77.00']);
        $this->stockIn($product, 3, 'PUB-77', 100);
        SiteCustomization::save(['invoice_manufacturer' => 'Test Maker Ltd']);

        $response = $this->get('/batch-check?batch=pub-77')->assertOk();

        $response->assertSee('Public Syrup');
        $response->assertSee(now()->addDays(100)->format('d/m/Y'));
        $response->assertSee('Test Maker Ltd');
        $response->assertDontSee('HO-PIRGANJ');
        $response->assertDontSee('77.00');

        $this->assertSame(['product', 'expires_on', 'maker'], array_keys(app(StockLotReports::class)->publicCheck('PUB-77')[0]));
    }

    public function test_public_batch_check_needs_the_exact_number(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Hidden Syrup']);
        $this->stockIn($product, 3, 'EXACT-1', 100);

        $this->get('/batch-check?batch=EXACT')->assertOk()->assertDontSee('Hidden Syrup')->assertSee(__('stock_lots.public_not_found'));
        $this->get('/batch-check?batch=%25')->assertOk()->assertDontSee('Hidden Syrup');
        $this->get('/batch-check')->assertOk()->assertDontSee(__('stock_lots.public_not_found'));
    }

    private function stockIn(Product $product, int $quantity, string $batch, int $days): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id, 'quantity' => $quantity, 'reference' => (string) Str::uuid(),
            'branch_id' => $this->hub->id, 'batch_number' => $batch, 'expires_on' => now()->addDays($days)->toDateString(),
        ]);
    }

    private function dispatchedOrder(Product $product, int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        app(OrderService::class)->dispatch($this->owner, $order->fresh(), [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test', 'tracking' => 'TRK', 'branch_id' => $this->hub->id,
        ]);

        return $order->fresh();
    }
}
