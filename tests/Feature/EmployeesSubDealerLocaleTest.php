<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeesSubDealerLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlaceCodeSeeder::class);
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
                ->where('translations.portal.emp_create_title', 'নতুন কর্মচারী ড্যাশবোর্ড অ্যাকাউন্ট তৈরি')
                ->where('translations.portal.emp_save', 'পারমিশন সংরক্ষণ')
                ->where('translations.portal.emp_preset_finance', 'ফিন্যান্স ও ট্রেজারি')
                ->where('data.available_permissions.0.label', 'ফিল্ড সেলস ওয়ার্কস্পেস')
                ->where('data.available_permissions.0.category', 'ফিল্ড সেলস')
                ->where('data.available_permissions.6.label', 'অর্ডার ও ডেলিভারি'));
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
                ->where('translations.portal.cms_tab_pages', 'পাবলিক পেজ')
                ->where('translations.portal.cms_save', 'কাস্টমাইজেশন সংরক্ষণ'));
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
                ->where('translations.portal.sd_heading', 'সাব-ডিলার অপারেশন ও রেকর্ড')
                ->where('translations.portal.sd_salary_title', 'নিশ্চিত সাব-ডিলার মাসিক স্যালারি · ৩%')
                ->where('translations.portal.sd_policy_rule', 'নীতি নিয়ম:'));
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
                ->where('translations.portal.pay_heading', 'পার্টনার পেআউট ও ডিসবার্সমেন্ট হাব')
                ->where('translations.portal.pay_review_title', 'পার্টনার উত্তোলন অনুরোধ পর্যালোচনা')
                ->where('translations.portal.pay_record_ledger', 'রেকর্ড ও লেজার পোস্ট')
                ->where('translations.portal.pco_title', 'কাস্টম কমিশন প্ল্যান')
                ->where('translations.portal.pco_submit', 'পার্টনার কমিশন প্ল্যান প্রস্তাব'));
    }
}
