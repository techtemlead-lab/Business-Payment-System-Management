<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OfficeHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-11 and CR-12: Settings as boxes with a line each, and one "More tools" page for the rarely used pages. */
class MoreAndSettingsBoxesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_field_rep_sees_only_the_field_sales_box_and_a_partner_sees_the_partner_page(): void
    {
        $sr = User::factory()->staff('sr')->create();
        $dealer = User::factory()->dealer()->create();

        $keys = collect(OfficeHubs::moreGroups($sr, 'manage', true))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
        $this->assertSame(['field-sales'], $keys);
        $this->actingAs($dealer)->onPortal('dealer')->get('/more')->assertOk()->assertInertia(fn (Assert $page) => $page->where('section', 'more'));
    }
}
