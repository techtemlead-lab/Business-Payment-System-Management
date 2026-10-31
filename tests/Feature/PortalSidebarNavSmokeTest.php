<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalSidebarNavSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_super_admin_can_render_stream_one_portal_sections(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $sections = [
            '/' => 'overview',
            '/catalog' => 'catalog',
            '/catalog?tab=taxonomy' => 'catalog',
            '/inventory' => 'inventory',
            '/inventory?action=stock_in' => 'inventory',
            '/inventory?action=stock_out' => 'inventory',
            '/branches' => 'branches',
            '/place-codes' => 'place-codes',
        ];

        foreach ($sections as $path => $section) {
            $this->actingAs($admin)->onPortal('sadmin')->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('portals/sadmin/Dashboard')
                    ->where('section', $section));
        }
    }

    public function test_super_admin_catalog_and_stock_nav_chrome_keys_are_present(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'catalog')
                ->where('translations.portal.nav_module_catalog', 'Catalog')
                ->where('translations.portal.nav_module_stock', 'Stock')
                ->where('translations.portal.nav_products', 'Products')
                ->where('translations.portal.cms_taxonomy_tab', 'Categories & Units')
                ->where('translations.portal.stock_action_product_in', 'Product In')
                ->where('translations.portal.stock_action_product_out', 'Product Out')
                ->where('translations.portal.branches', 'Branches')
                ->where('translations.portal.place_codes', 'Place codes'));
    }
}
