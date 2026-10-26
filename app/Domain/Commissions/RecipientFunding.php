<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use Illuminate\Validation\ValidationException;

final class RecipientFunding
{
    public const Sources = ['own_dealer_cashback', 'direct_referral', 'd2d_gen2', 'd2d_gen3', 'area_division', 'area_district', 'area_upazila', 'area_union',
        'area_management', 'dealer_monthly_salary_pool', 'rank_royalty_pool', 'rank_incentive_pool', 'company_marketing_fund', 'car_bike_incentive_fund',
        'additional_rank_pool', 'sub_dealer_salary', 'sub_dealer_tour'];

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $snapshot @return array{rows: array, company: int, decisions: array} */
    public function apply(array $rows, array $snapshot, int $cash, int $company): array
    {
        $targets = [];
        foreach ($rows as $index => $row) {
            $override = $snapshot['recipient_overrides'][$row['user_id']] ?? null;
            if ($override && isset($override['rates'][$row['category']])) {
                $targets[$index] = ['schedule_id' => $override['schedule_id'], ...$override['rates'][$row['category']]];
            }
        }
        $decisions = [];
        foreach ($targets as $index => $override) {
            $row = $rows[$index];
            $rate = Percentage::from((string) $override['percentage']);
            $mode = $override['funding_mode'] ?? 'cap';
            $actual = $rate->of($cash, 'half_up');
            if ($mode === 'cap') {
                $actual = min($row['cents'], $actual);
            }
            $difference = $actual - $row['cents'];
            $decision = ['schedule_id' => $override['schedule_id'], 'user_id' => $row['user_id'], 'category' => $row['category'],
                'funding_mode' => $mode, 'before_cents' => $row['cents'], 'after_cents' => $actual, 'sources' => []];
            if ($difference > 0) {
                if ($mode === 'company') {
                    if ($difference > $company) {
                        throw ValidationException::withMessages(['funding' => __('portal.err_fund_company_share')]);
                    }
                    $company -= $difference;
                    $decision['sources']['company_share'] = $difference;
                } elseif ($mode === 'allocations') {
                    $sources = $override['funding_sources'] ?? [];
                    $basisPoints = [];
                    foreach ($sources as $category => $percentage) {
                        if (! in_array($category, self::Sources, true) || $category === $row['category']) {
                            throw ValidationException::withMessages(['funding' => __('portal.err_fund_category')]);
                        }
                        $basisPoints[$category] = Percentage::from((string) $percentage)->basisPoints;
                    }
                    $requiredRate = $rate->basisPoints - Percentage::from($row['rate_percentage'])->basisPoints;
                    if ($requiredRate <= 0 || array_sum($basisPoints) !== $requiredRate) {
                        throw ValidationException::withMessages(['funding' => __('portal.err_fund_pct_match')]);
                    }
                    $amounts = $this->split($difference, $basisPoints);
                    foreach ($amounts as $category => $amount) {
                        $sourceIndex = array_search($category, array_column($rows, 'category'), true);
                        if ($sourceIndex === false || isset($targets[$sourceIndex]) || $rows[$sourceIndex]['cents'] < $amount) {
                            throw ValidationException::withMessages(['funding' => __('portal.err_fund_unavailable')]);
                        }
                        $rows[$sourceIndex]['cents'] -= $amount;
                        $rows[$sourceIndex]['metadata']['funding_deductions'][] = ['recipient_schedule_id' => $override['schedule_id'], 'recipient_id' => $row['user_id'], 'amount_cents' => $amount];
                        $rows[$sourceIndex]['rate_percentage'] = $this->effectiveRate($rows[$sourceIndex]['cents'], $cash);
                        $decision['sources'][$category] = $amount;
                    }
                } else {
                    throw ValidationException::withMessages(['funding' => __('portal.err_fund_mode')]);
                }
            } elseif ($difference < 0) {
                $rows[] = [...$row, 'category' => $row['category'].'_override_reserve', 'user_id' => null, 'status' => 'reserved',
                    'cents' => -$difference, 'rate_percentage' => $this->effectiveRate(-$difference, $cash),
                    'metadata' => ['recipient_schedule_id' => $override['schedule_id'], 'retained_by' => 'company_override_reserve']];
            }
            $rows[$index]['cents'] = $actual;
            if ($difference !== 0 || $mode !== 'cap') {
                $rows[$index]['rate_percentage'] = $rate->decimal();
            }
            $rows[$index]['metadata']['recipient_schedule_id'] = $override['schedule_id'];
            $rows[$index]['metadata']['funding_decision'] = $decision;
            $decisions[] = $decision;
        }

        return ['rows' => $rows, 'company' => $company, 'decisions' => $decisions];
    }

    /** @param array<string, int> $weights @return array<string, int> */
    private function split(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $parts = [];
        $fractions = [];
        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($amount * $weight, $total);
            $fractions[$key] = ($amount * $weight) % $total;
        }
        arsort($fractions, SORT_NUMERIC);
        $remainder = $amount - array_sum($parts);
        foreach (array_keys($fractions) as $key) {
            if ($remainder-- <= 0) {
                break;
            }
            $parts[$key]++;
        }

        return $parts;
    }

    private function effectiveRate(int $amount, int $cash): string
    {
        return number_format(intdiv($amount * 10000 + intdiv($cash, 2), $cash) / 100, 2, '.', '');
    }
}
