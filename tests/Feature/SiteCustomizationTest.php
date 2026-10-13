<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteCustomizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_and_dealer_cannot_access_site_customization(): void
    {
        $this->get('/company/customization')->assertRedirect('/company/login');
        $dealer = User::factory()->create(['role' => 'dealer']);
        $this->actingAs($dealer)->get('/company/customization')->assertForbidden();
    }

    public function test_staff_with_content_permission_can_view_customization_dashboard(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/company/customization')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal')
                ->where('section', 'customization')
                ->has('data.customization')
                ->has('data.defaults')
            );
    }

    public function test_customization_can_be_saved_and_reflected_on_public_home_page(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $payload = [
            'customization' => [
                'top_bar_text' => 'OFFICIAL MADINA HEALTH STORE',
                'announcement_text' => 'Special Ramadan offer: 10% discount on all wellness supplements!',
                'featured_products_title' => 'Handpicked Wellness Collection',
                'about_headline' => 'Leading Organic Health Care Hub in BD',
                'copyright_text' => '© 2026 Madina Natural Health Care Custom Copyright.',
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        // Verify public home page reflects customized elements
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('OFFICIAL MADINA HEALTH STORE');
        $response->assertSee('Special Ramadan offer: 10% discount on all wellness supplements!');
        $response->assertSee('Handpicked Wellness Collection');
        $response->assertSee('Leading Organic Health Care Hub in BD');
        $response->assertSee('© 2026 Madina Natural Health Care Custom Copyright.');

        // Verify unset elements still show standard placeholders
        $response->assertSee('Featured Categories');
        $response->assertSee('Our Products');
    }

    public function test_customization_can_be_reset_to_default_placeholders(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        SiteCustomization::save([
            'top_bar_text' => 'CUSTOM TOP BAR TEXT',
        ]);

        $this->get('/')->assertSee('CUSTOM TOP BAR TEXT');

        $this->actingAs($editor)->post('/commands/customization/reset')
            ->assertSessionHas('success');

        $this->get('/')->assertSee('MADINA NATURAL HEALTH CARE');
        $this->get('/')->assertDontSee('CUSTOM TOP BAR TEXT');
    }

    public function test_inner_pages_and_catalog_reflect_customized_placeholders(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $payload = [
            'customization' => [
                'about_hero_title' => 'Custom About Hero Title',
                'contact_company_title' => 'Custom Contact Company Hub',
                'directory_title' => 'Custom Dealer Directory Point',
                'catalog_categories_title' => 'Custom Catalog Filter Head',
                'auth_login_title' => 'Custom Partner Access Portal',
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        $this->get('/about')->assertOk()->assertSee('Custom About Hero Title');
        $this->get('/contact')->assertOk()->assertSee('Custom Contact Company Hub');
        $this->get('/dealers')->assertOk()->assertSee('Custom Dealer Directory Point');
        $this->get('/products')->assertOk()->assertSee('Custom Catalog Filter Head');

        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Custom Partner Access Portal');
    }
}
