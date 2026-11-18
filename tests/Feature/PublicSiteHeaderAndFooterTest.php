<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteHeaderAndFooterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // A stock-list test in the same process can leave the website facts in cache.
        SiteCustomization::reset();
    }

    public function test_the_logo_is_in_the_top_band_and_home_takes_its_place_in_the_navigation(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSeeInOrder(['ref-company-bar', 'ref-bar-logo', 'ref-bar-text', 'ref-sticky-chrome', 'ref-nav-home', 'ref-search'], false);
        $response->assertDontSee('ref-madina-logo', false);
    }

    public function test_the_top_band_shows_the_trade_licence_above_the_trademark_number_on_the_right(): void
    {
        $this->get('/')->assertOk()
            ->assertSeeInOrder(['ref-bar-logo', 'ref-bar-text', 'ref-bar-legal', 'Trade Licence', '2026-00563', 'TM No.', 'BD-H-1-335903', 'ref-sticky-chrome'], false);
    }

    public function test_the_top_band_follows_the_owners_licence_and_shows_only_the_trademark_number(): void
    {
        SiteCustomization::save(['trust_trade_licence' => 'TL-777', 'trust_trademark' => 'BD-H-1-999999 (Class 5, filed 1 Jan 2027)']);

        $band = $this->get('/')->assertOk()->getContent();
        $band = substr($band, (int) strpos($band, 'ref-bar-legal'), 400);

        $this->assertStringContainsString('TL-777', $band);
        $this->assertStringContainsString('BD-H-1-999999', $band);
        $this->assertStringNotContainsString('Class 5', $band);
    }

    public function test_the_top_band_labels_are_in_bangla_on_a_bangla_page(): void
    {
        $this->onPortal('public')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->onPortal('public')->get('/')->assertOk()->assertSee('ট্রেড লাইসেন্স')->assertSee('টিএম নম্বর');
    }

    public function test_home_is_marked_as_the_current_page_only_on_the_home_page(): void
    {
        $this->get('/')->assertOk()->assertSee('class="ref-nav-home" aria-current="page"', false);
        $this->get('/about')->assertOk()->assertSee('class="ref-nav-home"', false)->assertDontSee('class="ref-nav-home" aria-current="page"', false);
    }

    public function test_the_managing_directors_message_comes_before_the_banners_on_the_home_page(): void
    {
        $this->get('/')->assertOk()->assertSeeInOrder(['ref-md-message--home', 'ref-promotions'], false);
    }

    public function test_the_footer_does_not_repeat_the_support_number_as_the_hotline(): void
    {
        SiteCustomization::save(['footer_support_title' => '+8801730532966', 'footer_phone' => '01730-532966']);

        $this->get('/')->assertOk()->assertDontSee('ref-footer-contact-link is-hotline', false);
    }

    public function test_a_different_hotline_number_still_shows_in_the_footer(): void
    {
        SiteCustomization::save(['footer_support_title' => '+8801730532966', 'footer_phone' => '01730532965']);

        $this->get('/')->assertOk()
            ->assertDontSee('ref-footer-contact-link is-hotline', false)
            ->assertSeeInOrder(['ref-support-contacts', '+8801730532965', '+8801730532966', 'ref-support-mail', 'mailto:madinanaturalhealthcare@gmail.com'], false);
    }

    public function test_the_footer_shows_the_location_only_once(): void
    {
        $this->withCookie('locale', 'en')->get('/')->assertOk()
            ->assertSee(__('site.footer_location', [], 'en'))
            ->assertDontSee('ref-footer-contact-link is-address', false);
    }

    public function test_the_footer_shows_the_company_name_beside_the_logo_before_the_social_links(): void
    {
        $this->get('/')->assertOk()->assertSeeInOrder(['ref-footer-brand', '<img', '<h3>', 'ref-socials'], false);
    }

    public function test_the_dealer_login_menu_opens_the_public_website(): void
    {
        $this->onPortal('dealer')->get('/login')->assertOk()
            ->assertSee(PortalHost::url('public', '/products'), false)
            ->assertSee(PortalHost::url('public', '/dealers'), false)
            ->assertDontSee('href="/products"', false)
            ->assertDontSee('href="/dealers"', false)
            ->assertSee('data-office-portal="1"', false);
    }

    public function test_english_and_bangla_premium_fonts_are_loaded(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('family=marcellus:400|figtree:', false)
            ->assertSee('tiro-bangla:400|anek-bangla:', false);
    }
}
