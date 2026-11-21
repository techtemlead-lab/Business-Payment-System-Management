<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What the commission page shows, read straight from the live rules.
 *
 * It only reads: the rates come from CommissionPolicy::snapshot() and CommissionPolicy::rate(), the same
 * calls the order posting uses, so a number on the page is the number the engine pays with. Saving goes
 * through CommissionPolicy::update(), which keeps the versions, the owner's approval and the audit log.
 *
 * The engine holds ONE percent per commission head. A dealer and a sub-dealer who buy are paid the same
 * percent on the nine shared heads; a sub-dealer is paid three more heads on top (SubDealerKeys).
 */
final class CommissionPageModel
{
    /** Pool limits CommissionPolicy::validateSchedule() enforces, in basis points. */
    public const DealerCapBasisPoints = 4000;

    public const SubDealerCapBasisPoints = 5000;

    /** Heads paid only on a sub-dealer's own purchase. */
    public const SubDealerKeys = ['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'];

    /**
     * Heads that are split into parts. `param` is the rule parameter that holds the part's percent;
     * the parts must add up to the head's percent (CommissionPolicy::validateSchedule()).
     *
     * @var array<string, list<array{key: string, param: string}>>
     */
    private const Splits = [
        'd2d_multitier' => [['key' => 'gen2', 'param' => 'gen2_pct'], ['key' => 'gen3', 'param' => 'gen3_pct']],
        'area_dealer_pool' => [
            ['key' => 'management', 'param' => 'management_share_pct'], ['key' => 'division', 'param' => 'division_share_pct'],
            ['key' => 'district', 'param' => 'district_share_pct'], ['key' => 'upazila', 'param' => 'upazila_share_pct'],
            ['key' => 'union', 'param' => 'union_share_pct'],
        ],
        'rank_royalty' => [
            ['key' => 'mo', 'param' => 'mo_share_pct'], ['key' => 'am', 'param' => 'am_share_pct'],
            ['key' => 'sm', 'param' => 'sm_share_pct'], ['key' => 'me', 'param' => 'me_share_pct'],
        ],
    ];

    /**
     * Numbers behind each condition. `controls` says what the number looks at: the person's own purchase,
     * the team's purchase, what one team member can add, the area, a count of people, a length of time,
     * or the amount paid.
     *
     * @var list<array{id: string, rule: string, applies_to: list<string>, numbers: list<array{param: string, unit: string, controls: string}>}>
     */
    private const Conditions = [
        // Area income is paid once a month against the dealer's one monthly target (the salary condition below).
        ['id' => 'area', 'rule' => 'area_dealer_pool', 'applies_to' => ['dealer'], 'numbers' => [
            ['param' => 'held_share_months', 'unit' => 'months', 'controls' => 'time'],
        ]],
        // The dealer's one monthly target by kind of dealer: it unlocks the salary, the rank royalty and the area income.
        ['id' => 'salary', 'rule' => 'dealer_monthly_salary', 'applies_to' => ['dealer'], 'numbers' => [
            ['param' => 'union_target', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'upazila_target', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'district_target', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'division_target', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'min_monthly_volume', 'unit' => 'money', 'controls' => 'own'],
        ]],
        ['id' => 'sub_salary', 'rule' => 'sub_dealer_salary', 'applies_to' => ['sub_dealer'], 'numbers' => [
            ['param' => 'tier1_volume', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'tier1_pct', 'unit' => 'percent', 'controls' => 'own'],
            ['param' => 'tier2_volume', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'tier2_pct', 'unit' => 'percent', 'controls' => 'own'],
            ['param' => 'tier3_volume', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'tier3_pct', 'unit' => 'percent', 'controls' => 'own'],
        ]],
        ['id' => 'rank_join', 'rule' => 'rank_royalty', 'applies_to' => ['dealer', 'sub_dealer'], 'numbers' => [
            ['param' => 'mo_target', 'unit' => 'money', 'controls' => 'own'],
            ['param' => 'am_count', 'unit' => 'count', 'controls' => 'team_count'],
            ['param' => 'sm_count', 'unit' => 'count', 'controls' => 'team_count'],
            ['param' => 'me_count', 'unit' => 'count', 'controls' => 'team_count'],
            ['param' => 'qualification_depth', 'unit' => 'count', 'controls' => 'team_depth'],
        ]],
        // The rank royalty is paid at month end to rank holders who met their monthly target: a dealer's one target, or a
        // sub-dealer's own royalty target. The old rank-keeping minimums (rank_maintenance) only apply to older orders.
        ['id' => 'royalty', 'rule' => 'rank_royalty', 'applies_to' => ['dealer', 'sub_dealer'], 'numbers' => [
            ['param' => 'sub_dealer_royalty_target', 'unit' => 'money', 'controls' => 'own'],
        ]],
        ['id' => 'rank_bonus', 'rule' => 'rank_incentive', 'applies_to' => ['dealer', 'sub_dealer'], 'numbers' => [
            ['param' => 'mo_bonus', 'unit' => 'money', 'controls' => 'reward'],
            ['param' => 'am_bonus', 'unit' => 'money', 'controls' => 'reward'],
            ['param' => 'sm_bonus', 'unit' => 'money', 'controls' => 'reward'],
            ['param' => 'me_bonus', 'unit' => 'money', 'controls' => 'reward'],
        ]],
        ['id' => 'bike', 'rule' => 'car_bike_incentive', 'applies_to' => ['dealer', 'sub_dealer'], 'numbers' => [
            ['param' => 'bike_target', 'unit' => 'money', 'controls' => 'team'],
            ['param' => 'bike_leg_cap', 'unit' => 'money', 'controls' => 'each'],
            ['param' => 'bike_award_amount', 'unit' => 'money', 'controls' => 'reward'],
        ]],
        ['id' => 'car', 'rule' => 'car_bike_incentive', 'applies_to' => ['dealer', 'sub_dealer'], 'numbers' => [
            ['param' => 'car_target', 'unit' => 'money', 'controls' => 'team'],
            ['param' => 'car_leg_cap', 'unit' => 'money', 'controls' => 'each'],
            ['param' => 'car_award_amount', 'unit' => 'money', 'controls' => 'reward'],
        ]],
        ['id' => 'cooling_off', 'rule' => 'commission_cooling_off', 'applies_to' => ['dealer', 'sub_dealer'], 'numbers' => [
            ['param' => 'cooling_off_days', 'unit' => 'days', 'controls' => 'time'],
        ]],
        ['id' => 'sub_dealer_extras', 'rule' => 'sub_dealer_salary', 'applies_to' => ['sub_dealer'], 'numbers' => []],
    ];

    public function __construct(private CommissionPolicy $policies) {}

    /**
     * @param  array<string, mixed>|null  $snapshot  The live snapshot when the caller already has it.
     * @return array{heads: list<array<string, mixed>>, totals: array<string, mixed>, conditions: list<array<string, mixed>>, workflow: array<string, mixed>}
     */
    public function build(?User $viewer = null, ?array $snapshot = null): array
    {
        $snapshot ??= $this->policies->snapshot(false);
        $rules = $snapshot['rules'];

        return [
            'heads' => $this->heads($rules),
            'totals' => $this->totals($rules, $snapshot),
            'conditions' => $this->conditions($rules),
            'workflow' => $this->workflow($viewer),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public function heads(array $rules): array
    {
        $heads = [];
        foreach ([...CommissionPolicy::Keys, ...self::SubDealerKeys] as $key) {
            $rule = $rules[$key];
            $isSubDealerOnly = in_array($key, self::SubDealerKeys, true);
            $effective = CommissionPolicy::rate($rules, $key)->decimal();
            $children = $this->children($key, $rules);
            // Prize amounts listed under a head (rank prizes) are not a split of its percent, so they are not summed.
            $percentParts = array_values(array_filter($children, fn (array $child): bool => $child['unit'] === 'percent'));
            $childrenTotal = array_sum(array_map(fn (array $child): int => Percentage::from($child['pct'])->basisPoints, $percentParts));
            $heads[] = [
                'key' => $key,
                'kind' => (string) $rule['category'],
                'percentage' => (string) $rule['percentage'],
                'dealer_pct' => $isSubDealerOnly ? null : $effective,
                'sub_dealer_pct' => $effective,
                'applies_to' => $isSubDealerOnly ? ['sub_dealer'] : ['dealer', 'sub_dealer'],
                'is_active' => (bool) $rule['is_active'],
                'version' => (int) $rule['version'],
                'children' => $children,
                'children_total' => $percentParts === [] ? null : Percentage::fromBasisPoints($childrenTotal)->decimal(),
                'children_match' => $percentParts === [] || $childrenTotal === Percentage::from((string) $rule['percentage'])->basisPoints,
                'block' => $isSubDealerOnly ? 'sub_only' : 'shared',
                'destination' => $key === 'additional_rank' ? $this->extraDestination($rules) : null,
            ];
        }

        return $heads;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{key: string, param: ?string, unit: string, pct: string}> pct holds the percent, or the taka for a money part
     */
    private function children(string $key, array $rules): array
    {
        if (isset(self::Splits[$key])) {
            $parameters = $rules[$key]['parameters'];

            return array_map(fn (array $part): array => [
                'key' => $part['key'], 'param' => $part['param'], 'unit' => 'percent',
                'pct' => Percentage::from((string) $parameters[$part['param']])->decimal(),
            ], self::Splits[$key]);
        }
        if ($key === 'car_bike_incentive') {
            // The reserve is split between the bike and the car prizes by "bike_pool_share_pct"; the car gets the rest.
            $pools = CommissionPolicy::vehiclePoolRates($rules);

            return [
                ['key' => 'bike', 'param' => 'bike_pool_share_pct', 'unit' => 'percent', 'pct' => $pools['bike']->decimal()],
                ['key' => 'car', 'param' => null, 'unit' => 'percent', 'pct' => $pools['car']->decimal()],
            ];
        }
        if ($key === 'rank_incentive') {
            // The one-time prize of each rank, in taka. They are paid from this fund; they do not split its percent.
            $parameters = $rules[$key]['parameters'];

            return array_map(fn (string $rank): array => [
                'key' => $rank, 'param' => $rank.'_bonus', 'unit' => 'money',
                'pct' => Money::decimal(Money::cents((string) $parameters[$rank.'_bonus'])),
            ], ['mo', 'am', 'sm', 'me']);
        }

        return [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function totals(array $rules, array $snapshot): array
    {
        $dealer = 0;
        foreach (CommissionPolicy::Keys as $key) {
            $dealer += CommissionPolicy::rate($rules, $key)->basisPoints;
        }
        $sub = $dealer;
        foreach (self::SubDealerKeys as $key) {
            $sub += CommissionPolicy::rate($rules, $key)->basisPoints;
        }

        return [
            'dealer_pct' => $this->points($dealer),
            'sub_dealer_pct' => $this->points($sub),
            'dealer_cap_pct' => $this->points(self::DealerCapBasisPoints),
            'sub_dealer_cap_pct' => $this->points(self::SubDealerCapBasisPoints),
            'company_mrp_pct' => (string) ($snapshot['company_mrp_percent'] ?? ''),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function conditions(array $rules): array
    {
        $result = [];
        foreach (self::Conditions as $condition) {
            $parameters = $rules[$condition['rule']]['parameters'] ?? [];
            $result[] = [
                'id' => $condition['id'],
                'rule' => $condition['rule'],
                'applies_to' => $condition['applies_to'],
                'version' => (int) ($rules[$condition['rule']]['version'] ?? 1),
                'facts' => $condition['id'] === 'sub_dealer_extras' ? ['destination' => $this->extraDestination($rules)] : [],
                'numbers' => array_map(fn (array $number): array => [
                    ...$number,
                    'value' => array_key_exists($number['param'], $parameters) && $parameters[$number['param']] !== null && $parameters[$number['param']] !== ''
                        ? (string) $parameters[$number['param']] : null,
                ], $condition['numbers']),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function workflow(?User $viewer): array
    {
        return [
            'can_edit' => $viewer?->canAct('finance', 'edit') ?? false,
            // Only the owner's own change goes live at once; everyone else's is saved as a draft for the owner.
            'applies_at_once' => $viewer !== null && CommissionPolicy::approves($viewer),
            'drafts_waiting' => (int) DB::table('commission_policy_schedules')
                ->where('scope', 'global')->where('origin', 'rule_edit')->where('status', 'draft')->count(),
        ];
    }

    /**
     * How one order of this size splits among the commission heads, with the same percent and the same
     * half-up rounding the posting uses for each line. It posts nothing.
     *
     * @return array<string, mixed>
     */
    public function splitFor(string $buyerType, int $cashCents, ?int $mrpCents = null): array
    {
        $snapshot = $this->policies->snapshot(false);
        $rules = $snapshot['rules'];
        $lines = [];
        $total = 0;
        foreach ([...CommissionPolicy::Keys, ...($buyerType === 'sub_dealer' ? self::SubDealerKeys : [])] as $key) {
            $rate = CommissionPolicy::rate($rules, $key);
            $amount = $rate->of($cashCents, 'half_up');
            $total += $amount;
            $parts = [];
            foreach (array_filter($this->children($key, $rules), fn (array $child): bool => $child['unit'] === 'percent') as $child) {
                $parts[] = ['key' => $child['key'], 'pct' => $child['pct'], 'amount' => Money::decimal(Percentage::from($child['pct'])->of($cashCents, 'half_up'))];
            }
            $lines[] = ['key' => $key, 'pct' => $rate->decimal(), 'amount' => Money::decimal($amount), 'parts' => $rate->basisPoints > 0 ? $parts : []];
        }
        $companyPct = Percentage::from((string) $snapshot['company_mrp_percent']);
        $company = $mrpCents === null ? null : $companyPct->of($mrpCents, 'half_up');

        return [
            'buyer_type' => $buyerType,
            'amount' => Money::decimal($cashCents),
            'lines' => $lines,
            'total_amount' => Money::decimal($total),
            'company' => $company === null ? null : ['pct' => $companyPct->decimal(), 'mrp' => Money::decimal((int) $mrpCents), 'amount' => Money::decimal($company)],
            'left_amount' => $company === null ? null : $this->signed($cashCents - $total - $company),
        ];
    }

    /** An amount that may be below zero (what is left after the company share when the price is too low). */
    private function signed(int $cents): string
    {
        return ($cents < 0 ? '-' : '').Money::decimal(abs($cents));
    }

    /** @param array<string, array<string, mixed>> $rules */
    private function extraDestination(array $rules): string
    {
        $value = $rules['additional_rank']['parameters'][CommissionPolicy::ExtraDestinationParameter] ?? CommissionPolicy::ExtraDestinationDefault;

        return in_array($value, CommissionPolicy::ExtraDestinations, true) ? (string) $value : CommissionPolicy::ExtraDestinationDefault;
    }

    private function points(int $basisPoints): string
    {
        return Percentage::fromBasisPoints($basisPoints)->decimal();
    }
}
