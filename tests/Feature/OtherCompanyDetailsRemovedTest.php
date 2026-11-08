<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Another company's name, email, factory and phone must not appear anywhere on the
 * site. Madina's own licence, address and phone stay.
 */
class OtherCompanyDetailsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const CleanupMigration = '2026_11_08_185433_scrub_other_company_details_from_site_content.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_no_default_website_text_names_the_other_company(): void
    {
        $texts = json_encode([SiteCustomization::defaults(), trans('site', [], 'en'), trans('site', [], 'bn')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach (['Holistic', 'holisticnutracare', 'হোলিস্টিক', 'নিউট্রাকেয়ার', 'Moshorikhula', 'মশরীখোলা', '01727612379'] as $name) {
            $this->assertStringNotContainsString($name, $texts);
        }
    }

    public function test_madinas_own_details_stay(): void
    {
        $defaults = SiteCustomization::defaults();

        $this->assertStringContainsString('2026-00563', $defaults['footer_about_desc']);
        $this->assertStringContainsString('Thana Road, Pirganj', $defaults['office_address']);
        $this->assertSame('+8801730532966', $defaults['footer_phone']);
    }

    public function test_the_footer_has_no_email_until_the_owner_adds_one(): void
    {
        $this->get('/')->assertOk()->assertDontSee('ref-footer-contact-link is-email', false);

        SiteCustomization::save(['footer_email' => 'office@example.test']);

        $this->get('/')->assertOk()->assertSee('office@example.test');
    }

    public function test_saved_copies_of_the_other_companys_details_are_cleaned(): void
    {
        SiteCustomization::save(['footer_phone' => '+8801730532966']);
        $body = json_decode((string) DB::table('content_pages')->where('slug', 'frontend_customization')->value('body'), true);
        $body['footer_email'] = 'holisticnutracare@gmail.com';
        $body['footer_about_desc'] = 'Licensed distributor. Marketed nutrition line: Holistic Nutracare.';
        $body['about_vision'] = 'To bring herbal and food products to every home in Bangladesh.';
        $body['about_mission_title'] = 'Owner written title';
        $body['trust_pillars'] = [['icon' => 'fa-leaf', 'title' => 'Herbal', 'subtitle' => 'Holistic Nutracare herbal products.']];
        $body['slides'] = [['image' => '/a.jpg', 'alt' => 'Madina Holistic Wellness & Physiotherapy Center']];
        DB::table('content_pages')->where('slug', 'frontend_customization')->update(['body' => json_encode($body, JSON_THROW_ON_ERROR)]);
        $product = Product::factory()->create(['description' => 'Fruit syrup. Marketed by Madina Natural Health Care / Holistic Nutracare.']);

        (require database_path('migrations/'.self::CleanupMigration))->up();

        $stored = (string) DB::table('content_pages')->where('slug', 'frontend_customization')->value('body');
        $cleaned = json_decode($stored, true);
        $this->assertStringNotContainsString('olistic', $stored);
        $this->assertArrayNotHasKey('footer_email', $cleaned);
        $this->assertArrayNotHasKey('footer_about_desc', $cleaned);
        $this->assertArrayNotHasKey('about_vision', $cleaned);
        $this->assertSame('Owner written title', $cleaned['about_mission_title']);
        $this->assertSame('+8801730532966', $cleaned['footer_phone']);
        $this->assertSame('Herbal', $cleaned['trust_pillars'][0]['title']);
        $this->assertSame('Herbal and nutrition products for everyday care.', $cleaned['trust_pillars'][0]['subtitle']);
        $this->assertSame('Madina Natural Health Care', $cleaned['slides'][0]['alt']);
        $this->assertSame('Fruit syrup. Marketed by Madina Natural Health Care.', $product->fresh()->description);
    }

    public function test_the_other_companys_leaflet_is_not_served(): void
    {
        $this->assertFileDoesNotExist(public_path('branding/holistic-nutracare-product-list.jpg'));
    }
}
