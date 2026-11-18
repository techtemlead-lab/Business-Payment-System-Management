<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CR-06 for the website: every website wording has a Bangla box beside the English one. Staff type English,
 * the Bangla box is filled for them (or typed), and Bangla visitors read it; English visitors keep the English.
 */
class WebsiteBanglaTwinsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_every_website_wording_has_a_bangla_box_and_links_pictures_and_switches_do_not(): void
    {
        $twins = SiteCustomization::banglaTwinKeys();
        $defaults = SiteCustomization::defaults();

        foreach (['about_hero_title', 'about_history', 'header_about_title', 'footer_about_desc', 'campaign_title', 'footer_link_about', 'contact_email_title', 'meta_title', 'auth_login_title', 'product_add_to_cart_btn', 'invoice_manufacturer', 'invoice_marketed_by'] as $wording) {
            $this->assertContains($wording, $twins);
            $this->assertSame('', $defaults[$wording.'_bn']);
        }
        foreach (['site_logo_url', 'footer_link_about_url', 'contact_phone', 'contact_email', 'social_facebook', 'page_enabled_about', 'md_message_enabled', 'campaign_card1_price', 'map_latitude', 'invoice_marketed_phones', 'trust_tin'] as $setting) {
            $this->assertNotContains($setting, $twins);
        }
    }

    public function test_wordings_that_already_had_a_bangla_box_do_not_get_a_second_one(): void
    {
        $twins = SiteCustomization::banglaTwinKeys();

        foreach (['copyright_text', 'notice_empty', 'md_message_body', 'trust_refund_title'] as $wording) {
            $this->assertNotContains($wording, $twins);
            $this->assertArrayHasKey($wording.'_bn', SiteCustomization::defaults());
        }
    }

    public function test_bangla_visitors_read_the_bangla_box_and_english_visitors_the_english(): void
    {
        SiteCustomization::save(['about_hero_title' => 'Our Herbal Story', 'about_hero_title_bn' => 'আমাদের ভেষজ গল্প']);

        $this->withSession(['locale' => 'bn'])->get('/about')->assertOk()
            ->assertSee('আমাদের ভেষজ গল্প')
            ->assertDontSee('Our Herbal Story');
        $this->withSession(['locale' => 'en'])->get('/about')->assertOk()
            ->assertSee('Our Herbal Story')
            ->assertDontSee('আমাদের ভেষজ গল্প');
    }

    public function test_an_empty_bangla_box_changes_nothing_for_bangla_visitors(): void
    {
        SiteCustomization::save(['about_hero_title' => 'Our Herbal Story']);

        // As before: an owner's English edit is shown as written until the Bangla box holds words.
        $this->withSession(['locale' => 'bn'])->get('/about')->assertOk()->assertSee('Our Herbal Story');
    }

    public function test_wordings_read_straight_from_the_settings_also_follow_the_visitor_language(): void
    {
        SiteCustomization::save(['header_about_title' => 'About us', 'header_about_title_bn' => 'আমাদের কথা']);

        app()->setLocale('bn');
        $this->assertSame('আমাদের কথা', SiteCustomization::forPublicPages()['header_about_title']);
        app()->setLocale('en');
        $this->assertSame('About us', SiteCustomization::forPublicPages()['header_about_title']);
    }

    public function test_printed_letterheads_use_the_bangla_company_wording_for_a_bangla_reader(): void
    {
        SiteCustomization::save([
            'contact_company_title' => 'Madina Natural Health Care',
            'contact_company_title_bn' => 'মদিনা ন্যাচারাল হেলথ কেয়ার',
            'invoice_manufacturer' => 'Madina Herbal Lab',
            'invoice_manufacturer_bn' => 'মদিনা হারবাল ল্যাব',
        ]);
        $sources = ['app/Domain/Orders/OrderInvoice.php', 'app/Domain/Payouts/PayoutReceipts.php', 'app/Domain/Orders/ShippingLabels.php', 'app/Domain/Inventory/StockPrintPdf.php', 'app/Domain/Inventory/StockLotReports.php', 'app/Domain/Clinic/PrescriptionLetterhead.php', 'app/Support/LetterheadContacts.php'];

        app()->setLocale('bn');
        $bangla = SiteCustomization::inVisitorLanguage(SiteCustomization::all());
        app()->setLocale('en');
        $english = SiteCustomization::inVisitorLanguage(SiteCustomization::all());

        $this->assertSame('মদিনা ন্যাচারাল হেলথ কেয়ার', $bangla['contact_company_title']);
        $this->assertSame('মদিনা হারবাল ল্যাব', $bangla['invoice_manufacturer']);
        $this->assertSame('Madina Herbal Lab', $english['invoice_manufacturer']);
        foreach ($sources as $source) {
            $this->assertStringContainsString('SiteCustomization::inVisitorLanguage(SiteCustomization::all())', (string) file_get_contents(base_path($source)), $source.' must read the settings in the reader\'s language.');
        }
    }

    public function test_the_editor_saves_a_bangla_box_and_gets_each_wording_with_its_built_in_bangla_hint(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'services_hero_title' => 'Our Services',
            'services_hero_title_bn' => 'আমাদের সেবা',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('আমাদের সেবা', SiteCustomization::all()['services_hero_title_bn']);
        $this->actingAs($editor)->get('/company/website')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.bangla_defaults.twins.services_hero_title', __('site.services_hero_title', [], 'bn'))
                ->where('data.bangla_defaults.twins.header_about_title', '')
                ->where('data.customization.services_hero_title_bn', 'আমাদের সেবা'));
    }
}
