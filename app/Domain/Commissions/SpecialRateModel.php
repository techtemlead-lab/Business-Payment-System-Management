<?php

namespace App\Domain\Commissions;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Finance\Percentage;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the "one person's special rate" page shows. It only reads.
 *
 * Today the engine can give one person two kinds of special rate, both through commission_policy_schedules:
 * - the receiver side (scope "recipient"): what the person EARNS on eight commissions, with the extra paid from the
 *   company share or from other commissions;
 * - the buyer side (scope "buyer"): the whole plan on the person's own PURCHASES.
 * A new schedule of a kind replaces the earlier one of that kind, so the page always sends the full set.
 *
 * Conditions are different. The engine has no dated or per-condition waiver. It has three per-person switches the
 * owner sets (PartnerBenefitControls): no monthly purchase minimum for the dealer salary pool, no conditions for
 * the rank royalty, and no conditions for the rank prizes. The page shows exactly those and says plainly that the
 * other conditions are the same for everyone.
 */
final class SpecialRateModel
{
    /**
     * The commissions a person's own earnings rate can be changed for: category => [rule, parameter holding the
     * general rate or null when the rule's own percent is the rate].
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    public const ReceiverLines = [
        'own_dealer_cashback' => ['own_dealer_cashback', null],
        'direct_referral' => ['direct_referral', null],
        'd2d_gen2' => ['d2d_multitier', 'gen2_pct'],
        'd2d_gen3' => ['d2d_multitier', 'gen3_pct'],
        'area_division' => ['area_dealer_pool', 'division_share_pct'],
        'area_district' => ['area_dealer_pool', 'district_share_pct'],
        'area_upazila' => ['area_dealer_pool', 'upazila_share_pct'],
        'area_union' => ['area_dealer_pool', 'union_share_pct'],
    ];

    /**
     * Conditions the owner can lift for one person today, and the switch that does it.
     *
     * @var array<string, string>
     */
    public const Waivable = [
        'area' => 'unconditional_salary',
        'salary' => 'unconditional_salary',
        'sub_salary' => 'unconditional_salary',
        'rank_join' => 'unconditional_rank_royalty',
        'rank_keep' => 'unconditional_rank_royalty',
        'rank_bonus' => 'unconditional_rank_incentive',
    ];

    public function __construct(private CommissionPolicy $policies, private CommissionPageModel $page) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $viewer, ?User $partner): array
    {
        $snapshot = $this->policies->snapshot(false);
        $rules = $snapshot['rules'];
        $general = $this->page->build($viewer, $snapshot);
        $isOwner = CommissionPolicy::approves($viewer);
        $result = [
            'workflow' => [
                'can_propose' => $viewer->canAct('finance', 'create'),
                'is_owner' => $isOwner,
                'applies_at_once' => $isOwner,
                'can_set_conditions' => $viewer->owner(),
            ],
            'receiver_lines' => $this->receiverGeneral($rules),
            'heads' => $general['heads'],
            'totals' => $general['totals'],
            'conditions' => $general['conditions'],
            'waivable' => self::Waivable,
            'customizable' => $this->customizable($rules, $partner),
            'sub_dealer_salary_max_pct' => CommissionPolicy::rate($rules, 'sub_dealer_salary')->decimal(),
            'funding_sources' => RecipientFunding::Sources,
            'person' => null,
        ];
        if ($partner === null) {
            return $result;
        }
        $now = CarbonImmutable::now();
        $result['person'] = [
            'id' => $partner->id, 'name' => $partner->name, 'dealer_code' => $partner->dealer_code, 'phone' => $partner->phone,
            'role' => $partner->role, 'status' => $partner->status, 'division' => $partner->division, 'district' => $partner->district,
        ];
        $override = $this->policies->recipientOverrides([$partner->id])[$partner->id] ?? null;
        $result['receiver_current'] = $override === null ? null : [
            'schedule_id' => $override['schedule_id'],
            'rates' => $override['rates'],
        ];
        $buyer = $this->policies->activeSchedule('buyer', $partner->id, $now);
        $result['buyer_current'] = $buyer === null ? null : [
            'schedule_id' => (int) $buyer->id,
            'heads' => $this->page->heads(json_decode($buyer->rules, true, flags: JSON_THROW_ON_ERROR)),
        ];
        $result['schedules'] = $this->schedules($viewer, $partner, $now);
        $result['controls'] = in_array($partner->role, ['dealer', 'sub_dealer'], true) ? PartnerBenefitControls::for($partner) : null;
        $result['seats'] = DB::table('area_dealer_assignments as a')->leftJoin('place_codes as p', 'p.id', '=', 'a.place_code_id')
            ->where('a.user_id', $partner->id)->where('a.is_active', true)->orderBy('a.id')
            ->get(['a.level', 'a.monthly_quota', 'p.name as place'])
            ->map(fn (object $seat): array => ['level' => (string) $seat->level, 'place' => (string) $seat->place, 'monthly_quota' => (string) $seat->monthly_quota])->all();

        return $result;
    }

    /**
     * The general percent of each commission a person's earnings rate can be changed for, as the posting uses it.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{category: string, rule: string, pct: string}>
     */
    public function receiverGeneral(array $rules): array
    {
        $lines = [];
        foreach (self::ReceiverLines as $category => [$rule, $parameter]) {
            $active = (bool) ($rules[$rule]['is_active'] ?? false);
            $pct = $parameter === null
                ? CommissionPolicy::rate($rules, $rule)->decimal()
                : ($active ? Percentage::from((string) $rules[$rule]['parameters'][$parameter])->decimal() : '0.00');
            $lines[] = ['category' => $category, 'rule' => $rule, 'pct' => $pct];
        }

        return $lines;
    }

    /**
     * Condition thresholds that can be customized higher or lower for this specific partner.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, list<array{key: string, unit: string, general: string}>>
     */
    public function customizable(array $rules, ?User $partner): array
    {
        if ($partner === null || ! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            return [];
        }
        $rankParams = $rules['rank_royalty']['parameters'] ?? [];
        $vehicleParams = $rules['car_bike_incentive']['parameters'] ?? [];
        $subSalaryParams = $rules['sub_dealer_salary']['parameters'] ?? [];
        $coolingDays = ($rules['commission_cooling_off']['is_active'] ?? false)
            ? (string) ($rules['commission_cooling_off']['parameters']['cooling_off_days'] ?? 0)
            : '0';

        $fields = [
            'rank_join' => [
                ['key' => 'mo_target', 'unit' => 'money', 'general' => (string) ($rankParams['mo_target'] ?? '500000.00')],
                ['key' => 'am_count', 'unit' => 'count', 'general' => (string) ($rankParams['am_count'] ?? 10)],
                ['key' => 'sm_count', 'unit' => 'count', 'general' => (string) ($rankParams['sm_count'] ?? 10)],
                ['key' => 'me_count', 'unit' => 'count', 'general' => (string) ($rankParams['me_count'] ?? 10)],
                ['key' => 'qualification_depth', 'unit' => 'count', 'general' => (string) ($rankParams['qualification_depth'] ?? 3)],
            ],
            'bike' => [
                ['key' => 'bike_target', 'unit' => 'money', 'general' => (string) ($vehicleParams['bike_target'] ?? '10000000.00')],
                ['key' => 'bike_leg_cap', 'unit' => 'money', 'general' => (string) ($vehicleParams['bike_leg_cap'] ?? '2000000.00')],
            ],
            'car' => [
                ['key' => 'car_target', 'unit' => 'money', 'general' => (string) ($vehicleParams['car_target'] ?? '500000000.00')],
                ['key' => 'car_leg_cap', 'unit' => 'money', 'general' => (string) ($vehicleParams['car_leg_cap'] ?? '80000000.00')],
            ],
            'cooling_off' => [
                ['key' => 'cooling_off_days', 'unit' => 'days', 'general' => $coolingDays],
            ],
        ];

        if ($partner->role === 'dealer') {
            $kind = app(DealerKinds::class)->of((int) $partner->id);
            $fields['salary'] = [
                ['key' => 'monthly_target', 'unit' => 'money', 'general' => Money::decimal(MonthlyTargets::dealerTarget($rules, $kind))],
            ];
        } else {
            $fields['sub_salary'] = [
                ['key' => 'tier1_volume', 'unit' => 'money', 'general' => (string) ($subSalaryParams['tier1_volume'] ?? '10000.00')],
                ['key' => 'tier1_pct', 'unit' => 'percent', 'general' => (string) ($subSalaryParams['tier1_pct'] ?? '1.00')],
                ['key' => 'tier2_volume', 'unit' => 'money', 'general' => (string) ($subSalaryParams['tier2_volume'] ?? '20000.00')],
                ['key' => 'tier2_pct', 'unit' => 'percent', 'general' => (string) ($subSalaryParams['tier2_pct'] ?? '2.00')],
                ['key' => 'tier3_volume', 'unit' => 'money', 'general' => (string) ($subSalaryParams['tier3_volume'] ?? '40000.00')],
                ['key' => 'tier3_pct', 'unit' => 'percent', 'general' => (string) ($subSalaryParams['tier3_pct'] ?? '3.00')],
            ];
            $fields['rank_keep'] = [
                ['key' => 'sub_dealer_royalty_target', 'unit' => 'money', 'general' => Money::decimal(MonthlyTargets::subDealerRoyaltyTarget($rules))],
            ];
        }

        return $fields;
    }

    /**
     * The special rates and waiting drafts of one person, newest first, with what the viewer may do to each.
     *
     * @return list<array<string, mixed>>
     */
    private function schedules(User $viewer, User $partner, CarbonImmutable $now): array
    {
        $isOwner = CommissionPolicy::approves($viewer);
        $rows = DB::table('commission_policy_schedules as s')
            ->leftJoin('users as proposer', 'proposer.id', '=', 's.created_by')->leftJoin('users as reviewer', 'reviewer.id', '=', 's.reviewed_by')
            ->where('s.user_id', $partner->id)->whereIn('s.scope', ['buyer', 'recipient'])
            ->select(['s.id', 's.scope', 's.status', 's.origin', 's.rules', 's.changes', 's.reason', 's.effective_at', 's.expires_at', 's.created_by',
                's.reviewed_at', 's.review_reason', 'proposer.name as proposed_by_name', 'reviewer.name as reviewed_by_name'])
            ->orderByDesc('s.id')->limit(40)->get();

        return $rows->map(function (object $row) use ($viewer, $isOwner, $now): array {
            $effective = CarbonImmutable::parse($row->effective_at);
            $expires = $row->expires_at ? CarbonImmutable::parse($row->expires_at) : null;
            $state = match (true) {
                $row->status === 'draft' => $expires !== null && $expires->lte($now) ? 'expired' : 'draft',
                $row->status === 'approved' => $expires !== null && $expires->lte($now) ? 'ended' : ($effective->gt($now) ? 'upcoming' : 'active'),
                default => (string) $row->status,
            };
            $changes = is_string($row->changes ?? null) ? json_decode($row->changes, true, flags: JSON_THROW_ON_ERROR) : [];
            $live = in_array($state, ['active', 'upcoming'], true) && $row->origin !== 'rule_edit';

            return [
                'id' => (int) $row->id,
                'scope' => (string) $row->scope,
                'state' => $state,
                'status' => (string) $row->status,
                'lines' => $this->lines((string) $row->scope, $changes),
                'effective_at' => $effective->toIso8601String(),
                'expires_at' => $expires?->toIso8601String(),
                'reason' => $row->reason,
                'proposed_by_name' => $row->proposed_by_name,
                'reviewed_by_name' => $row->reviewed_by_name,
                'review_reason' => $row->review_reason,
                'can_review' => $isOwner && $state === 'draft',
                'can_cancel' => $state === 'draft' && ((int) $row->created_by === (int) $viewer->id || $isOwner || $viewer->canAct('finance', 'approve')),
                'can_end' => $isOwner && $live,
            ];
        })->all();
    }

    /**
     * What a schedule changes, as plain lines in the reader's language.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string>
     */
    public function lines(string $scope, array $changes): array
    {
        $lines = [];
        foreach ($changes as $key => $change) {
            if (! is_array($change)) {
                continue;
            }
            if ($scope === 'recipient') {
                $after = (array) ($change['after'] ?? []);
                $mode = (string) ($after['funding_mode'] ?? '');
                $lines[] = __('special_rates.receiver_'.$key).': '.LocalDigits::text($this->pct($after['percentage'] ?? null)).'%'
                    .($mode !== '' ? ' ('.__('special_rates.funding_'.$mode).')' : '');

                continue;
            }
            $head = (string) __('commission_page.head_'.$key);
            $before = (array) ($change['before'] ?? []);
            $after = (array) ($change['after'] ?? []);
            if (array_key_exists('percentage', $after)) {
                $lines[] = $head.': '.LocalDigits::text($this->pct($before['percentage'] ?? null)).'% → '.LocalDigits::text($this->pct($after['percentage'])).'%';
            }
            foreach ((array) ($after['parameters'] ?? []) as $name => $value) {
                $lines[] = $head.' · '.$this->parameterWord((string) $key, (string) $name).': '
                    .LocalDigits::text((string) ($before['parameters'][$name] ?? '—')).' → '.LocalDigits::text((string) $value);
            }
            if (array_key_exists('is_active', $after)) {
                $lines[] = $head.': '.__($after['is_active'] ? 'special_rates.switched_on' : 'special_rates.switched_off');
            }
        }

        return $lines;
    }

    private function pct(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) (float) $value;
    }

    private function parameterWord(string $rule, string $parameter): string
    {
        $part = $parameter === 'bike_pool_share_pct' ? 'bike' : (string) preg_replace('/_(share_)?pct$/', '', $parameter);
        foreach (['commission_page.part_'.$rule.'_'.$part, 'commission_page.num_'.$parameter] as $key) {
            $word = (string) __($key);
            if ($word !== $key) {
                return $word;
            }
        }

        return $parameter;
    }
}
