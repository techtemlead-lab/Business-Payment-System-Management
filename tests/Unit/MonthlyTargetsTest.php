<?php

namespace Tests\Unit;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\MonthlyTargets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The owner's monthly targets (2026-11-21) worked out from one set of rules, with no database.
 */
class MonthlyTargetsTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function rules(): array
    {
        return CommissionPolicy::defaults();
    }

    public function test_the_new_defaults_are_the_owners_numbers(): void
    {
        $rules = $this->rules();

        $this->assertSame('500000.00', $rules['rank_royalty']['parameters']['mo_target']);
        $this->assertSame('30000.00', $rules['rank_royalty']['parameters']['sub_dealer_royalty_target']);
        $this->assertSame(['10000.00', '1.00', '20000.00', '2.00', '40000.00', '3.00'], array_values($rules['sub_dealer_salary']['parameters']));
        $this->assertSame('3.00', $rules['sub_dealer_salary']['percentage']);
        foreach (['mo', 'am', 'sm', 'me'] as $rank) {
            $this->assertSame('1.00', $rules['rank_royalty']['parameters'][$rank.'_share_pct'], 'Each rank has its own 1% fund.');
        }
    }

    /** @return array<string, array{0: ?string, 1: int}> */
    public static function dealerKinds(): array
    {
        return [
            'union dealer' => ['union', 3_000_000],
            'upazila (thana) dealer' => ['upazila', 5_000_000],
            'district dealer' => ['district', 10_000_000],
            'division dealer' => ['division', 10_000_000],
            'dealer with no area yet' => [null, 3_000_000],
        ];
    }

    #[DataProvider('dealerKinds')]
    public function test_a_dealer_has_one_monthly_target_by_kind(?string $kind, int $cents): void
    {
        $this->assertSame($cents, MonthlyTargets::dealerTarget($this->rules(), $kind));
        $this->assertSame($cents, MonthlyTargets::royaltyTarget($this->rules(), 'dealer', $kind), 'The same target gates the royalty.');
    }

    public function test_a_sub_dealers_royalty_target_is_his_own_thirty_thousand(): void
    {
        $this->assertSame(3_000_000, MonthlyTargets::royaltyTarget($this->rules(), 'sub_dealer', null));
        $this->assertSame(3_000_000, MonthlyTargets::royaltyTarget($this->rules(), 'sub_dealer', 'division'), 'A sub-dealer has no dealer kind.');
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function salaryTiers(): array
    {
        return [
            '৳9,999' => [999_900, 0],
            '৳10,000' => [1_000_000, 100],
            '৳19,999.99' => [1_999_999, 100],
            '৳20,000' => [2_000_000, 200],
            '৳39,999' => [3_999_900, 200],
            '৳40,000' => [4_000_000, 300],
            '৳1,00,000' => [10_000_000, 300],
        ];
    }

    #[DataProvider('salaryTiers')]
    public function test_a_sub_dealer_earns_the_tier_his_own_month_reached(int $volume, int $basisPoints): void
    {
        $this->assertSame($basisPoints, MonthlyTargets::salaryBasisPoints($this->rules(), $volume));
    }

    public function test_the_tier_share_of_a_fund_set_aside_at_three_percent(): void
    {
        // ৳39,999 bought: 3% set aside is ৳1,199.97; the 2% tier is ৳799.98 of it, the rest goes to the company.
        $this->assertSame(79_998, MonthlyTargets::tierShare(119_997, 200, 300));
        $this->assertSame(0, MonthlyTargets::tierShare(119_997, 0, 300));
        $this->assertSame(119_997, MonthlyTargets::tierShare(119_997, 300, 300));
        $this->assertSame(1, MonthlyTargets::tierShare(1, 200, 300), 'Half a paisa or more rounds up.');
        $this->assertSame(100, MonthlyTargets::tierShare(100, 400, 300), 'Never more than the fund.');
    }

    public function test_rules_captured_before_the_owners_rule_keep_the_old_behaviour(): void
    {
        $old = $this->rules();
        $old['dealer_monthly_salary']['parameters'] = ['min_monthly_volume' => '10000.00'];
        $old['sub_dealer_salary']['parameters'] = [];

        $this->assertFalse(MonthlyTargets::usesMonthlyTargets($old));
        $this->assertFalse(MonthlyTargets::subDealersEarnRanks($old));
        $this->assertSame(1_000_000, MonthlyTargets::dealerTarget($old, 'division'), 'One minimum for every dealer.');
        $this->assertNull(MonthlyTargets::royaltyTarget($old, 'dealer', 'union'), 'The per-rank keeping minimums apply instead.');
        $this->assertNull(MonthlyTargets::salaryBasisPoints($old, 0), 'The whole salary set aside was paid.');
        $this->assertTrue(MonthlyTargets::usesMonthlyTargets($this->rules()));
    }
}
