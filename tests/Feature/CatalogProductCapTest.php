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
