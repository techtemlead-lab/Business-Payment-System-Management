<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The owner's single Website page: nothing that could be changed before may be
 * lost, and every old address keeps working.
 */
class WebsiteAdminSimplifyTest extends TestCase
{
    use RefreshDatabase;

    /** Every box the old 5,143-line editor showed, by its stored setting name. */
    private const OldEditorBoxes = [
        'featured_products_badge', 'featured_products_title', 'public_products_subtitle', 'featured_products_view_all', 'featured_products_view_all_link', 'products_empty_title',
        'top_bar_text', 'search_placeholder', 'header_catalog_title', 'header_about_title', 'header_about_subtitle', 'header_contact_title', 'header_contact_subtitle', 'header_network_title', 'header_account_title', 'header_account_subtitle',
        'featured_categories_title', 'featured_categories_subtitle', 'announcement_text', 'dealer_banner_title', 'dealer_banner_subtitle', 'dealer_banner_btn_text',
        'catalog_categories_title', 'catalog_all_products_label', 'catalog_price_title', 'catalog_instock_label', 'catalog_apply_filters_btn', 'catalog_clear_filters_btn', 'catalog_sort_by_label', 'catalog_empty_title', 'catalog_empty_desc', 'catalog_empty_btn',
        'product_in_stock_label', 'product_out_of_stock_label', 'product_share_label', 'product_video_title', 'product_tab_description', 'product_tab_specification',
        'company_intro_title', 'company_intro_body', 'company_intro_eyebrow', 'home_bismillah_text', 'product_range_title', 'product_range_sub', 'partner_with_us_title', 'partner_with_us_sub', 'our_services_title', 'our_services_sub',
        'trust_pillars_title', 'video_gallery_title', 'video_gallery_subtitle', 'testimonials_title', 'testimonials_subtitle', 'testimonials_invite_title', 'testimonials_invite_body', 'feedback_page_title', 'feedback_page_lead',
        'about_hero_title', 'about_hero_lead', 'about_history_title', 'about_history', 'about_mission_title', 'about_mission', 'about_vision_title', 'about_vision', 'about_objectives_title',
        'about_obj1_title', 'about_obj1_desc', 'about_obj2_title', 'about_obj2_desc', 'about_obj3_title', 'about_obj3_desc', 'about_obj4_title', 'about_obj4_desc', 'about_network_title', 'about_network', 'about_btn_products', 'about_btn_dealers', 'about_btn_contact',
        'md_message_heading', 'md_message_heading_bn', 'md_message_name', 'md_message_name_bn', 'md_message_title', 'md_message_title_bn', 'md_message_company', 'md_message_company_bn', 'md_message_body', 'md_message_body_bn',
        'contact_title', 'contact_company_title', 'contact_company_desc', 'contact_phone_title', 'contact_phone', 'contact_phone_hours', 'contact_email_title', 'contact_email', 'contact_social_title',
        'contact_topic1_title', 'contact_topic1_desc', 'contact_topic2_title', 'contact_topic2_desc', 'contact_topic3_title', 'office_address', 'map_latitude', 'map_longitude', 'contact_form_title', 'map_title', 'map_subtitle', 'map_facility', 'map_directions_text',
        'invoice_manufacturer', 'invoice_marketed_by', 'invoice_marketed_phones', 'directory_title', 'directory_subtitle', 'directory_gps_btn', 'directory_empty_title', 'directory_empty_desc',
        'partnership_hero_title', 'partnership_hero_lead', 'partnership_why_title', 'partnership_steps_title', 'partnership_cta_title', 'partnership_cta_btn', 'partnership_cta_body', 'partnership_cta_link',
        'services_hero_title', 'services_hero_lead', 'services_cta_btn', 'services_cta_link', 'quality_hero_title', 'quality_hero_lead', 'quality_cta_btn', 'quality_cta_link',
        'faq_hero_title', 'faq_hero_lead', 'privacy_hero_title', 'privacy_hero_lead', 'privacy_body', 'terms_hero_title', 'terms_hero_lead', 'terms_body', 'legal_meta_effective', 'legal_meta_governance', 'legal_meta_location',
        'footer_support_heading', 'footer_support_title', 'footer_support_subtitle', 'footer_support_link', 'footer_support_icon', 'footer_directory_title', 'footer_directory_subtitle', 'footer_directory_link', 'footer_directory_icon', 'footer_phone', 'footer_email', 'footer_address',
        'footer_about_heading', 'footer_link_about', 'footer_link_about_url', 'footer_link_contact', 'footer_link_contact_url', 'footer_link_dealer_apply', 'footer_link_dealer_apply_url', 'footer_stay_connected_heading', 'footer_logo_url', 'footer_about_title', 'footer_about_desc',
        'social_facebook', 'social_youtube', 'social_linkedin', 'social_instagram', 'social_whatsapp', 'whatsapp_link', 'social_twitter', 'social_tiktok', 'social_telegram', 'app_google_play_link', 'app_apple_store_link',
        'copyright_text', 'copyright_text_bn', 'notice_empty', 'notice_empty_bn', 'floating_chat_label', 'auth_login_title', 'auth_login_subtitle', 'auth_dealer_help_text', 'auth_dealer_contact_text', 'partner_help_title', 'partner_help_body',
    ];

    /** Settings that other parts of the page change with buttons or lists. */
    private const OldEditorSwitchesAndLists = [
        'nav_categories', 'mobile_search_categories_enabled', 'mobile_search_categories_heading', 'mobile_search_categories_items', 'mobile_search_categories_limit',
        'slides', 'side_banner_1_slides', 'side_banner_2_slides', 'shortcuts_section_enabled', 'shortcuts',
        'featured_categories_enabled', 'featured_categories_items', 'trust_pillars_enabled', 'video_gallery_enabled', 'testimonials_section_enabled', 'home_bismillah_enabled', 'md_message_enabled', 'md_message_image', 'faq_items',
        'page_enabled_about', 'page_enabled_products', 'page_enabled_dealers', 'page_enabled_contact', 'page_enabled_partnership', 'page_enabled_services', 'page_enabled_quality', 'page_enabled_faq', 'page_enabled_privacy', 'page_enabled_terms',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** All the code of the new website page, as one piece of text. */
    private function websitePageSource(): string
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js/components/website'), RecursiveDirectoryIterator::SKIP_DOTS));
        $source = '';
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $source .= file_get_contents($file->getPathname())."\n";
        }

        return $source;
    }

    /** @return list<string> */
    private function numberedBoxes(): array
    {
        $boxes = [];
        foreach ([['partnership_benefit', 4], ['partnership_step', 3], ['services_card', 3], ['quality_pillar', 3]] as [$prefix, $count]) {
            for ($number = 1; $number <= $count; $number++) {
                $boxes[] = "{$prefix}{$number}_title";
                $boxes[] = "{$prefix}{$number}_desc";
            }
        }

        return $boxes;
    }

    public function test_every_box_from_the_old_editor_is_still_on_the_website_page(): void
    {
        $source = $this->websitePageSource();

        foreach (self::OldEditorBoxes as $key) {
            $this->assertStringContainsString("fieldKey=\"{$key}\"", $source, "The box for {$key} is missing.");
        }
        foreach (['partnership_benefit${i}_title', 'partnership_step${i}_desc', 'services_card${i}_title', 'quality_pillar${i}_desc'] as $template) {
            $this->assertStringContainsString($template, $source);
        }
    }

    public function test_every_box_starts_from_what_is_saved(): void
    {
        $startingValues = file_get_contents(resource_path('js/components/website/initialCustomization.ts'));

        foreach ([...self::OldEditorBoxes, ...self::OldEditorSwitchesAndLists, ...$this->numberedBoxes()] as $key) {
            $this->assertMatchesRegularExpression('/^\s+'.preg_quote($key, '/').':/m', $startingValues, "{$key} does not start from its saved value.");
        }
    }

    public function test_no_stored_setting_name_was_changed(): void
    {
        $storedNames = array_keys(SiteCustomization::defaults());

        foreach ([...self::OldEditorBoxes, ...self::OldEditorSwitchesAndLists, ...$this->numberedBoxes()] as $key) {
            $this->assertContains($key, $storedNames, "{$key} is not a stored setting.");
        }
    }

    public function test_every_text_setting_saves_and_comes_back(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $sent = [];
        foreach (SiteCustomization::defaults() as $key => $default) {
            if (is_array($default)) {
                continue;
            }
            $isLink = preg_match('/(^|_)(url|link|image|image_path)$/D', $key) === 1 || str_starts_with($key, 'social_');
            $sent[$key] = $isLink ? '/saved/'.$key : 'Saved value for '.$key;
        }

        $this->actingAs($editor)->post('/commands/customization', ['customization' => $sent])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $saved = SiteCustomization::raw();
        foreach ($sent as $key => $value) {
            $this->assertSame($value, $saved[$key] ?? null, "{$key} did not save.");
        }
    }

    public function test_saving_part_of_the_page_keeps_everything_else(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post('/commands/customization', ['customization' => ['footer_phone' => '01700000000', 'about_hero_title' => 'First title']])
            ->assertSessionHasNoErrors();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => ['about_hero_title' => 'Second title']])
            ->assertSessionHasNoErrors();

        $saved = SiteCustomization::raw();
        $this->assertSame('01700000000', $saved['footer_phone']);
        $this->assertSame('Second title', $saved['about_hero_title']);
    }

    public function test_lists_and_switches_still_save(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $faq = [['question' => 'Saved question', 'answer' => 'Saved answer']];
        $slides = SiteCustomization::defaults()['slides'];
        $slides[0]['alt'] = 'Saved slide';

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'faq_items' => $faq,
            'slides' => $slides,
            'page_enabled_about' => '0',
            'video_gallery_enabled' => '0',
        ]])->assertSessionHasNoErrors();

        $saved = SiteCustomization::raw();
        $this->assertSame($faq, $saved['faq_items']);
        $this->assertSame('Saved slide', $saved['slides'][0]['alt']);
        $this->assertSame('0', $saved['page_enabled_about']);
        $this->assertSame('0', $saved['video_gallery_enabled']);
    }

    public function test_old_website_addresses_still_open(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        foreach (['/website', '/customization', '/content', '/operations/video-marketing'] as $path) {
            $this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk();
        }
    }

    public function test_website_page_has_the_home_video_setting_and_the_page_text(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->get('/company/website')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'website')
                ->has('data.videoSetting')
                ->has('data.videoProducts')
                ->has('translations.website_admin.heading'));
    }

    public function test_video_marketing_points_to_the_website_page_for_staff_who_can_open_it(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/video-marketing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('videoManagedOnWebsite', true));
    }

    public function test_new_words_exist_in_both_languages(): void
    {
        $english = require lang_path('en/website_admin.php');
        $bangla = require lang_path('bn/website_admin.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
    }
}
