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
                ->where('translations.portal.log_out', 'লগ আউট')
                ->where('translations.portal.brand_company_bar', 'মদিনা ন্যাচারাল হেলথ কেয়ার')
                ->where('translations.portal.brand_home_aria', 'মদিনা ন্যাচারাল হেলথ কেয়ার হোম'));
    }

    public function test_guest_can_switch_locale_on_staff_login_screen(): void
    {
        $this->onPortal('manage')->from('/login')->post('/locale', ['locale' => 'bn'])->assertRedirect('/login');
        $this->onPortal('manage')->get('/login')
            ->assertOk()
            ->assertSee('ভাষা', false)
            ->assertSee('স্টাফ সাইন ইন')
            ->assertSee('মদিনা ন্যাচারাল হেলথ কেয়ার', false);
    }

    public function test_dealer_login_uses_bangla_auth_ui_when_cms_holds_english_defaults(): void
    {
        $this->onPortal('dealer')->from('/login')->post('/locale', ['locale' => 'bn'])->assertRedirect('/login');
        $this->onPortal('dealer')->get('/login')
            ->assertOk()
            ->assertSee('অ্যাকাউন্ট লগইন', false)
            ->assertSee('স্বাগতম ফিরে এসেছেন', false)
            ->assertSee('ডিলার অ্যাকাউন্ট দরকার?', false)
            ->assertSee('কোম্পানির সাথে যোগাযোগ', false)
            ->assertDontSee('Account Login', false)
            ->assertDontSee('Need a dealer account?', false);
    }

    public function test_access_management_exposes_bangla_staff_access_copy(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'locale' => 'bn']);
        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/access-management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.sa_title', 'স্টাফ অ্যাকশন অনুমতি')
                ->where('translations.portal.sa_save_staff', 'স্টাফ অনুমতি সংরক্ষণ')
                ->where('translations.portal.sa_roles_title', 'কাস্টম স্টাফ রোল'));
    }

    public function test_portal_hosts_remain_isolated_by_role(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer']);
        $this->actingAs($dealer)->onPortal('subdealer')->get('/')->assertForbidden();
        $this->actingAs($dealer)->onPortal('manage')->get('/')->assertForbidden();
        $this->assertSame('http://dealer.example.test/', PortalHost::homeUrl($dealer));
    }

    public function test_partner_nav_is_grouped_into_mother_modules(): void
    {
        $this->assertSame(['overview'], PortalCatalog::nav('dealer')['home']);
        $this->assertSame(['orders', 'deliveries', 'dues', 'catalog'], PortalCatalog::nav('dealer')['commerce']);
        $this->assertSame(['earnings', 'full-report', 'withdrawals'], PortalCatalog::nav('dealer')['earnings']);
        $this->assertSame(['profile', 'security', 'notifications', 'circulars'], PortalCatalog::nav('dealer')['account']);
        $this->assertSame(['salary', 'earnings', 'full-report', 'withdrawals'], PortalCatalog::nav('subdealer')['earnings']);
        $this->assertSame(['profile', 'security', 'notifications', 'circulars'], PortalCatalog::nav('subdealer')['account']);
        $this->assertArrayNotHasKey('owner', PortalCatalog::nav('sadmin'));
        $this->assertSame(['accounts', 'partnerships'], PortalCatalog::nav('manage')['dealer_directory']);
    }

    public function test_mother_module_labels_are_available_in_english_and_bangla(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'locale' => 'en']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.nav_module_commerce', 'Commerce')
                ->where('translations.portal.nav_module_earnings', 'Earnings & payouts')
                ->where('translations.portal.download_join_poster', 'Download join poster')
                ->where('translations.portal.new_purchase_request', 'New purchase request'));

        $this->actingAs($dealer)->onPortal('dealer')->post('/locale', ['locale' => 'bn'])->assertRedirect();
        $this->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.nav_module_commerce', 'কমার্স')
                ->where('translations.portal.nav_module_earnings', 'আয় ও পেআউট')
                ->where('translations.portal.download_join_poster', 'জয়েন পোস্টার ডাউনলোড')
                ->where('translations.portal.new_purchase_request', 'নতুন ক্রয় অনুরোধ')
                ->where('translations.portal.change_password', 'পাসওয়ার্ড পরিবর্তন')
                ->where('translations.portal.ops_download_reports', 'আর্থিক প্রতিবেদন ডাউনলোড')
                ->where('translations.portal.ops_assign_territory', 'এলাকা নির্ধারণ')
                ->where('translations.portal.financial_readiness', 'উদ্বোধনী ব্যালেন্স পর্যালোচনা'));
    }

    public function test_manage_and_subdealer_dashboards_expose_bangla_chrome(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'locale' => 'bn']);
        $this->actingAs($staff)->onPortal('manage')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/manage/Dashboard')
                ->where('locale', 'bn')
                ->where('translations.portal.log_out', 'লগ আউট')
                ->where('translations.portal.nav_module_home', 'হোম')
                ->where('translations.portal.financial_readiness', 'উদ্বোধনী ব্যালেন্স পর্যালোচনা'));

        $subdealer = User::factory()->create(['role' => 'sub_dealer', 'locale' => 'bn']);
        $this->actingAs($subdealer)->onPortal('subdealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/subdealer/Dashboard')
                ->where('locale', 'bn')
                ->where('translations.portal.log_out', 'লগ আউট')
                ->where('translations.portal.nav_module_earnings', 'আয় ও পেআউট')
                ->where('translations.portal.change_password', 'পাসওয়ার্ড পরিবর্তন'));
    }
}
