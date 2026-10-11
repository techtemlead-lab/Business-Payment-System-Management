<?php

namespace Tests\Unit;

use App\Services\FinancialCalculator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialCalculatorTest extends TestCase
{
    public function test_confirmed_model_and_reward_pool_match_bdt_200(): void
    {
        $result = (new FinancialCalculator)->preview(20000);
        $this->assertSame(6000, $result['company_minor']);
        $this->assertSame(10000, $result['dealer_minor']);
        $this->assertSame(4000, $result['pool_minor']);
        $this->assertSame([400, 400, 200, 80, 120, 160, 80, 80, 80], array_column($result['rewards'], 'amount_minor'));
        $this->assertSame(1600, $result['allocated_minor']);
        $this->assertSame(2400, $result['unallocated_minor']);
        $this->assertTrue($result['preview_only']);
        $this->assertArrayNotHasKey('dealer_level_split', $result);
    }

    #[DataProvider('amounts')]
    public function test_rounding_conserves_every_poisha(int $amount): void
    {
        $p = (new FinancialCalculator)->preview($amount);
        $this->assertSame($amount, $p['company_minor'] + $p['dealer_minor'] + $p['pool_minor']);
        $this->assertSame($p['pool_minor'], $p['allocated_minor'] + $p['unallocated_minor']);
        foreach ($p['rewards'] as $row) {
            $this->assertSame('pending_definition', $row['status']);
        }
    }

    public static function amounts(): array
    {
        return ['zero' => [0], 'one poisha' => [1], 'two poisha' => [2], 'rounding boundary' => [5], 'fraction' => [19999], 'large' => [99999999999]];
    }

    #[DataProvider('invalidMoney')]
    public function test_rejects_nondecimal_or_overprecision_money(string $input): void
    {
        $this->expectException(ValidationException::class);
        (new FinancialCalculator)->parseMoney($input);
    }

    public static function invalidMoney(): array
    {
        return [['-1'], ['1.001'], ['1e3'], ['NaN'], ['9999999999'], [''], ['0x10']];
    }

    public function test_parses_decimal_money_without_floating_point(): void
    {
        $this->assertSame(20001, (new FinancialCalculator)->parseMoney('200.01'));
        $this->assertSame(80, (new FinancialCalculator)->parseMoney('0.8'));
    }

    public function test_rejects_top_level_allocations_exceeding_mrp(): void
    {
        $this->expectException(ValidationException::class);
        (new FinancialCalculator)->preview(20000, [...FinancialCalculator::definition(), 'company_bps' => 3100]);
    }

    public function test_rejects_pool_allocations_exceeding_parent_pool(): void
    {
        $this->expectException(ValidationException::class);
        (new FinancialCalculator)->preview(20000, [...FinancialCalculator::definition(), 'rewards' => ['A' => 10000, 'B' => 1]]);
    }
}
