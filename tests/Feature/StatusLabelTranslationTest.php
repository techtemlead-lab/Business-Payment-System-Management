<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Finance\ImportWorkbook;
use App\Domain\Integrations\IntegrationCatalog;
use Tests\TestCase;

/**
 * Status, role and money-category codes reach badges and tables through
 * label() in resources/js/components/UI.tsx. Each known code needs a plain
 * word in both languages so nobody sees "partially_settled" on screen.
 */
class StatusLabelTranslationTest extends TestCase
{
    /** @var list<string> */
    private const KnownStatuses = [
        'active', 'inactive', 'suspended', 'draft', 'published', 'new', 'contacted', 'closed', 'resolved', 'in_progress',
        'pending', 'requested', 'submitted', 'processing', 'approved', 'verified', 'rejected', 'cancelled', 'held',
        'paid', 'unpaid', 'dispatched', 'partially_dispatched', 'delivered',
        'reserved', 'available', 'maturing', 'retained', 'reversed', 'settled', 'partially_settled', 'rolled_over',
        'qualified', 'grace_period', 'demoted', 'applied', 'queued', 'preview', 'invalid', 'failed', 'passed', 'needs_review',
    ];

    public function test_every_known_status_has_a_plain_label_in_both_languages(): void
    {
        foreach (self::KnownStatuses as $status) {
            $this->assertPlainLabel('portal.status_'.$status);
        }
    }

    public function test_every_role_has_a_plain_label_in_both_languages(): void
    {
        $roles = [...array_keys(config('madina.roles')), 'employee', 'dealer', 'sub_dealer'];

        foreach ($roles as $role) {
            $this->assertPlainLabel('portal.staff_role_'.$role);
        }
    }

    public function test_every_commission_money_category_has_a_plain_label_in_both_languages(): void
    {
        $categories = [
            CommissionSettlement::DealerSalaryReserve,
            CommissionSettlement::SubDealerSalaryReserve,
            CommissionSettlement::RankRoyaltyReserve,
            CommissionSettlement::CompanyProfitCategory,
            RetainedFundLedger::BenefitsCategory,
            'company_share',
            'direct_referral', 'direct_referral_breakage', 'd2d_gen2', 'd2d_gen3', 'd2d_gen2_breakage', 'd2d_gen3_breakage',
            'own_dealer_cashback', 'area_division', 'area_district', 'area_upazila', 'area_union', 'area_management',
            'dealer_monthly_salary_pool', 'sub_dealer_salary', 'sub_dealer_tour', 'additional_rank_pool',
            'rank_royalty_pool', 'rank_incentive_pool', 'car_bike_incentive_fund', 'company_marketing_fund', 'opening_hold',
        ];

        foreach ($categories as $category) {
            $this->assertPlainLabel('portal.report_cat_'.$category);
        }
        foreach (['en', 'bn'] as $locale) {
            $this->assertStringContainsString(':category', __('portal.report_cat_override_reserve', [], $locale));
        }
    }

    public function test_commission_settings_import_columns_and_service_fields_have_labels(): void
    {
        foreach (CommissionPolicy::defaults() as $rule) {
            foreach ($rule['parameters'] ?? [] as $parameter => $value) {
                if (is_array($value) || $parameter === 'dynamic_compression') {
                    continue;
                }
                $this->assertPlainLabel('portal.cs_param_'.$parameter);
            }
        }
        foreach (ImportWorkbook::Columns as $type => $columns) {
            $this->assertPlainLabel('portal.imp_type_opt_'.$type);
            foreach ($columns as $column) {
                $this->assertPlainLabel('portal.imp_field_'.$column);
            }
        }
        foreach (IntegrationCatalog::groups() as $group) {
            foreach ($group['providers'] as $provider) {
                foreach ($provider['fields'] as $field) {
                    if ($field !== 'enabled') {
                        $this->assertPlainLabel('portal.si_field_'.$field);
                    }
                }
            }
        }
    }

    private function assertPlainLabel(string $key): void
    {
        foreach (['en', 'bn'] as $locale) {
            $value = __($key, [], $locale);
            $this->assertNotSame($key, $value, "Missing $locale translation for $key");
            $this->assertDoesNotMatchRegularExpression('/[a-z]_[a-z]/', $value, "$locale $key shows a code: $value");
        }
    }
}
