<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlankWebsiteLinksStayHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function footerOn(string $path): string
    {
        $html = (string) $this->onPortal('public')->get($path)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<footer.*<\/footer>/s', $html, $footer));

        return $footer[0];
    }

    public function test_a_footer_link_left_blank_is_hidden_and_stays_blank_in_the_editor(): void
    {
        SiteCustomization::save([
            'footer_link_about_url' => '',
            'footer_link_services_url' => '',
            'footer_directory_link' => '',
        ]);

        $footer = $this->footerOn('/contact');
        $this->assertStringNotContainsString('href="/about"', $footer);
        $this->assertStringNotContainsString('href="/services"', $footer);
        $this->assertStringNotContainsString('ref-support-box" href="/dealers"', $footer);
        $this->assertStringContainsString('href="/quality"', $footer, 'A link that was not cleared stays on the site.');
        $this->assertSame('', SiteCustomization::all()['footer_link_about_url']);
    }

    public function test_the_whatsapp_box_left_blank_removes_the_chat_button_and_the_icon(): void
    {
        $this->onPortal('public')->get('/about')->assertOk()->assertSee('ref-floating-tools', false)->assertSee('wa.me', false);

        SiteCustomization::save(['social_whatsapp' => '']);

        $this->onPortal('public')->get('/about')
            ->assertOk()
            ->assertDontSee('ref-floating-tools', false)
            ->assertDontSee('wa.me', false);
        $this->assertSame('', SiteCustomization::all()['whatsapp_link']);
    }

    public function test_the_home_page_view_all_link_left_blank_is_hidden(): void
    {
        Product::factory()->create(['featured' => true]);
        $this->onPortal('public')->get('/')->assertOk()->assertSee('ref-view-all-link', false);

        SiteCustomization::save(['featured_products_view_all_link' => '']);

        $this->onPortal('public')->get('/')->assertOk()->assertDontSee('ref-view-all-link', false);
    }

    public function test_page_buttons_left_without_a_link_are_hidden(): void
    {
        SiteCustomization::save([
            'partnership_cta_btn' => 'QA Partnership Button',
            'partnership_cta_link' => '',
            'services_cta_btn' => 'QA Services Button',
            'services_cta_link' => '',
            'quality_cta_btn' => 'QA Quality Button',
            'quality_cta_link' => '',
        ]);

        $this->onPortal('public')->get('/partnership')->assertOk()->assertDontSee('QA Partnership Button');
        $this->onPortal('public')->get('/services')->assertOk()->assertDontSee('QA Services Button');
        $this->onPortal('public')->get('/quality')->assertOk()->assertDontSee('QA Quality Button');
    }

    public function test_a_page_button_with_a_link_still_shows(): void
    {
        SiteCustomization::save([
            'partnership_cta_btn' => 'QA Partnership Button',
            'partnership_cta_link' => '/contact',
        ]);

        $this->onPortal('public')->get('/partnership')->assertOk()->assertSee('QA Partnership Button')->assertSee('href="/contact"', false);
    }

    public function test_a_plain_text_box_left_blank_still_goes_back_to_its_built_in_words(): void
    {
        SiteCustomization::save(['contact_title' => '']);

        $this->assertSame(SiteCustomization::defaults()['contact_title'], SiteCustomization::all()['contact_title']);
    }
}
