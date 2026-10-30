<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_product_search_returns_matching_json_suggestions(): void
    {
        Product::factory()->create([
            'name' => 'Taskin Pain Oil 60ml',
            'category' => 'Oil',
            'active' => true,
            'mrp' => 450,
        ]);
        Product::factory()->create([
            'name' => 'Ashwagandha Capsule',
            'category' => 'Ayurvedic',
            'active' => true,
            'mrp' => 320,
        ]);

        $this->getJson('/search/products?q=Taskin')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.name', 'Taskin Pain Oil 60ml')
            ->assertJsonPath('all_url', '/products?q=Taskin');
    }

    public function test_mobile_search_overlay_includes_live_search_controls(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('refMobileSearchOverlay', false)
            ->assertSee('refMobileSearchResults', false)
            ->assertSee('ref-mobile-search-submit', false)
            ->assertSee(__('site.search_mobile_hint'), false);
    }
}
