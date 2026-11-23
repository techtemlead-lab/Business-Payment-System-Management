<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every filter of a list is its own drop-down (the client asked for it after trying one-click chips).
 * These tests read the page sources, so a filter that quietly goes back to a row of chips, or loses
 * its phone grid, is caught here.
 */
class FilterDropdownsTest extends TestCase
{
    private function source(string $file): string
    {
        $text = file_get_contents(resource_path($file));
        $this->assertNotFalse($text, $file.' could not be read.');

        return (string) $text;
    }

    public function test_a_filter_is_a_native_select_that_applies_on_the_first_choice(): void
    {
        $filter = $this->source('js/components/FilterDropdown.tsx');

        $this->assertStringContainsString('<select', $filter);
        $this->assertStringContainsString('onChange(event.target.value)', $filter);
        $this->assertStringContainsString('<option value="">{allLabel}</option>', $filter);
        $this->assertStringContainsString("isOn ? 'is-on' : ''", $filter);
        $this->assertStringNotContainsString('aria-pressed', $filter, 'A filter is a drop-down, not a row of toggle buttons.');
        $this->assertStringNotContainsString('fc-chip', $filter);
    }

    public function test_the_old_chip_names_still_resolve_for_callers_that_use_them(): void
    {
        $filter = $this->source('js/components/FilterDropdown.tsx');

        $this->assertStringContainsString('export const FilterChips = FilterDropdown;', $filter);
        $this->assertStringContainsString('export type ChipOption = FilterOption;', $filter);
        $this->assertStringContainsString('export const ChipsUpTo = DropDownUpTo;', $filter);
        $this->assertFileDoesNotExist(resource_path('js/components/FilterChips.tsx'));
        $this->assertFileDoesNotExist(resource_path('css/screens-filter-chips.css'));
    }

    public function test_every_list_that_filtered_with_chips_now_imports_the_drop_down(): void
    {
        foreach ($this->callers() as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString("FilterDropdown'", $source, $file);
            $this->assertStringNotContainsString("FilterChips'", $source, $file);
        }
    }

    public function test_the_stylesheet_is_loaded_and_has_the_computer_row_and_the_phone_grid(): void
    {
        $this->assertStringContainsString("@import './screens-filter-dropdowns.css';", $this->source('css/dashboard.css'));

        $css = $this->source('css/screens-filter-dropdowns.css');

        // A computer: the name sits in front of the value, the active filter is drawn in the brand colour.
        $this->assertStringContainsString('.fd{display:inline-flex;', $css);
        $this->assertStringContainsString('.fd.is-on{border-color:var(--ds-brand', $css);
        // A phone: two boxes per row, each 44 px high with 16 px text.
        $this->assertStringContainsString('@media (max-width:767px){', $css);
        $this->assertStringContainsString('flex:1 1 calc(50% - 4px)', $css);
        $this->assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        $this->assertMatchesRegularExpression('/height:44px;[^}]*font-size:16px/', $css);
        // The dark theme follows the design-system tokens, not fixed colours.
        $this->assertStringNotContainsString('#fff;', str_replace('var(--ds-surface,#fff)', '', $css));
    }

    public function test_the_money_table_shows_its_drop_downs_under_the_search_box_on_a_phone(): void
    {
        $table = $this->source('js/components/MoneyTable.tsx');

        $this->assertStringContainsString('className="mt-filters mt-filters-phone"', $table);
        $this->assertStringContainsString('.mt-filters.mt-filters-phone', $this->source('css/screens-filter-dropdowns.css'));
        // The phone sheet is for sorting now; the drop-downs are on the page, not repeated inside it.
        $this->assertStringNotContainsString('className="mt-sheet-field"', $table);
        $this->assertStringNotContainsString('sf-main-field', $this->source('js/components/MoneyTableFilters.tsx'));
    }

    /** @return list<string> */
    private function callers(): array
    {
        return [
            'js/components/AreaDealersHub.tsx',
            'js/components/DataCleanupHub.tsx',
            'js/components/MoneyTable.tsx',
            'js/components/MoneyTableFilters.tsx',
            'js/components/NetworkTreeExplorer.tsx',
            'js/components/ServerListSearch.tsx',
            'js/components/TopSellersBoard.tsx',
            'js/components/UI.tsx',
            'js/components/office/operationsShared.tsx',
            'js/components/office/portalShared.tsx',
            'js/components/office/sections/Errors.tsx',
            'js/components/office/sections/staffSectionParts.tsx',
            'js/pages/TeamOrders.tsx',
        ];
    }
}
