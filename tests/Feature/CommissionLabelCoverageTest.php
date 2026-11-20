<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerIncomeHeads;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FundCatalog;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every commission rule, fund, ledger category and income head must have a real name in both
 * languages on every path that shows it. A missing name would fall back to the code itself or to a
 * humanised code such as "Sub dealer extra fund", which is how old or odd names reach people.
 */
class CommissionLabelCoverageTest extends TestCase
{
    /** @var list<string> Commission allocation categories a ledger row, statement or report can carry. */
    public const Categories = [
        'own_dealer_cashback', 'direct_referral', 'd2d_gen2', 'd2d_gen3', 'area_division', 'area_district', 'area_upazila', 'area_union',
        'area_management', 'dealer_monthly_salary_pool', 'sub_dealer_salary', 'rank_royalty_pool', 'rank_incentive_pool', 'company_marketing_fund',
        'car_bike_incentive_fund', 'additional_rank_pool', 'sub_dealer_tour', 'sub_dealer_extra_fund', 'company_profit_fund', 'no_commission_to_company',
        'dealer_salary_settlement_reserve', 'sub_dealer_salary_settlement_reserve', 'rank_royalty_settlement_reserve',
        'direct_referral_breakage', 'd2d_gen2_breakage', 'd2d_gen3_breakage',
    ];

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return ['bn' => ['bn'], 'en' => ['en']];
    }

    #[DataProvider('locales')]
    public function test_every_rule_key_has_a_name_on_both_rule_paths(string $locale): void
    {
        app()->setLocale($locale);
        $missing = [];
        foreach (array_keys(CommissionPolicy::defaults()) as $key) {
            // The three operations rules are settings, not commissions, so the commission page has no head for them.
            $paths = in_array($key, ['commission_cooling_off', 'payout_tax_withholding', 'rank_maintenance'], true)
                ? ['portal.rule_name_'.$key]
                : ['portal.rule_name_'.$key, 'commission_page.head_'.$key];
            foreach ($paths as $path) {
                if (__($path) === $path) {
                    $missing[] = $path;
                }
            }
        }
        $this->assertSame([], $missing, "[$locale] rule keys without a name");
    }

    /**
     * The React tables and forms name a rule key or category with label() in UI.tsx, which tries these portal prefixes
     * and otherwise humanises the code ("Dealer monthly salary"). That is how an old name comes back.
     */
    #[DataProvider('locales')]
    public function test_the_react_label_path_names_every_rule_key_and_category_without_humanising(string $locale): void
    {
        app()->setLocale($locale);
        $prefixes = ['report_cat_', 'label_', 'acct_role_', 'staff_role_', 'settle_kind_', 'jr_account_', 'status_', ''];
        $missing = [];
        foreach ([...array_keys(CommissionPolicy::defaults()), ...self::Categories] as $code) {
            $found = false;
            foreach ($prefixes as $prefix) {
                if (__('portal.'.$prefix.$code) !== 'portal.'.$prefix.$code) {
                    $found = true;

                    break;
                }
            }
            if (! $found) {
                $missing[] = $code;
            }
        }
        $this->assertSame([], $missing, "[$locale] codes the React label() would humanise");
    }

    #[DataProvider('locales')]
    public function test_every_fund_key_has_a_name_on_every_fund_path(string $locale): void
    {
        app()->setLocale($locale);
        $missing = [];
        foreach (array_unique([...FundCatalog::keys(), ...CompanyFunds::keys()]) as $fund) {
            $name = FundCatalog::name($fund);
            if ($name === 'commission_page.fund_'.$fund || $name === '') {
                $missing[] = 'FundCatalog::name('.$fund.')';
            }
            // The fund-spending report and picker read funds.php; month pools are never spent by hand, so they have no entry there.
            foreach (CompanyFunds::isSpendable($fund) || $fund === 'sub_dealer_extra' ? ['funds.fund_'.$fund] : [] as $path) {
                if (__($path) === $path) {
                    $missing[] = $path;
                }
            }
        }
        $this->assertSame([], $missing, "[$locale] funds without a name");
    }

    #[DataProvider('locales')]
    public function test_every_category_has_a_name_on_every_category_path(string $locale): void
    {
        app()->setLocale($locale);
        $missing = [];
        foreach (self::Categories as $category) {
            if (FundCatalog::typeLabel($category) === $category) {
                $missing[] = 'FundCatalog::typeLabel('.$category.')';
            }
            if (CompanyFunds::sourceLabel('marketing', $category, 'active') === $category) {
                $missing[] = 'CompanyFunds::sourceLabel('.$category.')';
            }
            // The React tables name a code through portal.report_cat_<code> and otherwise humanise it.
            if (__('portal.report_cat_'.$category) === 'portal.report_cat_'.$category) {
                $missing[] = 'portal.report_cat_'.$category;
            }
            if (PartnerMoneyBook::headWord($category, $locale) === Str::headline($category)) {
                $missing[] = 'PartnerMoneyBook::headWord('.$category.')';
            }
        }
        $this->assertSame([], $missing, "[$locale] categories without a name");
    }

    #[DataProvider('locales')]
    public function test_every_income_head_has_a_name_and_hint(string $locale): void
    {
        app()->setLocale($locale);
        $missing = [];
        foreach ([...PartnerIncomeHeads::keys(), 'salary_sub'] as $head) {
            foreach (['cr21_income.head_'.$head, 'cr21_income.hint_'.($head === 'salary_sub' ? 'salary' : $head)] as $path) {
                if (__($path) === $path) {
                    $missing[] = $path;
                }
            }
        }
        $this->assertSame([], $missing, "[$locale] income heads without a name");
    }
}
