<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLotHolds;
use App\Domain\Inventory\StockService;
use App\Domain\Inventory\StockTransfers;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\StaffDashboardSummary;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Partners are told a product is back, or gone, by what can really be sold: units on hold, past
 * their date or at a branch that does not send orders do not count. The staff low-stock list
 * counts the same way, because it exists to warn the staff before dealers run out.
 */
class StockNoticesFollowSellableStockTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $headOffice;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'en']);
    }

    public function test_partners_are_told_when_sellable_units_arrive_beside_units_on_hold(): void
    {
        $product = $this->product();
        $this->lot($product, 8, $this->onHold());

        $this->receive($product->refresh(), 5);

        $this->assertSame(['Product available'], $this->partnerNotices());
    }

    public function test_partners_are_not_told_a_product_is_back_when_returned_units_go_into_a_held_batch(): void
    {
        $product = $this->product();
        $emptyHeldLot = $this->lot($product, 0, ['received_quantity' => 5, ...$this->onHold()]);

        app(StockService::class)->adjust($this->owner, ['product_id' => $product->id, 'branch_id' => $this->headOffice->id, 'quantity' => 5, 'reason' => 'Parcel came back', 'reference' => (string) Str::uuid(),
            'return_lots' => [['lot_id' => $emptyHeldLot->id, 'quantity' => 5]]]);

        $this->assertSame(5, (int) $product->refresh()->stock);
        $this->assertSame(0, $product->sellableStock());
        $this->assertSame([], $this->partnerNotices());
    }

    public function test_partners_are_told_a_product_is_gone_when_only_units_on_hold_are_left(): void
    {
        $product = $this->product();
        $this->lot($product, 5);
        $this->lot($product, 8, $this->onHold());

        app(StockService::class)->adjust($this->owner, ['product_id' => $product->id, 'branch_id' => $this->headOffice->id, 'quantity' => -5, 'reason' => 'Counted short', 'reference' => (string) Str::uuid()]);

        $this->assertSame(8, (int) $product->refresh()->stock);
        $this->assertSame(['Product unavailable'], $this->partnerNotices());
    }

    public function test_sending_the_last_sellable_units_tells_partners_the_product_is_gone(): void
    {
        $product = $this->product(['dealer_price' => '100.00']);
        $this->lot($product, 3);
        $this->lot($product, 8, $this->onHold());
        $service = app(OrderService::class);
        $order = $service->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        $service->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');

        $this->actingAs($this->owner)->postJson('/api/v1/orders/'.$order->id.'/dispatch', ['courier' => 'Test courier', 'tracking' => 'SELL-1',
            'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $order->lines()->first()->id, 'quantity' => 3]]])->assertOk();

        $this->assertSame(['Product unavailable'], $this->partnerNotices());
    }

    public function test_holding_and_releasing_the_last_sellable_units_tells_partners_each_time(): void
    {
        $product = $this->product();
        $lot = $this->lot($product, 6);
        $holds = app(StockLotHolds::class);

        $holds->hold($this->owner, $lot, 'Quality check');
        $this->assertSame(['Product unavailable'], $this->partnerNotices());

        $holds->release($this->owner, $lot, 'Passed the check');
        $this->assertSame(['Product unavailable', 'Product available'], $this->partnerNotices());
    }

    public function test_a_hold_that_leaves_other_units_to_sell_says_nothing(): void
    {
        $product = $this->product();
        $lot = $this->lot($product, 6);
        $this->lot($product, 4);
        $holds = app(StockLotHolds::class);

        $holds->hold($this->owner, $lot, 'Quality check');
        $holds->release($this->owner, $lot, 'Passed the check');

        $this->assertSame([], $this->partnerNotices());
    }

    public function test_releasing_a_lot_of_a_paused_product_says_nothing(): void
    {
        $product = $this->product(['is_stock_paused' => true]);
        $lot = $this->lot($product, 6, $this->onHold());

        app(StockLotHolds::class)->release($this->owner, $lot, 'Passed the check');

        $this->assertSame([], $this->partnerNotices());
    }

    public function test_writing_off_a_held_lot_does_not_tell_partners_twice(): void
    {
        $product = $this->product();
        $lot = $this->lot($product, 6, $this->onHold());

        app(StockLotHolds::class)->writeOff($this->owner, $lot, 'Damaged in storage');

        $this->assertSame(0, (int) $product->refresh()->stock);
        $this->assertSame([], $this->partnerNotices());
    }

    public function test_stock_on_the_road_cannot_be_sold_so_partners_hear_when_it_leaves_and_when_it_arrives(): void
    {
        $product = $this->product();
        $this->lot($product, 6);
        $this->lot($product, 8, $this->onHold());
        $rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01']);
        $transfers = app(StockTransfers::class);
        $transfer = $transfers->request($this->owner, ['product_id' => $product->id, 'quantity' => 6, 'source_branch_id' => $this->headOffice->id, 'destination_branch_id' => $rajshahi->id]);

        $transfers->send($this->owner, $transfer);
        $this->assertSame(8, (int) $product->refresh()->stock);
        $this->assertSame(['Product unavailable'], $this->partnerNotices());

        $transfers->receive($this->owner, $transfer->refresh());
        $this->assertSame(['Product unavailable', 'Product available'], $this->partnerNotices());
    }

    public function test_resuming_a_product_with_only_units_on_hold_does_not_announce_it(): void
    {
        $product = $this->product(['is_stock_paused' => true]);
        $this->lot($product, 6, $this->onHold());

        $this->actingAs($this->owner)->post('/commands/products/'.$product->id.'/toggle-stock')->assertRedirect();

        $this->assertFalse($product->refresh()->is_stock_paused);
        $this->assertSame([], $this->partnerNotices());
    }

    public function test_the_low_stock_list_counts_only_units_that_can_be_sold(): void
    {
        $product = $this->product(['name' => 'Nearly Gone Tonic', 'low_stock_at' => 5]);
        $this->lot($product, 3);
        $this->lot($product, 50, $this->onHold());
        $this->lot($product, 20, $this->expired());
        $plenty = $this->product(['name' => 'Plenty Tonic', 'low_stock_at' => 5]);
        $this->lot($plenty, 40);
        $this->lot($plenty, 9, $this->onHold());

        $this->assertLowStockList($this->viewAllBranches(), ['Nearly Gone Tonic' => 3], 1);
    }

    public function test_a_shelf_full_of_units_on_hold_is_still_a_low_stock_warning(): void
    {
        $product = $this->product(['name' => 'Quarantined Tonic', 'low_stock_at' => 5]);
        $this->lot($product, 80, $this->onHold());

        $this->assertLowStockList($this->viewAllBranches(), ['Quarantined Tonic' => 0], 1);
    }

    public function test_a_picked_branch_lists_its_own_usable_units_against_its_own_mark(): void
    {
        $product = $this->product(['name' => 'Branch Tonic', 'low_stock_at' => 100]);
        $this->lot($product, 2);
        $this->lot($product, 40, $this->onHold());
        $rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01']);
        $this->lot($product, 30, [], $rajshahi);

        $this->assertLowStockList($this->viewBranch($this->headOffice), ['Branch Tonic' => 2], 1);
        $this->assertLowStockList($this->viewBranch($rajshahi), [], 0);
    }

    public function test_a_picked_branch_that_does_not_send_orders_still_counts_its_own_units(): void
    {
        $product = $this->product(['name' => 'Clinic Tonic']);
        $clinic = CompanyBranch::factory()->create(['code' => 'CLN-01', 'is_stock_hub' => false]);
        $this->lot($product, 30, [], $clinic);

        $this->assertLowStockList($this->viewBranch($clinic), [], 0);
    }

    public function test_the_list_gives_the_same_count_as_the_sellable_stock_used_everywhere_else(): void
    {
        $product = $this->product(['low_stock_at' => 50]);
        $this->lot($product, 7);
        $this->lot($product, 4, $this->onHold());
        $this->lot($product, 2, $this->expired());

        $row = app(StaffDashboardSummary::class)->lowStockProducts(null)->sole();

        $this->assertSame($product->refresh()->sellableStock(), $row->stock);
    }

    /** @param array<string, mixed> $attributes */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['stock' => 0, 'low_stock_at' => 5, 'active' => true, ...$attributes]);
    }

    /**
     * Puts a lot on a branch's shelf and keeps the branch and company totals right.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function lot(Product $product, int $quantity, array $attributes = [], ?CompanyBranch $branch = null): StockLot
    {
        $branch ??= $this->headOffice;
        BranchProductStock::query()
            ->firstOrCreate(['branch_id' => $branch->id, 'product_id' => $product->id], ['quantity' => 0, 'low_stock_at' => 5])
            ->increment('quantity', $quantity);
        $product->increment('stock', $quantity);

        return StockLot::factory()->create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => $quantity, 'received_quantity' => $quantity, ...$attributes]);
    }

    /** @return array<string, mixed> */
    private function onHold(): array
    {
        return ['held_at' => now(), 'hold_reason' => 'Quality check'];
    }

    /** @return array<string, string> */
    private function expired(): array
    {
        return ['expires_on' => now()->subDays(3)->toDateString()];
    }

    private function receive(Product $product, int $quantity): void
    {
        app(StockService::class)->adjust($this->owner, ['product_id' => $product->id, 'branch_id' => $this->headOffice->id, 'quantity' => $quantity, 'reason' => 'Supplier delivery', 'reference' => (string) Str::uuid()]);
    }

    /**
     * What the dealer has been told, oldest first, leaving out anything else in their inbox.
     *
     * @return list<string>
     */
    private function partnerNotices(): array
    {
        return DB::table('account_notices')->where('user_id', $this->dealer->id)->whereIn('title', ['Product available', 'Product unavailable'])->orderBy('id')->pluck('title')->all();
    }

    private function viewAllBranches(): TestResponse
    {
        return $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->withSession([BranchOperations::SessionView => 'all'])->get('/overview');
    }

    private function viewBranch(CompanyBranch $branch): TestResponse
    {
        $this->flushSession();

        return $this->actingAs(User::factory()->staff()->create(['active_branch_id' => $branch->id]))->onPortal('sadmin')->get('/overview');
    }

    /** @param array<string, int> $expected */
    private function assertLowStockList(TestResponse $response, array $expected, int $count): void
    {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.lowStock', fn ($rows) => collect($rows)->pluck('stock', 'name')->all() === $expected)
            ->where('data.admin_home.work_queue', fn ($queue) => (int) collect($queue)->firstWhere('key', 'low_stock_products')['count'] === $count));
    }
}
