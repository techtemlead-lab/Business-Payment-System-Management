<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OfficeHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-11, CR-12 and CR-15: Settings as boxes with a line each, and one "More tools" page for the rarely used pages. */
class MoreAndSettingsBoxesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_settings_box_has_a_name_and_a_line_in_both_languages(): void
    {
        $owner = User::factory()->staff()->create();

        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $groups = OfficeHubs::settingsGroups($owner, 'sadmin', true);
            $this->assertNotEmpty($groups);
            foreach ($groups as $group) {
                foreach ($group['links'] as $link) {
                    $this->assertStringNotContainsString('office_menu.', $link['label'], $link['key']);
                    $this->assertStringNotContainsString('office_menu.', $link['description'], $link['key'].' '.$locale);
                    $this->assertNotSame('', $link['description']);
                }
            }
        }
    }

    public function test_the_owner_more_page_lists_boxes_with_descriptions(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/more')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'more')
                ->where('data.more_groups', function ($groups): bool {
                    $keys = collect($groups)->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
                    $text = collect($groups)->flatMap(fn ($group) => collect($group['links'])->pluck('description'))->all();

                    return in_array('schemes', $keys, true) && in_array('employees', $keys, true) && in_array('website', $keys, true)
                        && collect($text)->every(fn ($line) => $line !== '' && ! str_contains($line, 'office_menu.'));
                }));
    }

    public function test_prescriptions_stay_in_the_sidebar_and_not_on_the_more_page(): void
    {
        $owner = User::factory()->staff()->create();

        $keys = collect(OfficeHubs::moreGroups($owner, 'sadmin', true))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
        $this->assertNotContains('prescriptions', $keys);

        $menu = (string) file_get_contents(resource_path('js/shared/portalNav.ts'));
        $this->assertMatchesRegularExpression("/function staffMothers[\s\S]*?mother\('clinic', 'prescriptions'/", $menu);
    }

    public function test_a_box_shows_only_if_the_person_may_open_the_page(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $keys = collect(OfficeHubs::moreGroups($editor, 'manage', true))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();

        $this->assertContains('content', $keys);
        $this->assertNotContains('employees', $keys);
        $this->assertNotContains('payroll', $keys);
        $this->assertNotContains('access-management', $keys);
    }

    public function test_a_field_rep_sees_only_the_field_sales_box_and_partners_have_no_page(): void
    {
        $sr = User::factory()->staff('sr')->create();
        $dealer = User::factory()->dealer()->create();

        $keys = collect(OfficeHubs::moreGroups($sr, 'manage', true))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
        $this->assertSame(['field-sales'], $keys);
        $this->actingAs($dealer)->onPortal('dealer')->get('/more')->assertForbidden();
    }

    public function test_commission_rules_the_event_log_and_the_guide_are_boxes_on_the_other_modules_page(): void
    {
        app()->setLocale('bn');
        $owner = User::factory()->staff()->create();

        $groups = OfficeHubs::moreGroups($owner, 'sadmin', true);
        $keys = collect($groups)->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
        $this->assertContains('commissions', $keys);
        $this->assertContains('treasury-events', $keys);

        $events = collect($groups)->flatMap(fn ($group) => $group['links'])->firstWhere('key', 'treasury-events');
        $this->assertSame('/treasury/events', $events['href']);
        $this->assertSame('ঘটনার খাতা', $events['label']);
        $this->assertStringContainsString('অনুমোদন', $events['description']);

        $this->actingAs($owner)->onPortal('sadmin')->get($events['href'])->assertOk();
        $this->actingAs($owner)->onPortal('sadmin')->get('/commissions')->assertOk();
    }

    public function test_the_event_log_box_follows_the_treasury_event_access(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $editor = User::factory()->staff('editor')->create();

        $financeKeys = collect(OfficeHubs::moreGroups($finance, 'manage', true))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
        $editorKeys = collect(OfficeHubs::moreGroups($editor, 'manage', true))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();

        $this->assertContains('treasury-events', $financeKeys);
        $this->assertContains('commissions', $financeKeys);
        $this->assertNotContains('treasury-events', $editorKeys);
        $this->assertNotContains('commissions', $editorKeys);
        $this->actingAs($editor)->onPortal('manage')->get('/treasury/events')->assertForbidden();
    }

    public function test_the_entry_is_called_other_modules_in_both_languages_and_the_old_name_is_gone(): void
    {
        $this->assertSame('অন্যান্য মডিউল', trans('portal.nav_module_more', [], 'bn'));
        $this->assertSame('Other modules', trans('portal.nav_module_more', [], 'en'));

        $this->assertStringNotContainsString('আরও সুবিধা', (string) file_get_contents(lang_path('bn/manual.php')));
        $this->assertStringNotContainsString('More tools', (string) file_get_contents(lang_path('en/manual.php')));
        foreach (['bn', 'en'] as $locale) {
            $this->assertStringContainsString($locale === 'bn' ? 'অন্যান্য মডিউল' : 'Other modules', trans('manual.menu_office_s4', [], $locale));
        }
    }

    public function test_the_sidebar_loses_commission_rules_the_treasury_tab_and_the_office_guide_card(): void
    {
        $menu = (string) file_get_contents(resource_path('js/shared/portalNav.ts'));
        preg_match('/function staffMothers[\s\S]*?\n}\n/', $menu, $office);
        $this->assertStringNotContainsString("'commissions'", $office[0]);
        $this->assertMatchesRegularExpression("/mother\('clinic', 'prescriptions'/", $office[0]);

        $this->assertStringNotContainsString('/treasury/events', (string) file_get_contents(resource_path('js/components/treasury/TreasuryTabs.tsx')));
        $this->assertStringContainsString('{!staff && <div className="sidebar-footer">', (string) file_get_contents(resource_path('js/components/Shell.tsx')));
        $this->assertStringContainsString('more_title_guide', (string) file_get_contents(resource_path('js/components/office/MoreHome.tsx')));
        $this->assertSame('ব্যবহারের নির্দেশিকা ও ট্যুর', trans('cr15.more_title_guide', [], 'bn'));
    }

    public function test_the_new_boxes_have_text_in_both_languages(): void
    {
        $bangla = require lang_path('bn/cr15.php');
        $english = require lang_path('en/cr15.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach ($english as $key => $word) {
            $this->assertNotSame($word, $bangla[$key], $key);
        }
    }
}
