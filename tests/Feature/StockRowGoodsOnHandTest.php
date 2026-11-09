<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockLotHolds;
use App\Domain\Inventory\StockService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Stock page names the batch of the goods actually on the row.
 */
class StockRowGoodsOnHandTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private CompanyBranch $otherBranch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->otherBranch = CompanyBranch::query()->create(['code' => 'BR-TWO', 'name' => 'Branch Two', 'is_active' => true, 'is_stock_hub' => true, 'is_delivery_point' => true]);
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
    }

    public function test_a_lot_written_off_to_zero_stops_showing_on_the_row(): void
    {
        $product = $this->product();
        $lot = $this->stockIn($product, 5, 'SPOILED', 60);

        $this->assertSame('SPOILED', $this->row($product)['batch_number']);

        app(StockLotHolds::class)->hold($this->owner, $lot, 'Checking this batch');
        app(StockLotHolds::class)->writeOff($this->owner, $lot->refresh(), 'Damaged in the store');

        $row = $this->row($product);
        $this->assertSame(0, $row['branch_stock']);
        $this->assertNull($row['batch_number']);
        $this->assertNull($row['expires_on']);
    }

    public function test_when_the_first_lot_is_emptied_the_next_one_shows(): void
    {
        $product = $this->product();
        $first = $this->stockIn($product, 5, 'SOON', 30);
        $this->stockIn($product, 5, 'LATER', 200);

        app(StockLotHolds::class)->hold($this->owner, $first, 'Checking this batch');
        app(StockLotHolds::class)->writeOff($this->owner, $first->refresh(), 'Damaged in the store');

        $row = $this->row($product);
        $this->assertSame('LATER', $row['batch_number']);
        $this->assertSame(now()->addDays(200)->toDateString(), $row['expires_on']);
    }

    public function test_a_row_names_the_lot_of_its_own_branch(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, 'OTHER-SHOP', 30, $this->otherBranch);

        $row = $this->row($product);
        $this->assertSame(0, $row['branch_stock']);
        $this->assertNull($row['batch_number']);
        $this->assertNull($row['expires_on']);
    }

    public function test_a_lot_on_hold_shows_when_it_is_all_that_is_left_and_gives_way_to_a_usable_one(): void
    {
        $product = $this->product();
        $held = $this->stockIn($product, 5, 'HELD', 30);
        app(StockLotHolds::class)->hold($this->owner, $held, 'Checking this batch');

        $this->assertSame('HELD', $this->row($product)['batch_number']);

        $this->stockIn($product, 5, 'GOOD', 200);

        $this->assertSame('GOOD', $this->row($product)['batch_number']);
    }

    public function test_stock_from_before_lots_were_recorded_keeps_the_details_on_the_product(): void
    {
        $product = Product::factory()->create(['stock' => 4, 'low_stock_at' => 1, 'batch_number' => 'OLD-1', 'expires_on' => '2028-03-31']);
        BranchProductStock::query()->create(['branch_id' => $this->hub->id, 'product_id' => $product->id, 'quantity' => 4]);

        $row = $this->row($product);
        $this->assertSame('OLD-1', $row['batch_number']);
        $this->assertSame('2028-03-31', $row['expires_on']);
    }

    private function product(): Product
    {
        return Product::factory()->create(['stock' => 0, 'low_stock_at' => 1, 'batch_number' => null, 'expires_on' => null]);
    }

    private function stockIn(Product $product, int $quantity, string $batch, int $days, ?CompanyBranch $branch = null): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id, 'quantity' => $quantity, 'reference' => (string) Str::uuid(),
            'branch_id' => ($branch ?? $this->hub)->id, 'batch_number' => $batch, 'expires_on' => now()->addDays($days)->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed> The product's row on the Stock page.
     */
    private function row(Product $product): array
    {
        $row = null;
        $this->actingAs($this->owner->refresh())->onPortal('sadmin')->get('/inventory')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$row, $product): void {
                $row = collect($page->toArray()['props']['data']['products'])->firstWhere('id', $product->id);
            });

        $this->assertIsArray($row);

        return $row;
    }
}
