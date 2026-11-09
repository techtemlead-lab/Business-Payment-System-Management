<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockLotHolds;
use App\Domain\Inventory\StockService;
use App\Domain\Inventory\StockStages;
use App\Domain\Orders\OrderService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Goods that left a branch and are neither delivered nor back are in their own stage.
 */
class StockCourierStageTest extends TestCase
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

    public function test_goods_sent_with_a_courier_leave_stock_and_sit_in_the_courier_stage(): void
    {
        $product = $this->product();
        $this->stockIn($product, 10, $this->hub);
        $order = $this->approvedOrder($product, 4);

        $this->dispatch($order, 3);

        $row = $this->branchRow($product, $this->hub);
        $this->assertSame(7, $row['in_stock']);
        $this->assertSame(3, $row['with_courier']);
        $this->assertSame(10, $row['total']);
        $this->assertSame(7, (int) BranchProductStock::query()->where('branch_id', $this->hub->id)->where('product_id', $product->id)->value('quantity'), 'The stock count itself is unchanged by the new stage.');
    }

    public function test_delivered_goods_leave_the_courier_stage_for_good(): void
    {
        $this->enableFinancialPosting();
        $product = $this->product();
        $this->stockIn($product, 10, $this->hub);
        $order = $this->approvedOrder($product, 4);
        $this->dispatch($order, 4);

        app(OrderService::class)->deliver($this->owner, $order, $this->parcelId($order), 'Signed for');

        $row = $this->branchRow($product, $this->hub);
        $this->assertSame(0, $row['with_courier']);
        $this->assertSame(6, $row['in_stock']);
    }

    public function test_a_parcel_that_comes_back_returns_to_stock_once_it_is_received(): void
    {
        $product = $this->product();
        $this->stockIn($product, 10, $this->hub);
        $order = $this->approvedOrder($product, 4);
        $this->dispatch($order, 4);
        $parcel = $this->parcelId($order);

        DB::table('shipments')->where('id', $parcel)->update(['courier_status' => 'returned']);
        $this->assertSame(4, $this->branchRow($product, $this->hub)['with_courier'], 'A parcel on its way back is still not on the shelf.');

        app(OrderService::class)->receiveReturnedParcel($this->owner, $order, $parcel, 'Back at the hub');

        $row = $this->branchRow($product, $this->hub);
        $this->assertSame(0, $row['with_courier']);
        $this->assertSame(10, $row['in_stock']);
    }

    public function test_every_unit_is_in_exactly_one_stage_on_each_branch_and_on_the_total(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, $this->hub, 'SOON', '+30 days');
        $this->stockIn($product, 5, $this->hub, 'LATER', '+200 days');
        $lot = StockLot::query()->where('batch_number', 'LATER')->firstOrFail();
        $this->stockIn($product, 4, $this->otherBranch);
        DB::table('stock_lots')->insert([
            'product_id' => $product->id, 'branch_id' => $this->otherBranch->id, 'batch_number' => 'OLD', 'expires_on' => now()->subDays(5)->toDateString(),
            'received_on' => now()->subYear()->toDateString(), 'quantity' => 2, 'received_quantity' => 2, 'source' => 'receipt', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_product_stocks')->where('branch_id', $this->otherBranch->id)->where('product_id', $product->id)->increment('quantity', 2);
        app(StockLotHolds::class)->hold($this->owner, $lot, 'Checking this batch');
        $order = $this->approvedOrder($product, 3);
        $this->dispatch($order, 3);

        $result = app(StockStages::class)->forProduct($product);
        $hub = $this->branchRow($product, $this->hub);
        $other = $this->branchRow($product, $this->otherBranch);

        $this->assertSame(['in_stock' => 2, 'on_hold' => 5, 'expired' => 0, 'with_courier' => 3, 'total' => 10], $this->stages($hub));
        $this->assertSame(['in_stock' => 4, 'on_hold' => 0, 'expired' => 2, 'with_courier' => 0, 'total' => 6], $this->stages($other));
        $this->assertSame(['in_stock' => 6, 'on_hold' => 5, 'expired' => 2, 'with_courier' => 3, 'total' => 16], $this->stages($result['total']));
        foreach ([$hub, $other, $result['total']] as $row) {
            $this->assertSame($row['total'], $row['in_stock'] + $row['on_hold'] + $row['expired'] + $row['with_courier']);
        }
    }

    public function test_the_total_counts_a_product_with_only_the_old_company_wide_figure(): void
    {
        $product = $this->product(['stock' => 25]);

        $result = app(StockStages::class)->forProduct($product);

        $this->assertSame(['in_stock' => 25, 'on_hold' => 0, 'expired' => 0, 'with_courier' => 0, 'total' => 25], $this->stages($result['total']));
    }

    public function test_a_parcel_is_counted_at_the_branch_that_sent_it(): void
    {
        $product = $this->product();
        $this->stockIn($product, 6, $this->hub);
        $this->stockIn($product, 6, $this->otherBranch);
        $order = $this->approvedOrder($product, 2);

        $this->dispatch($order, 2, $this->otherBranch);

        $this->assertSame(0, $this->branchRow($product, $this->hub)['with_courier']);
        $this->assertSame(2, $this->branchRow($product, $this->otherBranch)['with_courier']);
    }

    public function test_the_stock_page_and_product_page_show_the_stage(): void
    {
        $product = $this->product();
        $this->stockIn($product, 10, $this->hub);
        $this->dispatch($this->approvedOrder($product, 4), 4);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/inventory')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.stock_table.rows.data.0.with_courier', 4)
                ->where('data.stock_table.rows.data.0.in_stock', 6)
                ->where('data.stock_table.totals.sums.with_courier', '4.00'));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/products/'.$product->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stock_stages.total.with_courier', 4)
                ->where('stock_stages.total.total', 10)
                ->where('stock_stages.branches.0.branch_name', $this->hub->name));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/catalog')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.products.0.with_courier', 4));
    }

    public function test_a_person_without_stock_rights_does_not_get_the_stages_on_the_product_page(): void
    {
        $product = $this->product();
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->onPortal('manage')->get('/products/'.$product->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stock_stages', null));
    }

    /**
     * @param  array<string, int|string>  $row
     * @return array<string, int>
     */
    private function stages(array $row): array
    {
        return array_intersect_key($row, array_flip(['in_stock', 'on_hold', 'expired', 'with_courier', 'total']));
    }

    /** @return array<string, mixed> */
    private function branchRow(Product $product, CompanyBranch $branch): array
    {
        return collect(app(StockStages::class)->forProduct($product)['branches'])->firstWhere('branch_id', $branch->id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['mrp' => '200.00', 'stock' => 0, 'active' => true, ...$attributes]);
    }

    private function stockIn(Product $product, int $quantity, CompanyBranch $branch, ?string $batch = 'LOT-A', ?string $expires = '+200 days'): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reference' => (string) Str::uuid(),
            'branch_id' => $branch->id,
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

    private function dispatch(Order $order, int $quantity, ?CompanyBranch $branch = null): void
    {
        app(OrderService::class)->dispatch($this->owner, $order, [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
            'courier' => 'Test courier',
            'tracking' => 'TRK-1',
            'branch_id' => ($branch ?? $this->hub)->id,
        ]);
    }

    private function parcelId(Order $order): int
    {
        return (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
    }
}
