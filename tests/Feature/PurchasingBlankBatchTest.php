<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotReports;
use App\Models\CompanyBranch;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Goods can be received without a batch number. The batch then waits on the "Missing
 * details" list until someone adds it.
 */
class PurchasingBlankBatchTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $branch;

    private User $storekeeper;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->branch = CompanyBranch::factory()->create();
        $this->storekeeper = User::factory()->staff('inventory')->create();
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    public function test_a_blank_batch_is_accepted_and_the_stock_goes_in(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder($product, 4);

        $this->receive($order, $product, ['batch_number' => ''])->assertRedirect()->assertSessionHasNoErrors();

        $lot = StockLot::query()->where('product_id', $product->id)->sole();
        $this->assertNull($lot->batch_number);
        $this->assertSame(4, (int) $lot->quantity);
        $this->assertNull(GoodsReceiptLine::query()->sole()->batch_number);
        $this->assertSame(4, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase_order.received']);
    }

    public function test_a_blank_batch_is_flagged_on_the_missing_details_list(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->receive($this->sentOrder($product, 4), $product, ['batch_number' => null])->assertSessionHasNoErrors();

        $this->assertSame(1, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
        $lot = StockLot::query()->where('product_id', $product->id)->sole();
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?tab=details&all_branches=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.stock_lots.tab', 'details')
                ->count('data.stock_lots.missing_details', 1)
                ->where('data.stock_lots.missing_details.0.id', $lot->id));
    }

    public function test_a_receipt_with_a_batch_number_is_not_flagged(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->receive($this->sentOrder($product, 4), $product, ['batch_number' => 'B-200'])->assertSessionHasNoErrors();

        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
        $this->assertSame('B-200', GoodsReceiptLine::query()->sole()->batch_number);
    }

    public function test_adding_the_batch_later_clears_the_flag_and_updates_the_receipt(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $this->receive($this->sentOrder($product, 4), $product, ['batch_number' => ''])->assertSessionHasNoErrors();
        $lot = StockLot::query()->where('product_id', $product->id)->sole();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/details', ['batch_number' => 'LATER-1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('LATER-1', $lot->fresh()->batch_number);
        $this->assertSame('LATER-1', GoodsReceiptLine::query()->sole()->batch_number);
        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
    }

    public function test_the_cost_is_still_required(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder($product, 4);

        $this->receive($order, $product, ['unit_cost' => ''])->assertSessionHasErrors('lines.0.unit_cost');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_blank_expiry_date_is_accepted_and_the_stock_goes_in(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder($product, 4);

        $this->receive($order, $product, ['expires_on' => ''])->assertRedirect()->assertSessionHasNoErrors();

        $lot = StockLot::query()->where('product_id', $product->id)->sole();
        $this->assertNull($lot->expires_on);
        $this->assertSame('B-100', $lot->batch_number);
        $this->assertNull(GoodsReceiptLine::query()->sole()->expires_on);
        $this->assertSame(4, (int) $product->fresh()->stock);
        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()), 'A blank expiry date is not something left to do.');
    }

    public function test_the_form_marks_the_expiry_date_as_optional_in_both_languages(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/PurchasingHub.tsx'));

        $this->assertStringContainsString("{text('expiry_optional')}<DateInput value={row.expires_on}", $source);
        $this->assertSame('Expiry date (optional)', __('purchasing.expiry_optional', [], 'en'));
        $this->assertSame('মেয়াদ শেষের তারিখ (না দিলেও চলবে)', __('purchasing.expiry_optional', [], 'bn'));
    }

    public function test_the_form_marks_the_batch_as_optional_in_both_languages(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/PurchasingHub.tsx'));

        $this->assertStringContainsString("{text('batch_optional')}<input maxLength={60} value={row.batch_number}", $source);
        foreach (['batch_optional', 'batch_blank_hint'] as $key) {
            $this->assertNotSame('purchasing.'.$key, __('purchasing.'.$key, [], 'en'));
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', __('purchasing.'.$key, [], 'bn'));
        }
    }

    private function sentOrder(Product $product, int $quantity): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'supplier_id' => Supplier::factory()->create(['created_by' => $this->storekeeper->id])->id,
            'branch_id' => $this->branch->id,
            'status' => PurchaseOrder::StatusSent,
            'created_by' => $this->storekeeper->id,
        ]);
        $order->lines()->create(['product_id' => $product->id, 'quantity_ordered' => $quantity]);

        return $order;
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    private function receive(PurchaseOrder $order, Product $product, array $changes): TestResponse
    {
        $line = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('product_id', $product->id)->firstOrFail();

        return $this->actingAs($this->storekeeper)->onPortal('manage')
            ->post("/commands/purchasing/orders/{$order->id}/receipts", [
                'reference' => (string) Str::uuid(),
                'lines' => [[
                    'purchase_order_line_id' => $line->id,
                    'quantity' => 4,
                    'batch_number' => 'B-100',
                    'expires_on' => now('Asia/Dhaka')->addYear()->toDateString(),
                    'unit_cost' => '12.50',
                    ...$changes,
                ]],
            ]);
    }

    private function allBranches(): BranchScope
    {
        return new BranchScope(true, []);
    }
}
