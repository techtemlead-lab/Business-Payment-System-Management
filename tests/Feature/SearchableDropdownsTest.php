<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A long list is picked from a drop-down that opens with a search box (SearchSelect), not from
 * a plain <select> the person has to scroll. These tests read the page sources, so a long list
 * that quietly goes back to a plain drop-down is caught here.
 */
class SearchableDropdownsTest extends TestCase
{
    private function source(string $file): string
    {
        $text = file_get_contents(resource_path('js/'.$file));
        $this->assertNotFalse($text, $file.' could not be read.');

        return (string) $text;
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function longListPickers(): array
    {
        return [
            'prescription: clinic product and dispense branch' => ['components/PrescriptionsHub.tsx', 2],
            'prescription: patient and place codes' => ['components/PrescriptionPatientStep.tsx', 2],
            'prescription: medicine template' => ['components/PrescriptionTemplates.tsx', 1],
            'prescription: food template' => ['components/PrescriptionExtras.tsx', 1],
            'account opening: division, district, upazila, union, sponsor' => ['pages/AccountCreate.tsx', 5],
            'place codes: referral place, parents, base place' => ['components/PlaceCodesHub.tsx', 4],
            'purchasing: supplier, branch, product' => ['components/PurchasingHub.tsx', 3],
            'order form: category, sub-category, product' => ['components/ProductPurchaseMatrix.tsx', 3],
            'product editor: category, sub-category, unit' => ['pages/ProductEditor.tsx', 3],
            'new product: category, sub-category, unit' => ['components/ProductCreateModal.tsx', 1],
            'catalog: parent category' => ['components/CatalogTaxonomyHub.tsx', 1],
            'schemes: free product' => ['components/dealertools/SchemesHub.tsx', 1],
            'campaign offers: card product and bundle product' => ['components/website/CampaignOffersManager.tsx', 2],
            'featured order: product' => ['components/website/FeaturedOrderList.tsx', 1],
            'featured products: product' => ['components/website/FeaturedProductsManager.tsx', 1],
            'area dealers: dealer and place' => ['components/AreaDealersHub.tsx', 1],
            'team orders: team member' => ['pages/TeamOrders.tsx', 1],
            'staff access: staff member' => ['components/StaffAccessEditor.tsx', 1],
            'branches: staff member' => ['components/BranchesHub.tsx', 1],
            'branch performance: branch' => ['components/BranchPerformanceHub.tsx', 1],
            'stock count: branch' => ['components/StockCountHub.tsx', 1],
            'courier areas: city, zone, area' => ['pages/CourierAreas.tsx', 1],
            'fund spend: expense head' => ['components/treasury/FundSpendForm.tsx', 1],
            'budgets: expense head' => ['components/treasury/HeadsAndBudgets.tsx', 1],
        ];
    }

    #[DataProvider('longListPickers')]
    public function test_a_long_list_is_picked_from_the_searchable_drop_down(string $file, int $pickers): void
    {
        $this->assertGreaterThanOrEqual($pickers, substr_count($this->source($file), '<SearchSelect'), $file);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function sharedFormHelpers(): array
    {
        return [
            'ActionForm field and Table filter' => ['components/UI.tsx', 2],
            'money table toolbar filter' => ['components/MoneyTable.tsx', 1],
            'money table filter sheet' => ['components/MoneyTableFilters.tsx', 1],
            'server list search' => ['components/ServerListSearch.tsx', 1],
            'settings form' => ['components/office/sections/SettingsForm.tsx', 1],
            'staff action form' => ['components/office/sections/staffSectionParts.tsx', 1],
            'operation form' => ['components/office/operationsShared.tsx', 1],
            'office list extra filters' => ['components/office/portalShared.tsx', 1],
        ];
    }

    #[DataProvider('sharedFormHelpers')]
    public function test_a_shared_form_helper_switches_to_the_searchable_drop_down_by_list_size(string $file, int $pickers): void
    {
        $this->assertGreaterThanOrEqual($pickers, substr_count($this->source($file), '<AutoSelect'), $file);
    }

    public function test_a_list_gets_the_search_box_from_nine_rows_and_stays_a_plain_drop_down_below_that(): void
    {
        $source = $this->source('components/SearchSelect.tsx');

        $this->assertStringContainsString('export const LongListFrom = 9;', $source);
        $this->assertStringContainsString('props.options.length >= LongListFrom', $source);
    }

    public function test_choosing_several_products_for_a_scheme_uses_the_searchable_tick_list(): void
    {
        $source = $this->source('components/dealertools/SchemesHub.tsx');

        $this->assertStringContainsString('<SearchCheckList', $source);
        $this->assertStringNotContainsString('<select multiple', $source);
    }

    public function test_the_prescription_form_no_longer_has_a_separate_search_box_beside_the_patient_list(): void
    {
        $this->assertStringNotContainsString('type="search"', $this->source('components/PrescriptionPatientStep.tsx'));
    }

    public function test_the_search_box_wording_exists_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            foreach (['search_select_search', 'search_select_none', 'search_select_more', 'search_select_loading'] as $key) {
                $this->assertNotSame('portal.'.$key, __('portal.'.$key, [], $locale), $locale.' '.$key);
            }
        }
    }
}
