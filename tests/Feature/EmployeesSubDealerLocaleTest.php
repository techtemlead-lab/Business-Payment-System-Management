<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeesSubDealerLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClassicPlaceCodes();
        config(['madina.financial_activation' => false]);
    }

    public function test_employees_hub_exposes_bangla_modal_chrome_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/employees')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'employees')
                ->where('translations.portal.emp_create_title', 'নতুন কর্মচারীর ড্যাশবোর্ড অ্যাকাউন্ট')
                ->where('translations.portal.emp_save', 'অনুমতি সেভ')
                ->where('translations.portal.emp_preset_finance', 'হিসাব ও ফান্ড')
                ->where('data.available_permissions.0.label', 'মার্কেট সেলস ডেস্ক')
                ->where('data.available_permissions.0.category', 'মার্কেট সেলস')
                ->where('data.available_permissions.5.label', 'অর্ডার ও ডেলিভারি'));
    }

    public function test_website_customization_exposes_bangla_shell_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/website')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'website')
                ->where('translations.portal.cms_heading', 'ওয়েবসাইট ম্যানেজ')
                ->where('translations.portal.cms_tab_pages', 'পাবলিক পাতা')
                ->where('translations.portal.cms_save', 'সাইট সেটিং সেভ'));
    }

    public function test_sub_dealer_hub_exposes_bangla_chrome_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/sub-dealers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'sub-dealers')
                ->where('translations.portal.sd_heading', 'সাব-ডিলারের কাজ ও রেকর্ড')
                ->where('translations.portal.sd_salary_title', 'নিশ্চিত সাব-ডিলার মাসিক বেতন · ৩%')
                ->where('translations.portal.sd_policy_rule', 'নিয়ম:'));
    }

    public function test_payouts_hub_exposes_bangla_chrome_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/payouts-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'payouts-hub')
                ->where('translations.portal.pay_heading', 'টাকা তোলার অনুরোধ')
                ->where('translations.portal.pay_review_title', 'পার্টনারের টাকা তোলার অনুরোধ যাচাই')
                ->where('translations.portal.pay_record_ledger', 'খাতায় রেকর্ড করুন')
                ->where('translations.portal.pco_title', 'কাস্টম কমিশন প্ল্যান')
                ->where('translations.portal.pco_submit', 'পার্টনার কমিশন প্ল্যান প্রস্তাব'));
    }

    public function test_area_dealers_hub_exposes_bangla_chrome_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/area-dealers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'area-dealers')
                ->where('translations.portal.area_kpi_total', 'ডিলার')
                ->where('translations.portal.area_modal_title', 'এরিয়া ডিলার ঠিক করুন')
                ->where('translations.portal.area_save', 'এলাকার দায়িত্ব সেভ করুন'));
    }

    public function test_place_codes_hub_exposes_bangla_chrome_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/place-codes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'place-codes')
                ->where('translations.portal.pc_badge', 'প্লেস কোড')
                ->where('translations.portal.pc_save', 'কোড সেভ')
                ->where('translations.portal.pc_save_changes', 'পরিবর্তন সেভ'));
    }

    public function test_settlements_and_rewards_hubs_expose_bangla_chrome_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/settlements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'settlements')
                ->where('translations.portal.st_heading', 'মাস শেষের হিসাব ও পেমেন্ট')
                ->where('translations.portal.st_release', 'সময় হওয়া কমিশন ছাড়ুন')
                ->where('translations.portal.st_approve_pay', 'অনুমোদন দিয়ে টাকা দিন'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/treasury/funds/car_bike')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TreasuryFund')
                ->where('section', 'treasury')
                ->where('card.name', 'গাড়ি ও বাইক ফান্ড')
                ->where('translations.treasury.tab_funds', 'ফান্ড')
                ->where('translations.treasury.fund_car_bike', 'গাড়ি ও বাইক ফান্ড'));
    }
}
