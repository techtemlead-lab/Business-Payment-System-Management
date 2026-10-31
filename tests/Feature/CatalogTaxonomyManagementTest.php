<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogTaxonomyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_with_catalog_can_create_and_update_category_subcategory_and_unit(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/product-categories', [
            'name' => 'Herbal Care',
            'sort_order' => 1,
            'is_active' => true,
        ])->assertRedirect();

        $category = ProductCategory::query()->where('slug', 'herbal-care')->firstOrFail();
        $this->assertSame('Herbal Care', $category->name);
        $this->assertTrue($category->is_active);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/product-subcategories', [
            'product_category_id' => $category->id,
            'name' => 'Digestive',
            'sort_order' => 2,
        ])->assertRedirect();

        $subcategory = ProductSubcategory::query()->where('slug', 'digestive')->firstOrFail();
        $this->assertSame($category->id, $subcategory->product_category_id);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/product-units', [
            'name' => 'Bottle',
            'code' => 'bottle',
            'sort_order' => 3,
        ])->assertRedirect();

        $unit = ProductUnit::query()->where('code', 'bottle')->firstOrFail();
        $this->assertSame('Bottle', $unit->name);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/product-categories/'.$category->id, [
            'name' => 'Herbal Wellness',
            'is_active' => true,
        ])->assertRedirect();
        $this->assertSame('Herbal Wellness', $category->fresh()->name);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/product-subcategories/'.$subcategory->id, [
            'name' => 'Immunity',
            'slug' => 'immunity',
        ])->assertRedirect();
        $this->assertSame('Immunity', $subcategory->fresh()->name);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/product-units/'.$unit->id, [
            'name' => 'Glass Bottle',
            'is_active' => false,
        ])->assertRedirect();
        $this->assertFalse($unit->fresh()->is_active);
    }

    public function test_duplicate_category_slug_and_unit_code_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        ProductCategory::factory()->create(['name' => 'Oils', 'slug' => 'oils']);
        ProductUnit::factory()->create(['name' => 'Pack', 'code' => 'pack']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/product-categories', [
            'name' => 'Oils Line',
            'slug' => 'oils',
        ])->assertSessionHasErrors('slug');

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/product-units', [
            'name' => 'Packet',
            'code' => 'pack',
        ])->assertSessionHasErrors('code');
    }

    public function test_subcategory_slug_must_be_unique_within_category(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $category = ProductCategory::factory()->create(['name' => 'Care', 'slug' => 'care']);
        ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'Tablet',
            'slug' => 'tablet',
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/product-subcategories', [
            'product_category_id' => $category->id,
            'name' => 'Tablet Form',
            'slug' => 'tablet',
        ])->assertSessionHasErrors('slug');
    }

    public function test_catalog_capability_is_required_for_mutations(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer']);
        $support = User::factory()->create(['role' => 'support']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($dealer)->onPortal('sadmin')->post('/commands/product-categories', [
            'name' => 'Blocked',
        ])->assertForbidden();

        $this->actingAs($support)->onPortal('manage')->post('/commands/product-units', [
            'name' => 'Blocked Unit',
        ])->assertForbidden();

        $this->actingAs($editor)->onPortal('manage')->post('/commands/product-categories', [
            'name' => 'Editor Category',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_categories', ['name' => 'Editor Category', 'slug' => 'editor-category']);
    }

    public function test_backend_api_lists_and_creates_taxonomy_for_staff(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $category = ProductCategory::factory()->create(['name' => 'API Cat', 'slug' => 'api-cat']);
        ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'API Sub',
            'slug' => 'api-sub',
        ]);
        ProductUnit::factory()->create(['name' => 'API Unit', 'code' => 'api-unit']);

        $this->actingAs($admin)->getJson('/api/v1/product-categories')
            ->assertOk()
            ->assertJsonPath('data.categories.0.name', 'API Cat')
            ->assertJsonPath('data.categories.0.subcategories.0.name', 'API Sub');

        $this->actingAs($admin)->getJson('/api/v1/product-units')
            ->assertOk()
            ->assertJsonPath('data.units.0.code', 'api-unit');

        $this->actingAs($admin)->postJson('/api/v1/product-categories', [
            'name' => 'From API',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'From API')
            ->assertJsonPath('data.slug', 'from-api');
    }

    public function test_backend_api_rejects_staff_without_catalog_capability(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->postJson('/api/v1/product-categories', [
            'name' => 'Nope',
        ])->assertForbidden();
    }

    public function test_workspace_catalog_props_include_nested_categories_and_units(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $category = ProductCategory::factory()->create(['name' => 'Workspace Cat', 'slug' => 'workspace-cat', 'is_active' => false]);
        ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'Workspace Sub',
            'slug' => 'workspace-sub',
        ]);
        ProductUnit::factory()->create(['name' => 'Workspace Unit', 'code' => 'workspace-unit', 'is_active' => false]);

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'catalog')
                ->has('data.catalog_categories', 1)
                ->where('data.catalog_categories.0.name', 'Workspace Cat')
                ->where('data.catalog_categories.0.subcategories.0.name', 'Workspace Sub')
                ->has('data.catalog_units', 1)
                ->where('data.catalog_units.0.code', 'workspace-unit'));
    }

    public function test_site_customization_prefers_cms_tables_and_returns_empty_without_defaults(): void
    {
        $this->assertSame([], SiteCustomization::getConfiguredCategoryNames());
        $this->assertSame([], SiteCustomization::getConfiguredSubCategories());
        $this->assertDoesNotMatchRegularExpression('/Tablet|Capsule|Syrup/', implode(',', SiteCustomization::getConfiguredSubCategories()));

        $category = ProductCategory::factory()->create(['name' => 'CMS Source', 'slug' => 'cms-source']);
        ProductSubcategory::factory()->create([
            'product_category_id' => $category->id,
            'name' => 'CMS Sub',
            'slug' => 'cms-sub',
        ]);

        $this->assertContains('CMS Source', SiteCustomization::getConfiguredCategoryNames());
        $this->assertSame(['CMS Sub'], SiteCustomization::getConfiguredSubCategories());
        $this->assertSame(['CMS Sub'], SiteCustomization::getConfiguredSubCategories('CMS Source'));
        $this->assertSame([], SiteCustomization::getConfiguredSubCategories('Missing'));
    }
}
