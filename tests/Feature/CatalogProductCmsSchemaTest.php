<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogProductCmsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cms_catalog_rows_and_product_foreign_keys_with_mrp_and_expiry_can_be_created(): void
    {
        $category = ProductCategory::factory()->create([
            'name' => 'Herbal Care',
            'slug' => 'herbal-care',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $subcategory = ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'Digestive',
            'slug' => 'digestive',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $unit = ProductUnit::factory()->create([
            'name' => 'Bottle',
            'code' => 'bottle',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $expiresOn = Carbon::parse('2027-06-15');

        $product = Product::factory()->create([
            'sku' => 'CMS-SCHEMA-001',
            'name' => 'Madina Digestive Syrup',
            'category' => $category->name,
            'sub_category' => $subcategory->name,
            'unit' => $unit->name,
            'strength' => '120 ml',
            'mrp' => '350.00',
            'dealer_price' => '280.00',
            'sub_dealer_price' => '300.00',
            'product_subcategory_id' => $subcategory->id,
            'product_unit_id' => $unit->id,
            'expires_on' => $expiresOn,
        ]);

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'name' => 'Herbal Care',
            'slug' => 'herbal-care',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('product_subcategories', [
            'id' => $subcategory->id,
            'product_category_id' => $category->id,
            'slug' => 'digestive',
        ]);
        $this->assertDatabaseHas('product_units', [
            'id' => $unit->id,
            'code' => 'bottle',
            'name' => 'Bottle',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sku' => 'CMS-SCHEMA-001',
            'mrp' => '350.00',
            'product_subcategory_id' => $subcategory->id,
            'product_unit_id' => $unit->id,
            'expires_on' => '2027-06-15',
        ]);

        $product->refresh()->load(['subcategory.category', 'productUnit']);

        $this->assertTrue($product->subcategory->is($subcategory));
        $this->assertTrue($product->subcategory->category->is($category));
        $this->assertTrue($product->productUnit->is($unit));
        $this->assertSame('2027-06-15', $product->expires_on?->toDateString());
        $this->assertSame('350.00', (string) $product->mrp);
        $this->assertTrue(Schema::hasColumns('products', ['category', 'sub_category', 'unit', 'product_subcategory_id', 'product_unit_id', 'expires_on']));
    }

    public function test_catalog_migration_backfills_foreign_keys_from_legacy_product_strings(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('products', 'product_subcategory_id'));
        $this->assertFalse(Schema::hasColumn('products', 'expires_on'));

        DB::table('product_subcategories')->delete();
        DB::table('product_categories')->delete();
        DB::table('product_units')->delete();

        $productId = DB::table('products')->insertGetId([
            'sku' => 'LEGACY-BF-001',
            'name' => 'Legacy Capsule',
            'category' => 'Capsule Line',
            'sub_category' => 'Immunity',
            'unit' => 'strip',
            'mrp' => '100.00',
            'dealer_price' => '80.00',
            'stock' => 5,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_categories', [
            'name' => 'Capsule Line',
            'slug' => 'capsule-line',
        ]);
        $this->assertDatabaseHas('product_subcategories', [
            'name' => 'Immunity',
            'slug' => 'immunity',
        ]);
        $this->assertDatabaseHas('product_units', [
            'name' => 'strip',
            'code' => 'strip',
        ]);

        $product = DB::table('products')->where('id', $productId)->first();
        $this->assertNotNull($product->product_subcategory_id);
        $this->assertNotNull($product->product_unit_id);
        $this->assertSame('Capsule Line', $product->category);
        $this->assertSame('Immunity', $product->sub_category);
        $this->assertSame('strip', $product->unit);

        $subcategory = ProductSubcategory::query()->findOrFail($product->product_subcategory_id);
        $this->assertSame('Capsule Line', $subcategory->category->name);
        $this->assertSame('strip', ProductUnit::query()->findOrFail($product->product_unit_id)->code);
    }
}
