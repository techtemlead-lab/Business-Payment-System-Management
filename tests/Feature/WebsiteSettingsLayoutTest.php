<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Website settings are organised as five main areas, with a sub-module for every
 * public page and page part. The screen itself was checked in a browser; these tests
 * stop a page or a label from going missing.
 */
class WebsiteSettingsLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function registry(): string
    {
        return File::get(resource_path('js/components/website/modules/moduleRegistry.ts'));
    }

    /** @return list<string> */
    private function moduleIds(): array
    {
        preg_match_all("/\{ id: '([a-z_]+)', area: /", $this->registry(), $matches);

        return $matches[1];
    }

    public function test_the_page_has_one_main_area_for_the_website_and_each_portal(): void
    {
        $registry = $this->registry();

        foreach (['public', 'admin', 'staff', 'dealer', 'subdealer'] as $area) {
            $this->assertStringContainsString("{ id: '{$area}', icon:", $registry, "The {$area} area is missing.");
        }
    }

    public function test_every_public_page_and_page_part_has_its_own_sub_module(): void
    {
        $registry = $this->registry();

        foreach (['/', '/products', '/about', '/contact', '/dealers', '/services', '/partnership', '/faq', '/quality', '/notice', '/verify-dealer', '/grievance', '/feedback', '/prescribers', '/privacy', '/terms', '/refund-policy', '/delivery-policy', '/code-of-conduct', '/partner-exit-policy', '/quality-documents', '/articles'] as $path) {
            $this->assertStringContainsString("slug: '{$path}'", $registry, "{$path} has no sub-module.");
        }
        foreach (['company', 'header', 'navbar', 'footer'] as $part) {
            $this->assertContains($part, $this->moduleIds(), "{$part} has no sub-module.");
        }
    }

    public function test_every_sub_module_and_area_has_a_name_in_both_languages(): void
    {
        $english = require lang_path('en/website_admin.php');
        $bangla = require lang_path('bn/website_admin.php');
        $keys = [];
        foreach ($this->moduleIds() as $id) {
            $keys[] = "module_{$id}";
            $keys[] = "module_{$id}_hint";
        }
        foreach (['public', 'admin', 'staff', 'dealer', 'subdealer'] as $area) {
            $keys[] = "main_{$area}";
            $keys[] = "main_{$area}_hint";
        }

        foreach ($keys as $key) {
            $this->assertNotSame('', trim((string) ($english[$key] ?? '')), "English {$key} is missing.");
            $this->assertNotSame('', trim((string) ($bangla[$key] ?? '')), "Bangla {$key} is missing.");
        }
    }

    public function test_the_page_receives_the_policy_text_for_staff_who_may_change_it(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->get('/company/website')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'website')
                ->has('data.trust_editor.drafts.en.refund_title')
                ->has('data.trust_editor.values.trust_trade_licence'));
    }

    public function test_policy_text_saves_with_the_rest_of_the_page(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'trust_refund_title' => 'Owner refund heading',
            'trust_trade_licence' => 'TL-12345',
            'page_enabled_refund-policy' => '0',
            'contact_phone' => '01900-000000',
        ]])->assertSessionHasNoErrors();

        $saved = SiteCustomization::raw();
        $this->assertSame('Owner refund heading', $saved['trust_refund_title']);
        $this->assertSame('TL-12345', $saved['trust_trade_licence']);
        $this->assertSame('0', $saved['page_enabled_refund-policy']);
        $this->assertSame('01900-000000', $saved['contact_phone']);
    }

    public function test_policy_text_keeps_its_length_limit_on_the_main_save(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'trust_refund_title' => str_repeat('a', 2001),
        ]])->assertSessionHasErrors('customization.trust_refund_title');
    }

    public function test_only_staff_who_may_change_website_text_can_save_the_page(): void
    {
        $support = User::factory()->staff('support')->create();

        $this->actingAs($support)->post('/commands/customization', ['customization' => ['contact_phone' => '01900-000000']])
            ->assertForbidden();
        $this->assertArrayNotHasKey('contact_phone', SiteCustomization::raw());
    }
}
