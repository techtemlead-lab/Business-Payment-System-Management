<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stock goes in, out, is corrected and moves between branches without an expiry date being asked
 * for or made up, whether or not the goods in question have one.
 */
class StockWithoutExpiryTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
    }

    public function test_product_out_takes_undated_stock_without_any_expiry_field(): void
    {
        $product = $this->undatedProduct(8);

        $this->post('/commands/stock', $this->payload($product, ['kind' => BranchOperations::KindProductOut, 'quantity' => 3, 'reason' => 'damage']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertNull(StockLot::query()->where('product_id', $product->id)->sole()->expires_on);
    }

    public function test_product_out_leaves_dated_stock_first_and_undated_stock_after_it(): void
    {
        $product = $this->undatedProduct(4);
        $this->post('/commands/stock', $this->payload($product, ['kind' => BranchOperations::KindProductIn, 'quantity' => 6, 'reason' => 'supplier_receipt', 'expires_on' => now('Asia/Dhaka')->addMonths(6)->toDateString()]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->post('/commands/stock', $this->payload($product, ['kind' => BranchOperations::KindProductOut, 'quantity' => 8, 'reason' => 'damage']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) StockLot::query()->where('product_id', $product->id)->whereNull('expires_on')->sum('quantity'), 'The dated lot went first.');
        $this->assertSame(0, (int) StockLot::query()->where('product_id', $product->id)->whereNotNull('expires_on')->sum('quantity'));
    }

    public function test_a_plain_stock_change_up_or_down_needs_no_expiry_date(): void
    {
        $product = $this->undatedProduct(5);

        $this->post('/commands/stock', $this->payload($product, ['quantity' => 4, 'reason' => 'Counted four more']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/commands/stock', $this->payload($product, ['quantity' => -2, 'reason' => 'Two were broken']))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertNull($product->fresh()->expires_on);
        $this->assertSame([], StockLot::query()->where('product_id', $product->id)->whereNotNull('expires_on')->pluck('id')->all());
    }

    public function test_a_new_product_with_opening_stock_and_no_expiry_date_is_saved(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/products', [
            'name' => 'Opening Tonic', 'sku' => 'TEST-OPENING-NO-EXPIRY', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '120.00', 'dealer_price' => '90.00',
            'active' => true, 'initial_stock' => 12, 'reason' => 'Opening count for this test', 'video_source' => 'none',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'TEST-OPENING-NO-EXPIRY')->firstOrFail();
        $this->assertSame(12, (int) $product->stock);
        $this->assertNull($product->expires_on);
        $this->assertNull(StockLot::query()->where('product_id', $product->id)->sole()->expires_on);
    }

    public function test_an_undated_batch_moves_to_another_branch_and_stays_undated(): void
    {
        $product = $this->undatedProduct(6);
        $other = CompanyBranch::factory()->create(['name' => 'Khulna Hub', 'is_stock_hub' => true, 'is_delivery_point' => true]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock-transfers', [
            'product_id' => $product->id, 'quantity' => 4, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $other->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $transfer = StockTransfer::query()->firstOrFail();
        $this->post('/commands/stock-transfers/'.$transfer->id.'/send')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/commands/stock-transfers/'.$transfer->id.'/receive')->assertRedirect()->assertSessionHasNoErrors();

        $arrived = StockLot::query()->where('product_id', $product->id)->where('branch_id', $other->id)->sole();
        $this->assertSame(4, (int) $arrived->quantity);
        $this->assertNull($arrived->expires_on);
    }

    private function undatedProduct(int $quantity): Product
    {
        $product = Product::factory()->create(['stock' => 0, 'batch_number' => null, 'expires_on' => null]);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock', $this->payload($product, [
            'kind' => BranchOperations::KindProductIn, 'quantity' => $quantity, 'reason' => 'supplier_receipt', 'expires_on' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(Product $product, array $changes): array
    {
        return ['product_id' => $product->id, 'branch_id' => $this->hub->id, 'reference' => (string) Str::uuid(), ...$changes];
    }
}
