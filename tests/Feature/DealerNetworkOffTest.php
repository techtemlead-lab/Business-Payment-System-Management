<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client, 2026-11-20: the public dealer network is off by default; the owner can turn it on in the website editor.
 * WhatsApp and the other social links stay either way.
 */
class DealerNetworkOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.public_dealer_network' => false, 'madina.public_verify_dealer' => false]);
        SiteCustomization::forgetCache();
    }

    public function test_the_dealer_directory_and_dealer_check_are_gone(): void
    {
        $this->onPortal('public')->get('/dealers')->assertNotFound();
        $this->onPortal('public')->get('/verify-dealer')->assertNotFound();
    }

    public function test_the_home_page_has_no_dealer_network_banner_or_link(): void
    {
        $html = (string) $this->onPortal('public')->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('ref-dealer-network-banner', $html);
        $this->assertStringNotContainsString('href="/dealers"', $html);
        $this->assertStringNotContainsString('verify-dealer', $html);
    }

    public function test_the_footer_keeps_the_social_links_and_phones_hide_the_brand_block(): void
    {
        SiteCustomization::save(['social_whatsapp' => 'https://wa.me/8801730532966']);
        $html = (string) $this->onPortal('public')->get('/contact')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<footer.*<\/footer>/s', $html, $footer));

        $this->assertStringContainsString('wa.me', $footer[0]);
        // Client, 2026-11-20: the brand block stays on desktop and is hidden on phones by the stylesheet.
        $this->assertStringContainsString('ref-footer-brandblock', $footer[0]);
        $this->assertStringContainsString('@media(max-width:767px){.ref-footer-brandblock{display:none}}', (string) file_get_contents(resource_path('css/reference-home.css')));
        $this->assertStringNotContainsString('ref-business-identity', $footer[0]);
        $this->assertStringNotContainsString('href="/dealers"', $footer[0]);
        $this->assertStringNotContainsString('verify-dealer', $footer[0]);
        $this->assertStringContainsString('/batch-check', $footer[0], 'The product batch check stays public.');
    }

    public function test_a_product_page_does_not_send_visitors_to_the_dealer_network(): void
    {
        $product = Product::factory()->create(['active' => true]);

        $this->onPortal('public')->get('/products/'.$product->id)->assertOk()
            ->assertDontSee('href="/dealers"', false)
            ->assertDontSee(__('site.find_a_dealer'));
    }

    public function test_the_owner_can_turn_the_page_on_in_the_website_editor(): void
    {
        SiteCustomization::save(['page_enabled_dealers' => '1']);

        $this->assertTrue(SiteCustomization::isPageEnabled('dealers'));
        $this->onPortal('public')->get('/dealers')->assertOk();
    }

    public function test_turning_the_page_on_brings_everything_back(): void
    {
        SiteCustomization::save(['page_enabled_dealers' => '1']);

        $this->onPortal('public')->get('/dealers')->assertOk();
        // Verify a dealer has its own switch: turning the network on does not turn it on.
        $this->onPortal('public')->get('/verify-dealer')->assertNotFound();
        SiteCustomization::save(['page_enabled_verify-dealer' => '1']);
        $this->onPortal('public')->get('/verify-dealer')->assertOk();
        $this->assertStringContainsString('ref-dealer-network-banner', (string) $this->onPortal('public')->get('/')->getContent());
    }
}
