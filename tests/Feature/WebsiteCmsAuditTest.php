<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Things on the public site that used to be fixed in the code and can now be changed
 * from Website Settings, and the guard that keeps them from going back.
 */
class WebsiteCmsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_a_new_logo_reaches_every_public_place(): void
    {
        SiteCustomization::save(['site_logo_url' => '/branding/site-photos/new-logo.png']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'madina-header-logo.png'), 'The old logo is still on the page.');
        $this->assertStringContainsString('<link rel="preload" as="image" href="/branding/site-photos/new-logo.png"', $html);
        $this->assertGreaterThanOrEqual(3, substr_count($html, '/branding/site-photos/new-logo.png'));
    }

    public function test_a_new_white_logo_reaches_the_footer_and_the_phone_menu(): void
    {
        SiteCustomization::save(['footer_logo_url' => '/branding/site-photos/white.png']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'madina-header-logo-white.png'));
        $this->assertSame(2, substr_count($html, '/branding/site-photos/white.png'));
    }

    public function test_the_login_and_error_pages_use_the_saved_logo(): void
    {
        SiteCustomization::save(['site_logo_url' => '/branding/site-photos/new-logo.png']);

        $this->get('/login')->assertOk()->assertSee('/branding/site-photos/new-logo.png', false)->assertDontSee('madina-header-logo.png', false);
        $this->onPortal('sadmin')->get('/login')->assertOk()->assertSee('/branding/site-photos/new-logo.png', false)->assertDontSee('madina-header-logo.png', false);
        $this->get('/a-page-that-does-not-exist')->assertNotFound()->assertSee('/branding/site-photos/new-logo.png', false)->assertDontSee('madina-header-logo.png', false);
    }

    public function test_portal_menus_receive_the_saved_name_and_logo(): void
    {
        SiteCustomization::save(['site_logo_url' => '/branding/site-photos/new-logo.png', 'contact_company_title' => 'Madina Herbal House']);
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->get('/company/website')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('siteBrand.logo', '/branding/site-photos/new-logo.png')
                ->where('siteBrand.name', 'Madina Herbal House'));
    }

    public function test_a_logo_link_that_is_not_safe_is_refused(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => ['site_logo_url' => 'javascript:alert(1)']])
            ->assertSessionHasErrors('customization.site_logo_url');
    }

    public function test_the_browser_icon_can_be_changed(): void
    {
        SiteCustomization::save(['site_favicon_image' => '/branding/site-photos/icon.png']);

        $this->get('/')->assertOk()->assertSee('<link rel="icon" href="/branding/site-photos/icon.png">', false)->assertDontSee('/reference/favicon.png', false);
    }

    public function test_share_wording_and_picture_can_be_changed_and_stay_built_in_when_empty(): void
    {
        $this->get('/')->assertOk()->assertSee('<meta property="og:title" content="'.e(__('site.meta_title_default')).'">', false);

        SiteCustomization::save(['meta_title' => 'Owner title', 'meta_description' => 'Owner description', 'og_image' => '/branding/site-photos/share.png']);

        $this->get('/')->assertOk()
            ->assertSee('<meta property="og:title" content="Owner title">', false)
            ->assertSee('<meta name="description" content="Owner description">', false)
            ->assertSee('/branding/site-photos/share.png', false);
    }

    public function test_menu_names_can_be_changed_in_the_phone_menus(): void
    {
        SiteCustomization::save([
            'header_home_title' => 'Start here',
            'header_services_title' => 'Care services',
            'header_partner_title' => 'Join us',
            'header_faq_title' => 'Your questions',
            'mobile_nav_products' => 'Shop',
            'mobile_nav_dealers' => 'Stores',
            'mobile_nav_about' => 'Story',
            'mobile_nav_account' => 'Me',
        ]);

        $response = $this->get('/')->assertOk();
        foreach (['Start here', 'Care services', 'Join us', 'Your questions', 'Shop', 'Stores', 'Story', 'Me'] as $name) {
            $response->assertSee($name);
        }
    }

    public function test_every_footer_link_can_be_renamed(): void
    {
        SiteCustomization::save([
            'footer_link_services' => 'Care we give',
            'footer_link_quality' => 'Our promise',
            'footer_link_partnership' => 'Work with us',
            'footer_link_faq' => 'Common questions',
            'footer_link_privacy' => 'Your privacy',
            'footer_link_terms' => 'The rules',
            'footer_link_notice' => 'News',
        ]);

        $response = $this->get('/')->assertOk();
        foreach (['Care we give', 'Our promise', 'Work with us', 'Common questions', 'Your privacy', 'The rules', 'News'] as $name) {
            $response->assertSee($name);
        }
    }

    public function test_printed_papers_only_use_a_logo_picture_inside_the_public_folder(): void
    {
        $this->assertNull(SiteCustomization::logoFilePath(), 'The factory logo keeps its own file on printed papers.');

        foreach (['/../.env', '/../composer.json', '/index.php', '/branding/not-there.png'] as $unsafe) {
            SiteCustomization::save(['site_logo_url' => $unsafe]);
            $this->assertNull(SiteCustomization::logoFilePath(), $unsafe.' must not be used.');
        }

        SiteCustomization::save(['site_logo_url' => '/madina-header-logo-white.png?v=2']);
        $this->assertSame(realpath(public_path('madina-header-logo-white.png')), SiteCustomization::logoFilePath());
    }

    public function test_the_old_fixed_logo_address_is_only_kept_as_the_factory_setting(): void
    {
        foreach (['reference/header.blade.php', 'reference/footer.blade.php', 'reference/layout.blade.php', 'reference/partials/page-boot.blade.php', 'reference/auth.blade.php', 'reference/partner-login.blade.php', 'company/auth.blade.php', 'errors/layout.blade.php'] as $view) {
            $this->assertStringNotContainsString('madina-header-logo', File::get(resource_path('views/'.$view)), "{$view} still has the logo typed in.");
        }
    }
}
