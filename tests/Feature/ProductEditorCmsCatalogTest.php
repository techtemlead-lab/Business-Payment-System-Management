<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductEditorCmsCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_product_editor_receives_cms_catalog_props(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Herbal Care', 'slug' => 'herbal-care']);
        $subcategory = ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'Digestive',
            'slug' => 'digestive',
        ]);
        $unit = ProductUnit::factory()->create(['name' => 'Bottle', 'code' => 'bottle']);
        $product = Product::factory()->withCatalog($subcategory, $unit)->create([
            'expires_on' => '2027-06-15',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('/company/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProductEditor')
                ->where('product.product_subcategory_id', $subcategory->id)
                ->where('product.product_unit_id', $unit->id)
                ->where('product.product_category_id', $category->id)
                ->where('product.expires_on', '2027-06-15')
                ->has('catalog_categories', 1)
                ->where('catalog_categories.0.id', $category->id)
                ->where('catalog_categories.0.subcategories.0.id', $subcategory->id)
                ->has('catalog_units', 1)
                ->where('catalog_units.0.id', $unit->id)
                ->where('supports_product_expiry', true));
    }

    public function test_product_save_persists_mrp_dealer_sub_dealer_cms_fks_and_expires_on(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Herbal Care', 'slug' => 'herbal-care-save']);
        $subcategory = ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'Digestive',
            'slug' => 'digestive-save',
        ]);
        $otherCategory = ProductCategory::factory()->create(['name' => 'Other Line', 'slug' => 'other-line']);
        $mismatched = ProductSubcategory::factory()->create([
            'product_category_id' => $otherCategory->id,
            'name' => 'Mismatched',
            'slug' => 'mismatched',
        ]);
        $inactiveSub = ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'Inactive Digestive',
            'slug' => 'inactive-digestive',
            'is_active' => false,
        ]);
        $unit = ProductUnit::factory()->create(['name' => 'Bottle', 'code' => 'bottle-save']);
        $inactiveUnit = ProductUnit::factory()->create([
            'name' => 'Retired Pack',
            'code' => 'retired-pack',
            'is_active' => false,
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->post('/commands/products', [
            'name' => 'Madina Digestive Syrup',
            'sku' => 'CMS-EDITOR-001',
            'product_category_id' => $category->id,
            'product_subcategory_id' => $mismatched->id,
            'product_unit_id' => $unit->id,
            'strength' => '120 ml',
            'description' => 'CMS-backed product',
            'mrp' => '400.00',
            'dealer_discount_pct' => '30',
            'sub_dealer_discount_pct' => '25',
            'cost_price' => '90.00',
            'low_stock_at' => 8,
            'expires_on' => '2028-01-31',
            'active' => true,
            'featured' => false,
            'video_source' => 'none',
            'reason' => 'Create CMS catalog product with full pricing',
        ])->assertSessionHasErrors(['product_subcategory_id', 'product_category_id']);

        $this->actingAs($admin)->post('/commands/products', [
            'name' => 'Madina Digestive Syrup',
            'sku' => 'CMS-EDITOR-001',
            'product_category_id' => $category->id,
            'product_subcategory_id' => $inactiveSub->id,
            'product_unit_id' => $unit->id,
            'mrp' => '400.00',
            'dealer_discount_pct' => '30',
            'sub_dealer_discount_pct' => '25',
            'expires_on' => '2028-01-31',
            'video_source' => 'none',
            'reason' => 'Reject inactive subcategory',
        ])->assertSessionHasErrors('product_subcategory_id');

        $this->actingAs($admin)->post('/commands/products', [
            'name' => 'Madina Digestive Syrup',
            'sku' => 'CMS-EDITOR-001',
            'product_category_id' => $category->id,
            'product_subcategory_id' => $subcategory->id,
            'product_unit_id' => $inactiveUnit->id,
            'mrp' => '400.00',
            'dealer_discount_pct' => '30',
            'sub_dealer_discount_pct' => '25',
            'expires_on' => '2028-01-31',
            'video_source' => 'none',
            'reason' => 'Reject inactive unit',
        ])->assertSessionHasErrors('product_unit_id');

        $this->actingAs($admin)->post('/commands/products', [
            'name' => 'Madina Digestive Syrup',
            'sku' => 'CMS-EDITOR-001',
            'product_category_id' => $category->id,
            'product_subcategory_id' => $subcategory->id,
            'product_unit_id' => $unit->id,
            'strength' => '120 ml',
            'description' => 'CMS-backed product',
            'mrp' => '400.00',
            'dealer_discount_pct' => '30',
            'sub_dealer_discount_pct' => '25',
            'cost_price' => '90.00',
            'low_stock_at' => 8,
            'expires_on' => '2028-01-31',
            'active' => true,
            'featured' => true,
            'video_source' => 'none',
            'reason' => 'Create CMS catalog product with full pricing',
        ])->assertRedirect();

        $product = Product::query()->where('sku', 'CMS-EDITOR-001')->firstOrFail();

        $this->assertSame('Herbal Care', $product->category);
        $this->assertSame('Digestive', $product->sub_category);
        $this->assertSame('Bottle', $product->unit);
        $this->assertSame($subcategory->id, $product->product_subcategory_id);
        $this->assertSame($unit->id, $product->product_unit_id);
        $this->assertSame('400.00', (string) $product->mrp);
        $this->assertSame('280.00', (string) $product->dealer_price);
        $this->assertSame('300.00', (string) $product->sub_dealer_price);
        $this->assertSame('90.00', (string) $product->cost_price);
        $this->assertSame(8, $product->low_stock_at);
        $this->assertSame('120 ml', $product->strength);
        $this->assertSame('2028-01-31', optional($product->expires_on)?->toDateString());
        $this->assertTrue($product->featured);
    }

    public function test_workspace_catalog_section_includes_cms_catalog_props(): void
    {
        ProductCategory::factory()->create(['name' => 'Workspace Cat', 'slug' => 'workspace-cat']);
        ProductUnit::factory()->create(['name' => 'Pack', 'code' => 'pack-workspace']);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->onPortal('sadmin')
            ->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.catalog_categories')
                ->has('data.catalog_units')
                ->where('data.supports_product_expiry', true));
    }
}
