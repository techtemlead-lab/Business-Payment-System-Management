<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Bangla visitors see what the owner typed (copyright line, empty-notice
 * text), tab titles use the editor's company name, and the old
 * "64 districts" picture text is gone.
 */
class BanglaOwnerEditsAndBrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // The settings are kept in memory for one request; start each test clean.
        SiteCustomization::forgetCache();
    }

    public function test_copyright_line_defaults_to_the_built_in_line_in_each_language(): void
    {
        $year = date('Y');

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('© '.$year.' Madina Natural Health Care. All rights reserved.');
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertSee('© '.LocalDigits::text($year, 'bn').' মদিনা ন্যাচারাল হেলথ কেয়ার। সর্বস্বত্ব সংরক্ষিত।');
    }

    public function test_an_owner_copyright_edit_is_not_dropped_for_bangla_visitors(): void
    {
        SiteCustomization::save(['copyright_text' => '© Madina Herbal House, Rangpur']);

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertSee('© Madina Herbal House, Rangpur');
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()->assertSee('© Madina Herbal House, Rangpur');
    }

    public function test_bangla_copyright_box_is_used_for_bangla_visitors_only(): void
    {
        SiteCustomization::save([
            'copyright_text' => '© Madina Herbal House, Rangpur',
            'copyright_text_bn' => '© মদিনা হারবাল হাউস, রংপুর',
        ]);

        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertSee('© মদিনা হারবাল হাউস, রংপুর')
            ->assertDontSee('© Madina Herbal House, Rangpur');
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('© Madina Herbal House, Rangpur')
            ->assertDontSee('© মদিনা হারবাল হাউস, রংপুর');
    }

    public function test_factory_copyright_saved_in_an_earlier_year_shows_the_current_year(): void
    {
        SiteCustomization::save(['copyright_text' => '© 2020 Madina Natural Health Care. All rights reserved.']);

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('© '.date('Y').' Madina Natural Health Care. All rights reserved.')
            ->assertDontSee('© 2020 Madina');
    }

    public function test_empty_notice_text_defaults_and_owner_edits_in_each_language(): void
    {
        $this->withSession(['locale' => 'bn'])->get('/notice')->assertOk()
            ->assertSee(__('site.notice_empty', [], 'bn'));

        SiteCustomization::save(['notice_empty' => 'New circulars come out on the 1st of each month.']);

        $this->withSession(['locale' => 'en'])->get('/notice')->assertOk()
            ->assertSee('New circulars come out on the 1st of each month.');
        $this->withSession(['locale' => 'bn'])->get('/circular-price')->assertOk()
            ->assertSee('New circulars come out on the 1st of each month.');

        SiteCustomization::save(['notice_empty_bn' => 'প্রতি মাসের ১ তারিখে নতুন সার্কুলার আসে।']);

        $this->withSession(['locale' => 'bn'])->get('/notice')->assertOk()
            ->assertSee('প্রতি মাসের ১ তারিখে নতুন সার্কুলার আসে।');
        $this->withSession(['locale' => 'en'])->get('/notice')->assertOk()
            ->assertSee('New circulars come out on the 1st of each month.');
    }

    public function test_tab_titles_keep_the_current_company_name_by_default(): void
    {
        $product = Product::factory()->create(['active' => true]);

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('<title>'.e(__('site.home_document_title', [], 'en')).'</title>', false);
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertSee('<title>'.e(__('site.home_document_title', [], 'bn')).'</title>', false);

        foreach (['/about', '/products', '/feedback', '/products/'.$product->id] as $path) {
            $this->withSession(['locale' => 'en'])->get($path)->assertOk()
                ->assertSee(' · Madina Natural Health Care</title>', false);
        }
    }

    public function test_tab_titles_follow_the_company_name_in_the_website_editor(): void
    {
        $product = Product::factory()->create(['active' => true]);
        SiteCustomization::save(['contact_company_title' => 'Madina Herbal House']);

        foreach (['/', '/about', '/products', '/feedback', '/products/'.$product->id] as $path) {
            foreach (['en', 'bn'] as $locale) {
                $html = $this->withSession(['locale' => $locale])->get($path)->assertOk()->getContent();
                $this->assertMatchesRegularExpression('#<title>[^<]*Madina Herbal House[^<]*</title>#u', $html, $path.' '.$locale);
                // The page name itself may still mention the old name (owner text); only the brand part changes.
                $this->assertStringNotContainsString(' · Madina Natural Health Care</title>', $html, $path.' '.$locale);
                $this->assertStringNotContainsString('<title>Madina Natural Health Care · ', $html, $path.' '.$locale);
            }
        }
    }

    public function test_panel_tabs_say_madina_until_the_owner_changes_the_company_name(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk()
            ->assertSee('<meta name="application-name" content="Madina">', false);

        SiteCustomization::save(['contact_company_title' => 'Madina Herbal House']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk()
            ->assertSee('<meta name="application-name" content="Madina Herbal House">', false);
    }

    public function test_banner_picture_text_no_longer_claims_64_districts(): void
    {
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertDontSee('64 Districts')
            ->assertSee(__('site.side_banner_2_slide3_alt', [], 'en'));
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertDontSee('৬৪ জেলা');
    }

    public function test_old_64_districts_text_saved_by_an_earlier_save_is_replaced(): void
    {
        $slides = SiteCustomization::defaults()['side_banner_2_slides'];
        $slides[2]['alt'] = 'Madina Authorized Partner Pharmacy Outlets Across All 64 Districts';
        SiteCustomization::save(['side_banner_2_slides' => $slides]);

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertDontSee('64 Districts')
            ->assertSee(__('site.side_banner_2_slide3_alt', [], 'en'));
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertDontSee('64 Districts')
            ->assertSee(__('site.side_banner_2_slide3_alt', [], 'bn'));
    }

    public function test_website_staff_save_the_bangla_boxes_and_see_the_bangla_hints(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'copyright_text_bn' => '© মদিনা',
            'notice_empty_bn' => 'শিগগিরই নোটিশ আসবে।',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('© মদিনা', SiteCustomization::all()['copyright_text_bn']);
        $this->assertSame('শিগগিরই নোটিশ আসবে।', SiteCustomization::all()['notice_empty_bn']);

        $this->actingAs($editor)->get('/company/website')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.bangla_defaults.notice_empty', __('site.notice_empty', [], 'bn'))
                ->has('data.bangla_defaults.copyright'));

        $this->actingAs(User::factory()->staff('account_manager')->create())
            ->post('/commands/customization', ['customization' => ['copyright_text_bn' => 'x']])
            ->assertForbidden();
    }
}
