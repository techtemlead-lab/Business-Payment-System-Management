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
}
