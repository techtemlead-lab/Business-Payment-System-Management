<?php

namespace Tests\Unit;

use App\Domain\Finance\DistributionCalculator;
use App\Domain\Finance\Percentage;
use App\Domain\Finance\SalaryCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FinancialCalculationTest extends TestCase
{
    public function test_default_dealer_distribution_reconciles_to_the_purchase_value(): void
    {
        $result = (new DistributionCalculator)->calculate('200.00', 'dealer', '50.00');

        $this->assertSame('100.00', $result['purchase_value']);
        $this->assertSame('60.00', $result['company_share']);
        $this->assertSame('40.00', $result['distribution_total']);
        $this->assertSame('0.00', $result['unallocated']);
        $this->assertTrue($result['is_funded']);
    }

    public function test_subdealer_distribution_includes_salary_and_tour_once(): void
    {
        $result = (new DistributionCalculator)->calculate('200.00', 'sub_dealer', '40.00');

        $this->assertSame('120.00', $result['purchase_value']);
        $this->assertSame('60.00', $result['distribution_total']);
        $this->assertSame('50.00', $result['total_rate']);
        $this->assertSame(['category' => 'sub_dealer_salary', 'rate' => '3.00', 'amount' => '3.60'], $result['components'][10]);
        $this->assertSame(['category' => 'sub_dealer_tour', 'rate' => '3.00', 'amount' => '3.60'], $result['components'][11]);
        $this->assertFalse($result['posts_earnings']);
    }

    public function test_variable_discounts_preserve_the_unallocated_amount(): void
    {
        $result = (new DistributionCalculator)->calculate('200.00', 'dealer', '20.00');

        $this->assertSame('160.00', $result['purchase_value']);
        $this->assertSame('64.00', $result['distribution_total']);
        $this->assertSame('36.00', $result['unallocated']);
    }

    public function test_an_unfunded_schedule_reports_the_shortfall(): void
    {
        $result = (new DistributionCalculator)->calculate('200.00', 'sub_dealer', '50.00');

        $this->assertFalse($result['is_funded']);
        $this->assertSame('10.00', $result['shortfall']);
        $this->assertSame('0.00', $result['unallocated']);
    }

    public function test_custom_rates_replace_the_selected_category(): void
    {
        $result = (new DistributionCalculator)->calculate('200.00', 'dealer', '50.00', ['referral' => '5.00']);

        $this->assertSame('35.00', $result['distribution_total']);
        $this->assertSame('5.00', $result['unallocated']);
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedules_are_rejected(string $type, string $discount, array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DistributionCalculator)->calculate('200.00', $type, $discount, $overrides);
    }

    public static function invalidSchedules(): array
    {
        return [
            ['dealer', '19.99', []], ['dealer', '50.01', []], ['sr', '40.00', []],
            ['dealer', '50.00', ['referral' => '100.00']], ['dealer', '50.00', ['sub_dealer_tour' => '3.00']],
        ];
    }

    #[DataProvider('monthlyPurchases')]
    public function test_flat_salary_and_separate_tour_have_no_purchase_tiers(string $purchases, string $expected): void
    {
        $result = (new SalaryCalculator)->calculate($purchases);

        $this->assertSame('3.00', $result['salary_rate']);
        $this->assertSame($expected, $result['salary']);
        $this->assertSame($expected, $result['tour']);
    }

    public static function monthlyPurchases(): array
    {
        return [['0.00', '0.00'], ['999.00', '29.97'], ['10000.00', '300.00'], ['25000.00', '750.00'], ['40000.00', '1200.00'], ['50000.00', '1500.00']];
    }

    public function test_fractional_paisa_requires_an_explicit_rounding_rule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('approved rounding rule');

        Percentage::from('1.50')->of(100);
    }

    public function test_half_up_rounding_uses_integer_arithmetic(): void
    {
        $this->assertSame(2, Percentage::from('1.50')->of(100, 'half_up'));
        $this->assertSame(1, Percentage::from('1.00')->of(100, 'half_up'));
    }

    #[DataProvider('invalidPercentages')]
    public function test_invalid_percentages_are_not_silently_coerced(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Percentage::from($value);
    }

    public static function invalidPercentages(): array
    {
        return [['-1'], ['100.01'], ['3.001'], ['1e2'], [''], ['three']];
    }
}
