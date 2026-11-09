<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogStaffWebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function catalogOnlyStaff(): User
    {
        return User::factory()->staff('employee')->create(['profile' => ['permissions' => ['catalog']]]);
    }

    public function test_staff_with_only_the_catalog_permission_cannot_change_other_website_settings(): void
    {
        $before = SiteCustomization::all()['contact_company_title'];

        $this->actingAs($this->catalogOnlyStaff())->post('/commands/customization', ['customization' => [
            'contact_company_title' => 'Changed by catalog staff',
            'md_message_enabled' => '0',
        ]])->assertRedirect();

        $after = SiteCustomization::all();
        $this->assertSame($before, $after['contact_company_title']);
        $this->assertNotSame('0', (string) $after['md_message_enabled']);
    }

    public function test_catalog_staff_can_still_save_the_featured_products_block(): void
    {
        $this->actingAs($this->catalogOnlyStaff())->post('/commands/customization', ['customization' => [
            'featured_products_title' => 'Best sellers this month',
        ]])->assertRedirect();

        $this->assertSame('Best sellers this month', SiteCustomization::all()['featured_products_title']);
    }

    public function test_website_staff_can_still_change_any_setting(): void
    {
        $this->actingAs(User::factory()->staff('editor')->create())->post('/commands/customization', ['customization' => [
            'contact_company_title' => 'New company title',
        ]])->assertRedirect();

        $this->assertSame('New company title', SiteCustomization::all()['contact_company_title']);
    }
}
