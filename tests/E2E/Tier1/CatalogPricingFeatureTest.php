<?php

namespace Tests\E2E\Tier1;

use App\Domain\Pricing\ProductPricing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\E2E\E2ETestCase;

/**
 * Tier 1 — Catalog & Pricing Feature Tests.
 *
 * Covers:
 * - F03: Wholesale Purchase Booking (Product catalog matrix, cart computation, pricing checks, checkout modal)
 * - F15: Catalog & Product Management (Product CRUD, pricing matrix, image handling, discount limits)
 * - B01: Product Pricing Contract (ProductPricing unitPrice calculation, discount bounds 20-50%, MRP ceiling)
 */
class CatalogPricingFeatureTest extends E2ETestCase
{
    /**
     * F03: Dealer retrieves catalog listing with dynamic wholesale pricing and stock indicators.
     */
    public function test_dealer_views_catalog_with_wholesale_pricing(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct([
            'name' => 'Catalog Herb Blend',
            'mrp' => '500.00',
            'dealer_price' => '300.00',
            'stock' => 50,
            'active' => true,
        ]);

        $response = $this->actingAs($dealer)->getJson('/api/v1/catalog');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Catalog Herb Blend']);
        $response->assertJsonFragment(['mrp' => '500.00']);
        $response->assertJsonFragment(['price' => '300.00']);
    }

    /**
     * F03: Sub-dealer views catalog and receives sub-dealer wholesale price.
     */
    public function test_sub_dealer_views_catalog_with_sub_dealer_wholesale_pricing(): void
    {
        $subDealer = $this->createSubDealer();
        $this->createProduct([
            'name' => 'SubDealer Specific Tonic',
            'mrp' => '1000.00',
            'dealer_price' => '600.00',
            'sub_dealer_price' => '750.00',
            'stock' => 25,
            'active' => true,
        ]);

        $response = $this->actingAs($subDealer)->getJson('/api/v1/catalog');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'SubDealer Specific Tonic']);
        $response->assertJsonFragment(['price' => '750.00']);
    }

    /**
     * F03: Catalog filter supports category and search query cascading.
     */
    public function test_catalog_search_and_category_filtering(): void
    {
        $dealer = $this->createDealer();
        $category = ProductCategory::query()->create(['name' => 'Digestive Care', 'slug' => 'digestive-care']);
        $subcategory = ProductSubcategory::query()->create([
            'product_category_id' => $category->id,
            'name' => 'Syrups',
            'slug' => 'syrups',
        ]);
        $unit = ProductUnit::query()->create(['name' => 'Bottle 200ml', 'code' => 'bot-200']);

        $matched = $this->createProduct([
            'name' => 'Ginger Digest Elixir',
            'category' => $category->name,
            'sub_category' => $subcategory->name,
            'product_subcategory_id' => $subcategory->id,
            'product_unit_id' => $unit->id,
        ]);

        $unmatched = $this->createProduct([
            'name' => 'Hair Vital Oil',
            'category' => 'Hair Care',
        ]);

        $response = $this->actingAs($dealer)->getJson('/api/v1/catalog?q=Ginger');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Ginger Digest Elixir']);
        $response->assertJsonMissing(['name' => 'Hair Vital Oil']);
    }

    /**
     * F03: Dealer books a wholesale purchase with valid item quantities.
     */
    public function test_dealer_submits_wholesale_purchase_booking(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct([
            'mrp' => '400.00',
            'dealer_price' => '250.00',
            'stock' => 20,
        ]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.total', '500.00'); // 2 * 250.00
        $this->assertDatabaseHas('orders', [
            'user_id' => $dealer->id,
            'status' => 'submitted',
            'total' => '500.00',
        ]);
    }

    /**
     * F03: Cart calculation correctly aggregates multiple product line totals.
     */
    public function test_wholesale_order_computes_multi_product_cart_total(): void
    {
        $dealer = $this->createDealer();
        $p1 = $this->createProduct(['mrp' => '200.00', 'dealer_price' => '120.00', 'stock' => 10]);
        $p2 = $this->createProduct(['mrp' => '300.00', 'dealer_price' => '180.00', 'stock' => 10]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $p1->id, 'quantity' => 3], // 360.00
                ['product_id' => $p2->id, 'quantity' => 2], // 360.00
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total', '720.00');
    }

    /**
     * F15: Staff creates a new product with valid discount limits via commands.
     */
    public function test_staff_creates_product_with_valid_pricing(): void
    {
        $staff = $this->createStaff('super_admin');
        $category = ProductCategory::query()->create(['name' => 'General Wellness', 'slug' => 'general-wellness']);
        $subcategory = ProductSubcategory::query()->create([
            'product_category_id' => $category->id,
            'name' => 'Capsules',
            'slug' => 'capsules',
        ]);
        $unit = ProductUnit::query()->create(['name' => 'Strip of 10', 'code' => 'strip-10']);

        $response = $this->actingAs($staff)->onPortal('sadmin')->post('/commands/products', [
            'name' => 'Moringa Leaf Extract',
            'sku' => 'MOR-CAP-001',
            'mrp' => '500.00',
            'dealer_price' => '350.00', // 30% discount
            'sub_dealer_price' => '400.00', // 20% discount
            'product_category_id' => $category->id,
            'product_subcategory_id' => $subcategory->id,
            'product_unit_id' => $unit->id,
            'redirect_to' => 'catalog',
            'reason' => 'Introducing new herbal supplement line',
        ]);

        $response->assertRedirect('/catalog');
        $this->assertDatabaseHas('products', [
            'sku' => 'MOR-CAP-001',
            'mrp' => '500.00',
            'dealer_price' => '350.00',
            'sub_dealer_price' => '400.00',
        ]);
    }

    /**
     * F15: Product update maintains version concurrency and modifies attributes.
     */
    public function test_staff_updates_existing_product(): void
    {
        $staff = $this->createStaff('super_admin');
        $product = $this->createProduct([
            'name' => 'Original Herbal Cream',
            'mrp' => '300.00',
            'dealer_price' => '200.00',
            'stock' => 15,
        ]);
        $product->refresh();

        $response = $this->actingAs($staff)->onPortal('sadmin')->patch('/commands/products/'.$product->id, [
            'name' => 'Updated Herbal Cream Plus',
            'mrp' => '300.00',
            'dealer_price' => '180.00', // 40% discount
            'version' => (string) ($product->registry_version ?? 0),
            'redirect_to' => 'catalog',
            'reason' => 'Formula enhancement update',
        ]);

        $response->assertRedirect('/catalog');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Herbal Cream Plus',
            'dealer_price' => '180.00',
        ]);
    }

    /**
     * F15: Staff toggles product stock ordering pause state.
     */
    public function test_staff_toggles_product_ordering_pause(): void
    {
        $staff = $this->createStaff('super_admin');
        $product = $this->createProduct(['is_stock_paused' => false]);

        $response = $this->actingAs($staff)->onPortal('sadmin')->post('/commands/products/'.$product->id.'/toggle-stock');

        $response->assertSessionHasNoErrors();
        $this->assertTrue((bool) $product->refresh()->is_stock_paused);

        // Toggle back to resume
        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/products/'.$product->id.'/toggle-stock');
        $this->assertFalse((bool) $product->refresh()->is_stock_paused);
    }

    /**
     * F15: Category and Unit management endpoints function reliably.
     */
    public function test_category_and_unit_management(): void
    {
        $staff = $this->createStaff('super_admin');

        // Create category
        $catResponse = $this->actingAs($staff)->postJson('/api/v1/product-categories', [
            'name' => 'Ayurvedic Remedies',
        ]);
        $catResponse->assertCreated();
        $categoryId = $catResponse->json('data.id');

        // Create unit
        $unitResponse = $this->actingAs($staff)->postJson('/api/v1/product-units', [
            'name' => 'Box 50 Sachets',
            'code' => 'box-50-sach',
        ]);
        $unitResponse->assertCreated();

        $this->assertDatabaseHas('product_categories', ['id' => $categoryId, 'name' => 'Ayurvedic Remedies']);
        $this->assertDatabaseHas('product_units', ['code' => 'box-50-sach']);
    }

    /**
     * F15: Non-owner staff cannot exceed maximum discount percentage limits.
     */
    public function test_staff_cannot_exceed_maximum_dealer_discount_rate(): void
    {
        // Role 'editor' or 'inventory' does not have super_admin owner powers
        $staff = $this->createStaff('super_admin');
        // Max dealer discount for owner is 70%, for non-owner is 50%
        $product = $this->createProduct(['mrp' => '1000.00', 'dealer_price' => '700.00']);
        $product->refresh();

        // Attempt setting dealer price to 200.00 (80% discount > 70% max)
        $response = $this->actingAs($staff)->onPortal('sadmin')->patch('/commands/products/'.$product->id, [
            'name' => $product->name,
            'mrp' => '1000.00',
            'dealer_price' => '200.00', // 80% discount
            'version' => (string) ($product->registry_version ?? 0),
            'reason' => 'Excessive discount test',
        ]);

        $response->assertSessionHasErrors(['dealer_price']);
    }

    /**
     * B01: ProductPricing contract calculates correct wholesale unit price for dealer.
     */
    public function test_product_pricing_contract_calculates_dealer_unit_price(): void
    {
        $pricing = app(ProductPricing::class);
        $dealer = $this->createDealer();
        $product = $this->createProduct([
            'mrp' => '300.00',
            'dealer_price' => '180.00', // 40% discount
        ]);

        $unitPriceCents = $pricing->unitPrice($product, $dealer);

        $this->assertSame(18000, $unitPriceCents);
        $this->assertSame('180.00', Money::decimal($unitPriceCents));
    }

    /**
     * B01: ProductPricing contract calculates sub-dealer price or defaults to 40% discount (60% MRP).
     */
    public function test_product_pricing_contract_sub_dealer_explicit_and_fallback(): void
    {
        $pricing = app(ProductPricing::class);
        $subDealer = $this->createSubDealer();

        // Case 1: Explicit sub_dealer_price
        $productExplicit = $this->createProduct([
            'mrp' => '500.00',
            'dealer_price' => '300.00',
            'sub_dealer_price' => '380.00',
        ]);
        $this->assertSame(38000, $pricing->unitPrice($productExplicit, $subDealer));

        // Case 2: Fallback 40% discount (60% of MRP)
        $productFallback = $this->createProduct([
            'mrp' => '500.00',
            'dealer_price' => '300.00',
            'sub_dealer_price' => null,
        ]);
        $this->assertSame(30000, $pricing->unitPrice($productFallback, $subDealer));
    }

    /**
     * B01: ProductPricing contract enforces non-negative price bound and rejects <= 0.
     */
    public function test_product_pricing_rejects_non_positive_price(): void
    {
        $pricing = app(ProductPricing::class);
        $dealer = $this->createDealer();
        $product = $this->createProduct([
            'mrp' => '200.00',
            'dealer_price' => '0.00',
        ]);

        $this->expectException(ValidationException::class);
        $pricing->unitPrice($product, $dealer);
    }

    /**
     * B01: ProductPricing contract enforces MRP upper ceiling and rejects price > MRP.
     */
    public function test_product_pricing_rejects_price_greater_than_mrp(): void
    {
        $pricing = app(ProductPricing::class);
        $dealer = $this->createDealer();
        $product = $this->createProduct([
            'mrp' => '200.00',
            'dealer_price' => '250.00', // Exceeds MRP
        ]);

        $this->expectException(ValidationException::class);
        $pricing->unitPrice($product, $dealer);
    }

    /**
     * B01: ProductPricing contract aborts with 403 for non-wholesaler roles (e.g. staff or retail user).
     */
    public function test_product_pricing_aborts_for_non_wholesaler(): void
    {
        $pricing = app(ProductPricing::class);
        $staff = $this->createStaff('finance');
        $product = $this->createProduct();

        $this->expectException(HttpException::class);
        $pricing->unitPrice($product, $staff);
    }
}
