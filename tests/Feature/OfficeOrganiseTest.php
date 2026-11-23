<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The office panel keeps one tidy pattern for its controls: view options in one top-bar button, tab strips that
 * become a row with "More" or a drop-down, report periods as drop-downs, and secondary actions under "More".
 * These tests read the sources so a page does not quietly drift back to a crowded bar or a strip of chips.
 */
class OfficeOrganiseTest extends TestCase
{
    private function source(string $file): string
    {
        $text = file_get_contents(resource_path($file));
        $this->assertNotFalse($text, $file.' could not be read.');

        return (string) $text;
    }

    public function test_the_office_top_bar_keeps_language_text_size_theme_and_the_guide_in_one_button(): void
    {
        $shell = $this->source('js/components/Shell.tsx');
        $menu = $this->source('js/components/ViewOptionsMenu.tsx');

        $this->assertStringContainsString('{staff && <ViewOptionsMenu onOpenGuide={() => setManualOpen(true)} />}', $shell);
        $this->assertStringContainsString('{!staff && <LanguageSwitcher compact />}', $shell, 'Only partners keep the language switch in the bar.');
        foreach (['<LanguageSwitcher compact />', '<TextSizeToggle />', '<ThemeToggle />', 'onOpenGuide()'] as $part) {
            $this->assertStringContainsString($part, $menu);
        }
        $this->assertStringContainsString('profile-menu-phone-tools', $shell, 'Phones keep the same choices in the account menu.');
    }

    public function test_tab_strips_become_a_row_with_more_or_one_drop_down(): void
    {
        $tabs = $this->source('js/components/AdaptiveTabs.tsx');
        $hub = $this->source('js/components/OfficeHubTabs.tsx');
        $status = $this->source('js/components/office/portalShared.tsx');

        $this->assertStringContainsString('PHONE_SELECT_FROM', $tabs);
        $this->assertStringContainsString('<ActionMenu', $tabs);
        $this->assertStringContainsString('<select', $tabs);
        $this->assertStringContainsString('<AdaptiveTabs', $hub);
        $this->assertStringContainsString('<AdaptiveTabs', $status);
        $this->assertStringNotContainsString('scrollIntoView', $status, 'Status tabs no longer scroll sideways.');
    }

    public function test_report_periods_and_views_are_drop_downs_not_chip_rows(): void
    {
        foreach (['js/components/BranchPerformanceHub.tsx', 'js/components/office/sections/Visitors.tsx', 'js/components/office/sections/PartnerActivityList.tsx'] as $file) {
            $text = $this->source($file);
            $this->assertStringContainsString('<FilterDropdown', $text, $file);
            $this->assertStringNotContainsString('className="mt-view"', $text, $file);
        }
        $this->assertStringContainsString('views.length > 3', $this->source('js/components/office/sections/staffSectionParts.tsx'), 'More than three views become a drop-down.');
    }

    public function test_secondary_actions_sit_in_a_more_menu(): void
    {
        $this->assertStringContainsString('<ActionMenu', $this->source('js/pages/OrderDetail.tsx'));
        $this->assertStringContainsString('<ActionMenu', $this->source('js/pages/PartnerProfile.tsx'));
        $this->assertStringContainsString('<ActionMenu', $this->source('js/components/office/sections/Inventory.tsx'));
    }

    public function test_a_toolbar_shows_at_most_four_drop_downs_and_keeps_the_rest_under_more_filters(): void
    {
        $filters = $this->source('js/components/MoneyTableFilters.tsx');
        $table = $this->source('js/components/MoneyTable.tsx');

        $this->assertStringContainsString('TOOLBAR_FILTER_LIMIT = 4', $filters);
        $this->assertStringContainsString("filter.group !== 'more'", $filters);
        $this->assertStringContainsString('limitToolbarFilters(data.filters)', $table);
        $this->assertStringContainsString("column.detail ? 'mt-col-detail' : ''", $table);
    }

    public function test_the_new_stylesheet_is_loaded_last_and_the_new_words_exist_in_both_languages(): void
    {
        $css = $this->source('css/dashboard.css');
        $this->assertStringContainsString("@import './organise-office.css';", $css);
        $this->assertGreaterThan(strpos($css, "@import './mobile-brand-bars-portal.css';"), strpos($css, "@import './organise-office.css';"));

        foreach (['view_options', 'view_options_text', 'view_options_theme'] as $key) {
            $this->assertNotSame('office_shell.'.$key, __('office_shell.'.$key), $key);
            $this->assertNotSame(__('office_shell.'.$key, [], 'en'), __('office_shell.'.$key, [], 'bn'), $key);
        }
    }
}
