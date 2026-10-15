<?php

namespace App\Domain\Finance;

use App\Support\Money;

final class SalaryCalculator
{
    /**
     * Salary and tour are separate accruals, not an extra payment on top of distribution.
     *
     * @return array<string, string|bool>
     */
    public function calculate(string $monthlyPurchases, string $rounding = 'reject'): array
    {
        $purchases = Money::cents($monthlyPurchases);
        $amount = Percentage::from('3.00')->of($purchases, $rounding);

        return [
            'currency' => 'BDT', 'purchase_value' => Money::decimal($purchases),
            'salary_rate' => '3.00', 'salary' => Money::decimal($amount),
            'tour_rate' => '3.00', 'tour' => Money::decimal($amount),
            'rounding' => $rounding, 'posts_earnings' => false,
        ];
    }
}
