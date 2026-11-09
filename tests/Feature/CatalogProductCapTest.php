<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogProductCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_catalog_picker_payload_is_capped_and_searchable(): void
    {
        Product::factory()->count(3)->create(['active' => true, 'name' => 'Cap Alpha']);
        Product::factory()->count(2)->create(['active' => true, 'name' => 'Cap Beta']);
        Product::factory()->create(['active' => false, 'name' => 'Cap Hidden']);

        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?q=Beta')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.all_catalog_products_truncated', false)
                ->has('data.all_catalog_products', 2)
                ->where('data.all_catalog_products.0.name', 'Cap Beta'));
    }

    public function test_staff_catalog_lists_categories_saved_only_on_older_products(): void
    {
        Product::factory()->create(['name' => 'Old Oil', 'category' => 'Old Free Text', 'active' => true]);

        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.used_categories', ['Old Free Text']));
    }

    public function test_staff_catalog_filters_by_category_price_and_stock(): void
    {
        Product::factory()->create([
            'name' => 'Cheap Tablet',
            'category' => 'Tablet',
            'sub_category' => 'Pain',
            'unit' => 'Box',
            'mrp' => '50.00',
            'active' => true,
            'stock' => 5,
        ]);
        Product::factory()->create([
            'name' => 'Costly Syrup',
            'category' => 'Syrup',
            'sub_category' => 'Cough',
            'unit' => 'Bottle',
            'mrp' => '500.00',
            'active' => true,
            'stock' => 0,
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?category=Tablet&max_mrp=100')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.products', 1)
                ->where('data.products.0.name', 'Cheap Tablet')
                ->where('filters.category', 'Tablet'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?availability=out_of_stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.products', 1)
                ->where('data.products.0.name', 'Costly Syrup'));
    }

    public function test_staff_catalog_sorts_by_price_and_rejects_an_unknown_sort(): void
    {
        Product::factory()->create(['name' => 'Apple Tonic', 'mrp' => '90.00', 'active' => true, 'stock' => 2]);
        Product::factory()->create(['name' => 'Zebra Balm', 'mrp' => '10.00', 'active' => true, 'stock' => 8]);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products.0.name', 'Apple Tonic')
                ->where('data.products.1.name', 'Zebra Balm'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?sort=price_asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products.0.name', 'Zebra Balm')
                ->where('data.products.1.name', 'Apple Tonic')
                ->where('filters.sort', 'price_asc'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog?sort=unknown')
            ->assertSessionHasErrors('sort');
    }

    public function test_staff_website_product_picker_is_capped(): void
    {
        Product::factory()->count(3)->create(['active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/website')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products_truncated', false)
                ->has('data.products', 3));
    }
}
