<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPolicy;
use App\Support\Money;

/**
 * Read-only check of the car and bike reward fund against the current reward rule: does the money the
 * fund collects from a qualifier's team, at the target, cover the award? It changes nothing.
 *
 * The fund receives the rule's rate of every delivered, fully paid dealer order. A partner reaches a
 * target when the purchases of their team reach it, so at exactly the target the team has put in
 * target x rate. Everything else in the company's sales is extra and is shown only as the sales
 * needed in total (award / rate) before one award is paid for.
 */
final class RewardFundCheck
{
    public const Covers = 'covers';

    public const Short = 'short';

    public const NotSet = 'not_set';

    /**
     * @param  array<string, array<string, mixed>>|null  $rules  the rules to check; the live ones when null
     * @return array<string, array{target: string, rate: string, fund_at_target: string, award: ?string, status: string, shortfall: ?string, break_even_sales: ?string}>
     */
    public function check(?array $rules = null): array
    {
        $rules ??= app(CommissionPolicy::class)->snapshot(false)['rules'];
        $rate = CommissionPolicy::rate($rules, 'car_bike_incentive');
        $parameters = $rules['car_bike_incentive']['parameters'];
        $result = [];
        foreach (['bike', 'car'] as $program) {
            $target = Money::cents((string) $parameters[$program.'_target']);
            $fund = $rate->of($target, 'half_up');
            $awardValue = $parameters[$program.'_award_amount'] ?? null;
            $award = $awardValue === null || $awardValue === '' ? null : Money::cents((string) $awardValue);
            $status = $award === null ? self::NotSet : ($fund >= $award ? self::Covers : self::Short);
            $result[$program] = [
                'target' => Money::decimal($target),
                'rate' => $rate->decimal(),
                'fund_at_target' => Money::decimal($fund),
                'award' => $award === null ? null : Money::decimal($award),
                'status' => $status,
                'shortfall' => $status === self::Short ? Money::decimal($award - $fund) : null,
                // Sales needed in all before the fund holds one award: award / rate, rounded up to the paisa.
                'break_even_sales' => $award !== null && $rate->basisPoints > 0
                    ? Money::decimal((int) (intdiv($award * 10000 + $rate->basisPoints - 1, $rate->basisPoints)))
                    : null,
            ];
        }

        return $result;
    }
}
