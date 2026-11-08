<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_each_audience_renders_its_own_dashboard_page(): void
    {
        $cases = [
            'dealer' => ['role' => 'dealer', 'page' => 'portals/dealer/Dashboard'],
            'subdealer' => ['role' => 'sub_dealer', 'page' => 'portals/subdealer/Dashboard'],
            'manage' => ['role' => 'finance', 'page' => 'portals/manage/Dashboard'],
            'sadmin' => ['role' => 'super_admin', 'page' => 'portals/sadmin/Dashboard'],
        ];
        foreach ($cases as $portal => $case) {
            $user = User::factory()->create(['role' => $case['role']]);
            $this->actingAs($user)->onPortal($portal)->get('/')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($case['page'])
                    ->where('portal', $portal)
                    ->where('portalCatalog.page', PortalCatalog::page($portal)));
        }
    }

    public function test_staff_cannot_place_partner_purchase_orders(): void
    {
        $staff = User::factory()->create(['role' => 'finance']);
        $this->actingAs($staff)->onPortal('manage')
            ->post('/commands/orders', ['items' => [['product_id' => 1, 'quantity' => 1]]])
            ->assertForbidden();
    }

    public function test_owner_contact_succeeds_on_sadmin_and_owner_is_blocked_from_manage(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'password' => 'SyntheticPassword123']);
        $this->actingAs($owner)->onPortal('manage')->get('/')->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')
            ->from('/security')
            ->post('/commands/owner/contact', [
                'email' => 'owner.next@example.test',
                'current_password' => 'SyntheticPassword123',
            ])->assertRedirect('/security');
    }

    public function test_locale_can_be_switched_to_bangla_and_persisted(): void
    {
        $user = User::factory()->create(['role' => 'dealer', 'locale' => 'en']);
        $this->actingAs($user)->onPortal('dealer')->post('/locale', ['locale' => 'bn'])->assertRedirect();
        $this->assertSame('bn', $user->fresh()->locale);
        $this->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('locale', 'bn')
                ->where('translations.portal.log_out', 'লগআউট')
                ->where('translations.portal.brand_company_bar', 'মদিনা ন্যাচারাল হেলথ কেয়ার')
                ->where('translations.portal.brand_home_aria', 'মদিনা ন্যাচারাল হেলথ কেয়ার হোম'));
    }

    public function test_guest_can_switch_locale_on_staff_login_screen(): void
    {
        $this->onPortal('manage')->from('/login')->post('/locale', ['locale' => 'bn'])->assertRedirect('/login');
        $this->onPortal('manage')->get('/login')
            ->assertOk()
            ->assertSee('ভাষা', false)
            ->assertSee('স্টাফ লগইন')
            ->assertSee('মদিনা ন্যাচারাল হেলথ কেয়ার', false);
    }

    public function test_dealer_login_uses_bangla_auth_ui_when_cms_holds_english_defaults(): void
    {
        $this->onPortal('dealer')->from('/login')->post('/locale', ['locale' => 'bn'])->assertRedirect('/login');
        $this->onPortal('dealer')->get('/login')
            ->assertOk()
            ->assertSee('অ্যাকাউন্ট লগইন', false)
            ->assertSee('আবার স্বাগতম', false)
            ->assertSee('ডিলার অ্যাকাউন্ট দরকার?', false)
            ->assertSee('কোম্পানির সাথে যোগাযোগ', false)
            ->assertSee('মদিনা ন্যাচারাল হেলথ কেয়ার', false)
            ->assertDontSee('Account Login', false)
            ->assertDontSee('Need a dealer account?', false)
            ->assertDontSee('Madina Natural Health Care', false)
            ->assertDontSee('Sub-dealer log in', false);
    }

    public function test_cross_portal_login_links_only_appear_on_owner_login(): void
    {
        $this->onPortal('sadmin')->get('/login')
            ->assertOk()
            ->assertSee('data-tour="owner-login-portals"', false)
            ->assertSee('Employee log in', false)
            ->assertSee('Dealer log in', false)
            ->assertSee('Sub-dealer log in', false);

        $this->onPortal('manage')->get('/login')
            ->assertOk()
            ->assertDontSee('data-tour="owner-login-portals"', false)
            ->assertDontSee('Owner log in', false)
            ->assertDontSee('Dealer log in', false)
            ->assertDontSee('Sub-dealer log in', false);

        $this->onPortal('dealer')->get('/login')
            ->assertOk()
            ->assertDontSee('Sub-dealer log in', false)
            ->assertDontSee('Owner log in', false);

        $this->onPortal('subdealer')->get('/login')
            ->assertOk()
            ->assertDontSee('Dealer log in', false)
            ->assertDontSee('Owner log in', false);
    }

    public function test_access_management_exposes_bangla_staff_access_copy(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'locale' => 'bn']);
        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/access-management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.sa_title', 'স্টাফ কাজের অনুমতি')
                ->where('translations.portal.sa_save_staff', 'স্টাফ অনুমতি সেভ')
                ->where('translations.portal.sa_roles_title', 'কাস্টম স্টাফ রোল'));
    }

    public function test_portal_hosts_remain_isolated_by_role(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer']);
        $this->actingAs($dealer)->onPortal('subdealer')->get('/')->assertForbidden();
        $this->actingAs($dealer)->onPortal('manage')->get('/')->assertForbidden();
        $this->assertSame('http://dealer.example.test/', PortalHost::homeUrl($dealer));
    }

    public function test_the_menu_lives_in_the_page_code_not_in_the_portal_config(): void
    {
        foreach (['dealer', 'subdealer', 'manage', 'sadmin'] as $portal) {
            $this->assertArrayNotHasKey('nav', PortalCatalog::definition($portal));
            $this->assertArrayNotHasKey('nav', PortalCatalog::inertiaPayload($portal));
            $this->assertContains('overview', PortalCatalog::inertiaPayload($portal)['sections']);
        }
        $this->assertContains('share-feedback', PortalCatalog::sections('dealer'));
        $this->assertContains('share-feedback', PortalCatalog::sections('subdealer'));
        $this->assertContains('login-rules', PortalCatalog::sections('sadmin'));
        $this->assertNotContains('login-rules', PortalCatalog::sections('manage'));
    }

    public function test_mother_module_labels_are_available_in_english_and_bangla(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'locale' => 'en']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.nav_module_commerce', 'My orders')
                ->where('translations.portal.nav_module_earnings', 'My money')
                ->where('translations.portal.nav_module_fulfillment', 'Orders and delivery')
                ->where('translations.portal.nav_module_system', 'System')
                ->where('translations.portal.search_menu', 'Search menu')
                ->where('translations.portal.download_join_poster', 'Download join poster')
                ->where('translations.portal.new_purchase_request', 'New order'));

        $this->actingAs($dealer)->onPortal('dealer')->post('/locale', ['locale' => 'bn'])->assertRedirect();
        $this->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.nav_module_commerce', 'আমার অর্ডার')
                ->where('translations.portal.nav_module_earnings', 'আমার টাকা')
                ->where('translations.portal.nav_module_fulfillment', 'অর্ডার ও ডেলিভারি')
                ->where('translations.portal.nav_module_system', 'সিস্টেম')
                ->where('translations.portal.search_menu', 'মেনু খুঁজুন')
                ->where('translations.portal.download_join_poster', 'জয়েন পোস্টার ডাউনলোড')
                ->where('translations.portal.new_purchase_request', 'নতুন অর্ডার')
                ->where('translations.portal.change_password', 'পাসওয়ার্ড পরিবর্তন')
                ->where('translations.portal.ops_download_reports', 'রিপোর্ট ডাউনলোড')
                ->where('translations.portal.ops_assign_territory', 'এলাকা নির্ধারণ')
                ->where('translations.portal.financial_readiness', 'শুরুর ব্যালেন্স যাচাই'));
    }

    public function test_manage_and_subdealer_dashboards_expose_bangla_chrome(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'locale' => 'bn']);
        $this->actingAs($staff)->onPortal('manage')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/manage/Dashboard')
                ->where('locale', 'bn')
                ->where('translations.portal.log_out', 'লগআউট')
                ->where('translations.portal.nav_module_home', 'হোম')
                ->where('translations.portal.financial_readiness', 'শুরুর ব্যালেন্স যাচাই'));

        $subdealer = User::factory()->create(['role' => 'sub_dealer', 'locale' => 'bn']);
        $this->actingAs($subdealer)->onPortal('subdealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/subdealer/Dashboard')
                ->where('locale', 'bn')
                ->where('translations.portal.log_out', 'লগআউট')
                ->where('translations.portal.nav_module_earnings', 'আমার টাকা')
                ->where('translations.portal.change_password', 'পাসওয়ার্ড পরিবর্তন'));
    }
}
