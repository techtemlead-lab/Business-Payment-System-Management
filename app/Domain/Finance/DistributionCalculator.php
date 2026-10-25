<?php

namespace App\Domain\Finance;

use App\Support\Money;
use InvalidArgumentException;

final class DistributionCalculator
{
    /**
     * Preview allocations only. No recipient, wallet or policy is persisted or activated.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    public function calculate(string $mrp, string $accountType, string $discount, array $overrides = [], string $companyShare = '30.00', string $rounding = 'reject'): array
    {
        $amount = Money::cents($mrp);
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('portal.err_draft_mrp_positive'));
        }
        $discountRate = Percentage::from($discount);
        if ($discountRate->basisPoints < 2000 || $discountRate->basisPoints > 5000) {
            throw new InvalidArgumentException(__('portal.err_draft_discount_range'));
        }
        $rates = DistributionSchedule::defaults($accountType);
        if (array_diff_key($overrides, $rates) !== []) {
            throw new InvalidArgumentException(__('portal.err_draft_unsupported_category'));
        }
        $rates = array_replace($rates, $overrides);
        $totalRate = 0;
        foreach ($rates as $rate) {
            $totalRate += Percentage::from($rate)->basisPoints;
        }
        if ($totalRate > 10000) {
            throw new InvalidArgumentException(__('portal.err_draft_alloc_100'));
        }
        $purchaseRate = Percentage::from(Money::decimal(10000 - $discountRate->basisPoints));
        $purchase = $purchaseRate->of($amount, $rounding);
        $company = Percentage::from($companyShare)->of($amount, $rounding);
        $components = [];
        $distributed = 0;
        foreach ($rates as $key => $rate) {
            $percentage = Percentage::from($rate);
            $allocation = $percentage->of($purchase, $rounding);
            $distributed += $allocation;
            $components[] = ['category' => $key, 'rate' => $percentage->decimal(), 'amount' => Money::decimal($allocation)];
        }
        $residual = $purchase - $company - $distributed;

        return [
            'currency' => 'BDT', 'account_type' => $accountType, 'basis' => 'purchase_value',
            'mrp' => Money::decimal($amount), 'discount_percent' => $discountRate->decimal(),
            'purchase_value' => Money::decimal($purchase), 'company_share' => Money::decimal($company),
            'total_rate' => Money::decimal($totalRate), 'distribution_total' => Money::decimal($distributed),
            'unallocated' => Money::decimal(max(0, $residual)), 'shortfall' => Money::decimal(max(0, -$residual)),
            'is_funded' => $residual >= 0, 'rounding' => $rounding, 'components' => $components,
            'posts_earnings' => false,
        ];
    }
}
