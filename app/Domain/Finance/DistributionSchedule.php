<?php

namespace App\Domain\Finance;

use InvalidArgumentException;

final class DistributionSchedule
{
    /** @return array<string, string> */
    public static function defaults(string $accountType): array
    {
        if (! in_array($accountType, ['dealer', 'sub_dealer'], true)) {
            throw new InvalidArgumentException('Only dealer and sub_dealer schedules are supported.');
        }
        $rates = [
            'referral' => '10.00', 'area' => '10.00', 'own_dealer' => '5.00',
            'dealer_salary' => '2.00', 'rank_royalty' => '4.00', 'rank_incentive' => '2.00',
            'd2d' => '3.00', 'marketing' => '2.00', 'car_motorcycle' => '2.00',
        ];

        return $accountType === 'sub_dealer'
            ? [...$rates, 'additional_rank' => '4.00', 'sub_dealer_salary' => '3.00', 'sub_dealer_tour' => '3.00']
            : $rates;
    }
}
