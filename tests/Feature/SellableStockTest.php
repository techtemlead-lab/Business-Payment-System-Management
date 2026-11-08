<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLots;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellableStockTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
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

    private function branchStock(Product $product): BranchProductStock
    {
        return BranchProductStock::query()->where('branch_id', $this->headOffice->id)->where('product_id', $product->id)->firstOrFail();
    }

    public function test_held_and_expired_units_are_not_counted(): void
    {
        $product = $this->product();
        $this->lot($product, 10);
        $this->lot($product, 4, $this->onHold());
        $this->lot($product, 3, $this->expired());
        $product->refresh();

        $this->assertSame(17, (int) $product->stock);
        $this->assertSame(10, $product->sellableStock());
        $this->assertSame('in_stock', $product->stockStatus());
    }

    public function test_a_lot_that_expires_today_can_still_be_sold(): void
    {
        $product = $this->product();
        $this->lot($product, 9, ['expires_on' => StockLot::today()]);

        $this->assertSame(9, $product->refresh()->sellableStock());
    }

    public function test_only_held_or_expired_units_read_as_out_of_stock(): void
    {
        $held = $this->product();
        $this->lot($held, 8, $this->onHold());
        $expired = $this->product();
        $this->lot($expired, 8, $this->expired());

        $this->assertSame('out_of_stock', $held->refresh()->stockStatus());
        $this->assertSame('out_of_stock', $expired->refresh()->stockStatus());
    }

    public function test_the_low_stock_label_follows_the_sellable_count(): void
    {
        $product = $this->product(['low_stock_at' => 5]);
        $this->lot($product, 3);
        $this->lot($product, 50, $this->onHold());

        $this->assertSame('low_stock', $product->refresh()->stockStatus());
    }

    public function test_the_count_matches_what_an_order_can_be_given(): void
    {
        $product = $this->product();
        $this->lot($product, 6);
        $this->lot($product, 4, $this->onHold());
        $this->lot($product, 2, $this->expired());
        $product->refresh();
        $lots = app(StockLots::class);
        $sellable = $product->sellableStock();

        $this->assertSame($sellable, $lots->usableQuantity($this->branchStock($product), $product));

        try {
            DB::transaction(fn () => $lots->take($this->branchStock($product), $product, $sellable + 1, ['kind' => StockLots::KindDispatch]));
            $this->fail('An order must not be given more than the sellable count.');
        } catch (ValidationException) {
            $this->assertSame($sellable, $product->sellableStock());
        }

        $given = $lots->take($this->branchStock($product), $product, $sellable, ['kind' => StockLots::KindDispatch]);

        $this->assertSame($sellable, array_sum(array_column($given, 'quantity')));
        $this->assertSame(0, $product->sellableStock());
    }

    public function test_lots_at_a_closed_branch_or_a_non_hub_branch_are_not_counted(): void
    {
        $product = $this->product();
        $this->lot($product, 5);
        $this->lot($product, 7, [], CompanyBranch::factory()->create(['is_active' => false]));
        $this->lot($product, 9, [], CompanyBranch::factory()->create(['is_stock_hub' => false]));

        $this->assertSame(5, $product->refresh()->sellableStock());
    }

    public function test_old_company_wide_stock_counts_until_the_product_expires(): void
    {
        $product = $this->product(['stock' => 40]);
        $expired = $this->product(['stock' => 40, 'expires_on' => now()->subDay()->toDateString()]);

        $this->assertSame(40, $product->sellableStock());
        $this->assertSame('in_stock', $product->stockStatus());
        $this->assertSame(0, $expired->sellableStock());

        $stock = app(BranchOperations::class)->ensureBranchStock($this->headOffice, $product);
        $this->assertSame(40, app(StockLots::class)->usableQuantity($stock, $product));
    }

    public function test_extra_units_in_a_branch_total_count_like_the_allocation_treats_them(): void
    {
        $product = $this->product();
        $this->lot($product, 5);
        $this->branchStock($product)->increment('quantity', 3);
        $product->refresh();

        $this->assertSame(8, $product->sellableStock());
        $this->assertSame(8, app(StockLots::class)->usableQuantity($this->branchStock($product), $product));
    }

    public function test_a_branch_total_below_its_lots_counts_like_the_allocation_treats_it(): void
    {
        $product = $this->product();
        $this->lot($product, 5);
        $this->lot($product, 2, $this->onHold());
        $this->branchStock($product)->decrement('quantity', 3);
        $product->refresh();

        $this->assertSame(2, $product->sellableStock());
        $this->assertSame(2, app(StockLots::class)->usableQuantity($this->branchStock($product), $product));
    }

    public function test_the_public_catalogue_counts_only_sellable_units(): void
    {
        $quarantined = $this->product(['name' => 'Quarantined Tonic']);
        $this->lot($quarantined, 8, $this->onHold());
        $ready = $this->product(['name' => 'Ready Tonic']);
        $this->lot($ready, 8);

        $this->get('/products?in_stock=1')->assertOk()->assertSee('Ready Tonic')->assertDontSee('Quarantined Tonic');
        $this->get('/products')->assertOk()->assertSee('data-stock-status="out_of_stock"', false);
        $this->get('/products/'.$quarantined->id)->assertOk()
            ->assertViewHas('item', fn (array $item): bool => $item['stock_status'] === 'out_of_stock');
        $this->get('/products/'.$ready->id)->assertOk()
            ->assertViewHas('item', fn (array $item): bool => $item['stock_status'] === 'in_stock');
    }

    public function test_the_home_page_cards_count_only_sellable_units(): void
    {
        $quarantined = $this->product(['name' => 'Quarantined Tonic', 'featured' => true]);
        $this->lot($quarantined, 8, $this->onHold());
        SiteCustomization::forgetCache();

        $card = collect(SiteCustomization::getFeaturedProducts())->firstWhere('id', $quarantined->id);

        $this->assertSame('out_of_stock', $card['stock_status']);
    }

    public function test_the_dealer_ordering_screen_counts_only_sellable_units(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = $this->product();
        $this->lot($product, 6);
        $this->lot($product, 40, $this->onHold());
        $this->lot($product, 12, $this->expired());

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products.0.id', $product->id)
                ->where('data.products.0.stock', 6)
                ->where('data.products.0.stock_status', 'in_stock'));
    }

    public function test_the_dealer_api_catalogue_counts_only_sellable_units(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = $this->product();
        $this->lot($product, 6);
        $this->lot($product, 40, $this->onHold());

        $this->actingAs($dealer)->getJson('/api/v1/catalog')->assertOk()
            ->assertJsonPath('data.0.stock', 6)
            ->assertJsonPath('data.0.stock_status', 'in_stock');
    }

    public function test_a_repeat_order_is_cut_to_the_sellable_count(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = $this->product(['dealer_price' => '100.00']);
        $this->lot($product, 2);
        $this->lot($product, 50, $this->onHold());
        $order = Order::create(['number' => 'ORD-'.Str::upper(Str::random(8)), 'user_id' => $dealer->id, 'status' => 'delivered', 'total' => '900.00', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 9, 'mrp' => $product->mrp, 'unit_price' => '100.00', 'total' => '900.00']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.repeat_orders.0.items.0.quantity', 2)
                ->where('data.repeat_orders.0.items.0.product.stock', 2)
                ->where('data.repeat_orders.0.items.0.quantity_reduced', true));
    }

    public function test_the_staff_catalog_filters_on_sellable_units_and_keeps_the_label_right(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $quarantined = $this->product(['name' => 'Quarantined Tonic']);
        $this->lot($quarantined, 8, $this->onHold());
        $ready = $this->product(['name' => 'Ready Tonic']);
        $this->lot($ready, 3);
        $this->lot($ready, 30, $this->expired());

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?availability=in_stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.products', 1)
                ->where('data.products.0.name', 'Ready Tonic')
                ->where('data.products.0.stock', 3)
                ->where('data.products.0.stock_status', 'low_stock'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?availability=out_of_stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.products', 1)
                ->where('data.products.0.name', 'Quarantined Tonic'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?sort=stock_desc')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.products.0.name', 'Ready Tonic'));
    }

    public function test_the_stock_screen_still_shows_what_is_on_the_shelf(): void
    {
        $staff = User::factory()->staff('inventory')->create(['status' => 'active']);
        $product = $this->product();
        $this->lot($product, 4);
        $this->lot($product, 6, $this->onHold());

        $this->actingAs($staff)->onPortal('manage')->get('/inventory')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products.0.id', $product->id)
                ->where('data.products.0.stock', 10)
                ->where('data.products.0.stock_status', 'low_stock'));
    }

    public function test_the_product_screen_keeps_the_sellable_count_out_of_its_serialized_row(): void
    {
        $product = $this->product();
        $this->lot($product, 4);

        $loaded = Product::query()->withSellableStock()->whereKey($product->id)->firstOrFail();

        $this->assertSame(4, $loaded->sellableStock());
        $this->assertArrayNotHasKey('sellable_stock', $loaded->toArray());
    }
}
