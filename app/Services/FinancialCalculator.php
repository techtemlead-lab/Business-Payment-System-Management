<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FinancialCalculator
{
    public const REWARDS = [
        'Referral bonus' => 1000, 'Area dealer commission' => 1000, 'Dealer commission allocation' => 500,
        'Dealer salary allocation' => 200, 'Dealer-to-dealer allocation' => 300, 'Rank reward' => 400,
        'Rank-maintenance incentive' => 200, 'Car & motorcycle reward' => 200, 'Management & marketing' => 200,
    ];

    public static function definition(): array
    {
        return ['company_bps' => 3000, 'dealer_bps' => 5000, 'pool_bps' => 2000, 'rewards' => self::REWARDS];
    }

    public function parseMoney(string $money): int
    {
        if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/D', $money)) {
            throw ValidationException::withMessages(['mrp' => 'Enter a non-negative BDT amount with at most two decimal places.']);
        }
        [$whole,$fraction] = array_pad(explode('.', $money, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function preview(int $mrp, ?array $definition = null): array
    {
        $d = $definition ?? self::definition();
        $rates = [$d['company_bps'] ?? -1, $d['dealer_bps'] ?? -1, $d['pool_bps'] ?? -1];
        if ($mrp < 0 || $mrp > 99999999999 || count(array_filter($rates, fn ($v) => is_int($v) && $v >= 0 && $v <= 10000)) !== 3 || array_sum($rates) !== 10000) {
            throw ValidationException::withMessages(['rates' => 'Top-level allocations must total exactly 100%.']);
        }
        $rewardRates = $d['rewards'] ?? [];
        $referralRates = $d['referrals']['generation_bps'] ?? [0, 0, 0];
        if (count($referralRates) !== 3 || count(array_filter($referralRates, fn ($rate) => is_int($rate) && $rate >= 0 && $rate <= 10000)) !== 3) {
            throw ValidationException::withMessages(['referral_bps' => 'Enter a percentage between 0% and 100% for each of the three generations.']);
        }
        if (array_key_exists('Unallocated', $rewardRates) || count(array_filter($rewardRates, fn ($v) => is_int($v) && $v >= 0 && $v <= 10000)) !== count($rewardRates) || array_sum($rewardRates) > 10000) {
            throw ValidationException::withMessages(['rates' => 'Reward allocations cannot exceed 100% of the pool.']);
        }
        // Largest-remainder apportionment: every poisha is conserved, ties use stable input order.
        $top = $this->apportion($mrp, ['company' => $rates[0], 'dealer' => $rates[1], 'pool' => $rates[2]]);
        $rewardParts = $this->apportion($top['pool'], $rewardRates + ['Unallocated' => 10000 - array_sum($rewardRates)]);
        $rows = [];
        foreach ($rewardRates as $name => $bps) {
            $rows[] = ['name' => $name, 'bps' => $bps, 'amount_minor' => $rewardParts[$name], 'status' => 'pending_definition'];
        }

        return ['company_bps' => $rates[0], 'dealer_bps' => $rates[1], 'pool_bps' => $rates[2], 'mrp_minor' => $mrp, 'company_minor' => $top['company'], 'dealer_minor' => $top['dealer'], 'pool_minor' => $top['pool'],
            'allocated_minor' => array_sum($rewardParts) - $rewardParts['Unallocated'], 'unallocated_minor' => $rewardParts['Unallocated'],
            'allocated_bps' => array_sum($rewardRates), 'rewards' => $rows, 'preview_only' => true, 'referrals' => ['base' => 'pending_discussion', 'generation_bps' => $referralRates, 'posting_enabled' => false]];
    }

    private function apportion(int $amount, array $rates): array
    {
        $result = [];
        $remainders = [];
        foreach ($rates as $key => $bps) {
            $result[$key] = intdiv($amount * $bps, 10000);
            $remainders[$key] = ($amount * $bps) % 10000;
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum($result);
        foreach (array_keys($remainders) as $key) {
            if ($left-- <= 0) {
                break;
            } $result[$key]++;
        }

        return $result;
    }
}
