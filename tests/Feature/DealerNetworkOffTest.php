<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Client, 2026-11-20: the public dealer network is switched off; WhatsApp and the other social links stay. */
class DealerNetworkOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.public_dealer_network' => false]);
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

    public function test_the_footer_keeps_only_the_social_links_of_the_stay_connected_block(): void
    {
        SiteCustomization::save(['social_whatsapp' => 'https://wa.me/8801730532966']);
        $html = (string) $this->onPortal('public')->get('/contact')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<footer.*<\/footer>/s', $html, $footer));

        $this->assertStringContainsString('wa.me', $footer[0]);
        $this->assertStringNotContainsString('ref-footer-brand', $footer[0]);
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

    public function test_the_owner_cannot_switch_the_page_back_on_from_the_editor_while_the_network_is_off(): void
    {
        SiteCustomization::save(['page_enabled_dealers' => '1']);

        $this->assertFalse(SiteCustomization::isPageEnabled('dealers'));
        $this->assertNull(SiteCustomization::publicHref('/dealers'));
    }

    public function test_switching_the_network_on_brings_everything_back(): void
    {
        config(['madina.public_dealer_network' => true]);
        SiteCustomization::forgetCache();

        $this->onPortal('public')->get('/dealers')->assertOk();
        $this->onPortal('public')->get('/verify-dealer')->assertOk();
        $this->assertStringContainsString('ref-dealer-network-banner', (string) $this->onPortal('public')->get('/')->getContent());
    }
}
