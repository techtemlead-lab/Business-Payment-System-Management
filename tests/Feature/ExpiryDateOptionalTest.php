<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotReports;
use App\Domain\Inventory\StockService;
use App\Models\CompanyBranch;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The expiry date is filled in only when the office wants it. A product or a batch with no date
 * is never "near expiry" or "expired", stays on sale, and shows a blank instead of a made-up date.
 */
class ExpiryDateOptionalTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_12_220525_make_goods_receipt_line_expiry_optional.php';

    private CompanyBranch $hub;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
    }

    public function test_a_product_is_added_without_an_expiry_date(): void
    {
        $this->actingAs($this->owner)->post('/commands/products', [
            'name' => 'Undated Tonic', 'sku' => 'TEST-NO-EXPIRY', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '200.00', 'dealer_price' => '140.00',
            'active' => true, 'expires_on' => '', 'reason' => 'Synthetic product for this test', 'video_source' => 'none',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'TEST-NO-EXPIRY', 'expires_on' => null]);
    }

    public function test_a_product_is_added_with_an_expiry_date(): void
    {
        $expiry = now()->addYear()->toDateString();

        $this->actingAs($this->owner)->post('/commands/products', [
            'name' => 'Dated Tonic', 'sku' => 'TEST-WITH-EXPIRY', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '200.00', 'dealer_price' => '140.00',
            'active' => true, 'expires_on' => $expiry, 'reason' => 'Synthetic product for this test', 'video_source' => 'none',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($expiry, Product::query()->where('sku', 'TEST-WITH-EXPIRY')->firstOrFail()->expires_on->toDateString());
    }

    public function test_a_products_expiry_date_can_be_taken_off_again(): void
    {
        $product = Product::factory()->create(['expires_on' => now()->addYear()->toDateString()]);

        $this->saveProduct($product, ['expires_on' => ''])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->expires_on);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/products/'.$product->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('product.expires_on', null));
    }

    public function test_a_product_saves_other_changes_while_it_has_no_expiry_date(): void
    {
        $product = Product::factory()->create(['name' => 'Undated Tonic', 'expires_on' => null]);

        $this->saveProduct($product, ['name' => 'Undated Tonic Plus'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Undated Tonic Plus', 'expires_on' => null]);
    }

    public function test_stock_comes_in_without_an_expiry_date(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'batch_number' => null, 'expires_on' => null]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock', [
            'product_id' => $product->id, 'branch_id' => $this->hub->id, 'kind' => BranchOperations::KindProductIn, 'quantity' => 6,
            'reason' => 'supplier_receipt', 'reference' => (string) Str::uuid(), 'batch_number' => 'NO-DATE-1', 'expires_on' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lot = StockLot::query()->where('product_id', $product->id)->sole();
        $this->assertNull($lot->expires_on);
        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertNull($product->fresh()->expires_on, 'The product must not be given a date it was never told.');
    }

    public function test_goods_are_received_on_a_purchase_order_without_an_expiry_date(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder($product, 5);

        $this->receive($order, $product, ['expires_on' => null])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(StockLot::query()->where('product_id', $product->id)->sole()->expires_on);
        $this->assertNull(GoodsReceiptLine::query()->sole()->expires_on);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/purchasing')->assertOk();
    }

    public function test_an_expiry_date_that_is_given_on_a_receipt_must_not_have_passed(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder($product, 5);

        $this->receive($order, $product, ['expires_on' => now('Asia/Dhaka')->subDay()->toDateString()])
            ->assertSessionHasErrors(['lines.0.expires_on' => __('portal.err_stock_expiry_past')]);

        $this->assertDatabaseCount('stock_lots', 0);
    }

    public function test_the_near_expiry_run_skips_stock_with_no_date(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Mixed Syrup']);
        $this->stockIn($product, 3, 'DATED-SOON', now()->addDays(10)->toDateString());
        $undated = $this->stockIn($product, 3, 'UNDATED', null);

        $this->assertSame(0, Artisan::call('stock:near-expiry-notices'));

        $bodies = DB::table('account_notices')->where('user_id', $this->owner->id)->pluck('body')->implode(' ');
        $this->assertStringContainsString('DATED-SOON', $bodies);
        $this->assertStringNotContainsString('UNDATED', $bodies);
        $this->assertNull($undated->fresh()->near_expiry_noticed_at);
        $this->assertNull($undated->fresh()->expired_noticed_at);
    }

    public function test_stock_with_no_date_is_neither_expiring_soon_nor_expired_on_the_batches_page(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $dated = $this->stockIn($product, 3, 'DATED-SOON', now()->addDays(10)->toDateString());
        $undated = $this->stockIn($product, 3, 'UNDATED', null);

        $lists = app(StockLotReports::class)->expiryLists(new BranchScope(true, []), 90);

        $this->assertSame([$dated->id], array_column($lists['near'], 'id'));
        $this->assertSame([], $lists['expired']);
        $this->assertFalse($undated->fresh()->isExpired());
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?days=90&all_branches=1')->assertOk();
    }

    public function test_stock_with_no_date_stays_on_sale_and_goes_out_after_dated_stock(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'expires_on' => null]);
        $undated = $this->stockIn($product, 4, 'UNDATED', null);
        $dated = $this->stockIn($product, 2, 'DATED', now()->addMonths(6)->toDateString());

        $pickingOrder = StockLot::query()->where('product_id', $product->id)->usable()->fefo()->pluck('id')->all();

        $this->assertSame([$dated->id, $undated->id], $pickingOrder);
        $this->assertSame(6, Product::query()->withSellableStock()->findOrFail($product->id)->sellableStock());
    }

    public function test_the_public_batch_check_says_the_date_is_not_recorded_and_shows_no_date(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Undated Syrup']);
        $this->stockIn($product, 3, 'PUB-NODATE', null);

        $this->get('/batch-check?batch=pub-nodate')->assertOk()
            ->assertSee('Undated Syrup')
            ->assertSee(__('stock_lots.public_expiry_unknown'))
            ->assertDontSee(now()->format('/Y'));
    }

    public function test_a_blank_expiry_date_alone_is_not_counted_as_missing_but_can_still_be_added_later(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $lot = $this->stockIn($product, 3, 'HAS-BATCH', null);
        $reports = app(StockLotReports::class);
        $everyBranch = new BranchScope(true, []);

        $this->assertSame(0, $reports->missingDetailsCount($everyBranch));
        $this->assertSame([$lot->id], array_column($reports->lotsMissingDetails($everyBranch), 'id'));

        $expiry = now()->addYear()->toDateString();
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/details', ['expires_on' => $expiry])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($expiry, $lot->fresh()->expires_on->toDateString());
    }

    public function test_lots_waiting_for_a_batch_number_come_before_lots_that_only_have_no_date(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $onlyUndated = $this->stockIn($product, 3, 'HAS-BATCH', null);
        $noBatch = $this->stockIn($product, 3, '', null);

        $reports = app(StockLotReports::class);

        $this->assertSame([$noBatch->id, $onlyUndated->id], array_column($reports->lotsMissingDetails(new BranchScope(true, [])), 'id'));
        $this->assertSame(1, $reports->missingDetailsCount(new BranchScope(true, [])));
    }

    public function test_every_form_label_says_the_date_can_be_left_out_in_both_languages(): void
    {
        foreach (['portal.pe_expires_on', 'portal.stock_expires_on', 'purchasing.expiry_optional'] as $key) {
            $this->assertStringContainsString('না দিলেও চলবে', __($key, [], 'bn'), $key);
            $this->assertStringContainsString('optional', __($key, [], 'en'), $key);
        }
        $this->assertStringNotContainsString('মেয়াদের তারিখ নেই', __('stock_lots.missing_details_notice', [], 'bn'));
        $this->assertStringNotContainsString('expiry', __('stock_lots.missing_details_notice', [], 'en'));
    }

    public function test_rolling_the_change_back_keeps_a_receipt_that_has_no_date(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->receive($this->sentOrder($product, 5), $product, ['expires_on' => null])->assertSessionHasNoErrors();
        $migration = require database_path('migrations/'.self::Migration);

        $migration->down();

        $this->assertNull(GoodsReceiptLine::query()->sole()->expires_on);
        $this->assertTrue(collect(Schema::getColumns('goods_receipt_lines'))->firstWhere('name', 'expires_on')['nullable'], 'The column must stay open while a receipt has no date.');
    }

    public function test_rolling_the_change_back_makes_the_date_compulsory_again_when_every_receipt_has_one(): void
    {
        $migration = require database_path('migrations/'.self::Migration);

        $migration->down();
        $this->assertFalse(collect(Schema::getColumns('goods_receipt_lines'))->firstWhere('name', 'expires_on')['nullable']);

        $migration->up();
        $this->assertTrue(collect(Schema::getColumns('goods_receipt_lines'))->firstWhere('name', 'expires_on')['nullable']);
    }

    private function stockIn(Product $product, int $quantity, string $batch, ?string $expiresOn): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id, 'quantity' => $quantity, 'reference' => (string) Str::uuid(),
            'branch_id' => $this->hub->id, 'batch_number' => $batch, 'expires_on' => $expiresOn,
        ]);
    }

    private function sentOrder(Product $product, int $quantity): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'supplier_id' => Supplier::factory()->create(['created_by' => $this->owner->id])->id,
            'branch_id' => $this->hub->id,
            'status' => PurchaseOrder::StatusSent,
            'created_by' => $this->owner->id,
        ]);
        $order->lines()->create(['product_id' => $product->id, 'quantity_ordered' => $quantity]);

        return $order;
    }

    /** @param  array<string, string|null>  $changes */
    private function receive(PurchaseOrder $order, Product $product, array $changes): TestResponse
    {
        $line = $order->lines()->where('product_id', $product->id)->firstOrFail();

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->post("/commands/purchasing/orders/{$order->id}/receipts", [
                'reference' => (string) Str::uuid(),
                'lines' => [[
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $line->quantity_ordered,
                    'batch_number' => 'RCPT-1',
                    'expires_on' => now('Asia/Dhaka')->addYear()->toDateString(),
                    'unit_cost' => '12.50',
                    ...$changes,
                ]],
            ]);
    }

    /** @param  array<string, string>  $changes */
    private function saveProduct(Product $product, array $changes): TestResponse
    {
        return $this->actingAs($this->owner)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->fresh()->registry_version,
            'reason' => 'Synthetic change for this test',
            ...$changes,
        ]);
    }
}
