<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\ReserveService;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The partner's "my progress" pop-up: how far they are toward this month's salary, the next rank
 * and its one-time award, the bike and car rewards and (for sub-dealers) the tour fund.
 *
 * Every figure comes from the code that later pays the money: monthly volume and rank-keeping from
 * CommissionQualification, the salary estimate from CommissionSettlement::salaryPlan(), bike and car
 * from CommissionQualification::milestone(), the tour balance from ReserveService. Every target is
 * read from the live commission rules, so changing a rule changes the pop-up at once.
 */
final class PartnerProgress
{
    public function __construct(
        private CommissionQualification $qualification,
        private CommissionPolicy $policy,
        private CommissionSettlement $settlement,
        private ReserveService $reserves,
    ) {}

    /**
     * @return array{
     *   role: string,
     *   period: array{month: string, starts_on: string, ends_on: string, days_left: int},
     *   salary: array<string, mixed>|null,
     *   rank: array<string, mixed>|null,
     *   rewards: list<array<string, mixed>>,
     *   tour: array<string, mixed>|null
     * }
     */
    public function for(User $user): array
    {
        $month = CommissionQualification::currentMonth();
        [$start, $end] = CommissionQualification::period($month);
        $today = CarbonImmutable::now((string) config('madina.business_timezone'))->startOfDay();
        $rules = $this->policy->snapshot(false)['rules'];
        $isDealer = $user->role === 'dealer';
        $monthlyVolume = $this->qualification->monthlyVolume($user->id, $month);
        $showSubDealerFunds = ! $isDealer && DashboardManagement::partnerFeatureEnabled($user, 'salary');

        return [
            'role' => (string) $user->role,
            'period' => [
                'month' => $month,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->subDay()->toDateString(),
                'days_left' => max(1, (int) $today->diffInDays($end)),
            ],
            'salary' => match (true) {
                $isDealer => $this->dealerSalary($user, $rules, $month, $monthlyVolume),
                $showSubDealerFunds => $this->subDealerSalary($user, $rules, $month, $monthlyVolume),
                default => null,
            },
            'rank' => $isDealer && DashboardManagement::partnerFeatureEnabled($user, 'ranks')
                ? $this->dealerRank($user, $rules, $month)
                : null,
            'rewards' => DashboardManagement::partnerFeatureEnabled($user, 'rewards') ? $this->rewards($user, $rules) : [],
            'tour' => $showSubDealerFunds ? $this->tour($user, $rules, $month) : null,
        ];
    }

    /**
     * Dealer salary: the month's salary pool of the dealer's division is shared equally by the
     * dealers there who bought at least the rule's minimum this month (or have it waived).
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function dealerSalary(User $user, array $rules, string $month, int $monthlyVolume): array
    {
        $isOn = CommissionPolicy::rate($rules, 'dealer_monthly_salary')->basisPoints > 0;
        $target = Money::cents((string) $rules['dealer_monthly_salary']['parameters']['min_monthly_volume']);
        $isWaived = PartnerBenefitControls::waivesSalaryVolume($user);
        $isMet = $isWaived || $monthlyVolume >= $target;
        $estimate = null;
        if ($isOn && $isMet) {
            $plan = $this->settlement->salaryPlan($month);
            $mine = collect($plan['dealers'])->firstWhere('user_id', (int) $user->id);
            $division = $mine !== null ? collect($plan['divisions'])->firstWhere('division', $mine['division']) : null;
            $estimate = [
                'share' => $mine['share'] ?? '0.00',
                'division' => $mine['division'] ?? null,
                'division_pool' => $division['pool'] ?? '0.00',
                'qualifiers' => (int) ($division['qualifiers'] ?? 0),
            ];
        }

        return [
            'kind' => 'dealer_pool',
            'status' => match (true) {
                ! $isOn => 'off',
                $isWaived => 'waived',
                $isMet => 'met',
                default => 'not_met',
            },
            'rate' => (string) $rules['dealer_monthly_salary']['percentage'],
            ...$this->moneyBar($monthlyVolume, $target),
            'estimate' => $estimate,
        ];
    }

    /**
     * Sub-dealer salary: a flat share of every paid and delivered purchase, with no minimum,
     * paid when the month is closed. The amount is the month's pending salary already set aside.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function subDealerSalary(User $user, array $rules, string $month, int $monthlyVolume): array
    {
        $earned = $this->subDealerSalarySetAside((int) $user->id, $month);

        return [
            'kind' => 'sub_dealer_flat',
            'status' => CommissionPolicy::rate($rules, 'sub_dealer_salary')->basisPoints > 0 ? 'earning' : 'off',
            'rate' => (string) $rules['sub_dealer_salary']['percentage'],
            'current' => Money::decimal($monthlyVolume),
            'earned' => Money::decimal($earned),
        ];
    }

    /**
     * The dealer's rank today, what the next rank needs, the one-time award for each, and what
     * must be bought this month to keep being paid royalty at the current rank.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function dealerRank(User $user, array $rules, string $month): array
    {
        $rank = DealerRank::query()->where('user_id', $user->id)->first();
        $current = $rank->current_rank ?? 'dealer';
        $parameters = $rules['rank_royalty']['parameters'];
        $incentiveOn = CommissionPolicy::rate($rules, 'rank_incentive')->basisPoints > 0;
        $bonus = fn (string $code): ?string => $incentiveOn
            ? Money::decimal(Money::cents((string) $rules['rank_incentive']['parameters'][$code.'_bonus']))
            : null;

        $next = null;
        if ($current === 'dealer') {
            $next = [
                'rank' => 'mo',
                'measure' => 'own_purchases',
                ...$this->moneyBar(Money::cents((string) ($rank->lifetime_purchase_total ?? '0')), Money::cents((string) $parameters['mo_target'])),
            ];
        } else {
            $nextCode = CommissionQualification::RankLevels[array_search($current, CommissionQualification::RankLevels, true) + 1] ?? null;
            $required = $nextCode !== null ? (CommissionQualification::TeamRankRequirements[$nextCode] ?? null) : null;
            if ($nextCode !== null && $required !== null) {
                $depth = (int) $parameters['qualification_depth'];
                $counts = $this->qualification->teamRankCounts([(int) $user->id], $depth)[(int) $user->id] ?? [];
                $next = [
                    'rank' => $nextCode,
                    'measure' => 'team_leaders',
                    'required_rank' => $required,
                    'depth' => $depth,
                    ...$this->countBar(CommissionQualification::leadersAtOrAbove($counts, $required), (int) $parameters[$nextCode.'_count']),
                ];
            }
        }
        if ($next !== null) {
            $next['award'] = $bonus($next['rank']);
        }

        $award = null;
        if ($current !== 'dealer') {
            $paid = DB::table('rank_incentive_awards')->where('user_id', $user->id)->where('rank', $current)->value('amount');
            $isPaid = $paid !== null || (bool) $rank?->one_time_incentive_paid;
            $award = [
                'rank' => $current,
                'amount' => $paid !== null ? Money::decimal(Money::cents((string) $paid)) : $bonus($current),
                'status' => $isPaid ? 'paid' : ($incentiveOn ? 'waiting' : 'off'),
            ];
        }

        $keep = null;
        if ($current !== 'dealer' || PartnerBenefitControls::forcedRoyaltyRank($user) !== null) {
            $paidAs = $this->qualification->paidAs((int) $user->id, $month, $rules);
            $keep = [
                'rank' => $paidAs['status'] === 'admin_unconditional' ? $paidAs['paid_as_rank'] : $paidAs['lifetime_rank'],
                'status' => $paidAs['status'],
                'royalty_on' => CommissionPolicy::rate($rules, 'rank_royalty')->basisPoints > 0,
                'grace_days' => (int) $rules['rank_maintenance']['parameters']['grace_period_days'],
                ...$this->moneyBar(Money::cents($paidAs['monthly_volume']), Money::cents($paidAs['required_volume'])),
            ];
        }

        return [
            'current' => $current,
            'next' => $next,
            'award' => $award,
            'keep' => $keep,
        ];
    }

    /**
     * Bike and car rewards, counted exactly as the office's reward check counts them.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function rewards(User $user, array $rules): array
    {
        $isOn = CommissionPolicy::rate($rules, 'car_bike_incentive')->basisPoints > 0;
        $claimed = RewardMilestoneProgress::query()
            ->where('user_id', $user->id)
            ->where('claimed', true)
            ->pluck('milestone_type')
            ->all();
        $rewards = [];
        foreach (['bike', 'car'] as $type) {
            $milestone = $this->qualification->milestone((int) $user->id, $type);
            $rewards[] = [
                'type' => $type,
                'status' => match (true) {
                    in_array($type, $claimed, true) => 'given',
                    (bool) $milestone['achieved'] => 'reached',
                    ! $isOn => 'off',
                    default => 'in_progress',
                },
                'line_cap' => $milestone['leg_cap_amount'],
                'team_sales' => $milestone['raw_team_volume'],
                'lines' => count($milestone['legs']),
                ...$this->moneyBar(Money::cents($milestone['qualifying_volume']), Money::cents($milestone['target_amount'])),
            ];
        }

        return $rewards;
    }

    /**
     * The sub-dealer's own tour fund: what has been set aside, what was already spent on tours,
     * and what is still there for the office to use.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function tour(User $user, array $rules, string $month): array
    {
        $saved = DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->where('category', 'sub_dealer_tour')
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(CASE WHEN metadata->>\'period_month\' = ? THEN amount ELSE 0 END), 0) AS this_month', [$month])
            ->first();
        $savedTotal = Money::cents((string) ($saved->total ?? '0'));
        $available = $this->tourFundLeft((int) $user->id);
        $waiting = DB::table('reserve_disbursements')
            ->where('user_id', $user->id)
            ->where('program', 'tour')
            ->where('status', 'requested')
            ->count();

        return [
            'status' => CommissionPolicy::rate($rules, 'sub_dealer_tour')->basisPoints > 0 ? 'saving' : 'off',
            'rate' => (string) $rules['sub_dealer_tour']['percentage'],
            'saved_this_month' => Money::decimal(Money::cents((string) ($saved->this_month ?? '0'))),
            'saved_total' => Money::decimal($savedTotal),
            'used' => Money::decimal(max(0, $savedTotal - $available)),
            'available' => Money::decimal($available),
            'requests_waiting' => $waiting,
            'is_active' => $user->status === 'active',
        ];
    }

    /**
     * A sub-dealer's salary for the month that is still waiting to be paid: exactly what the
     * month-end salary settlement pays them (in paisa).
     */
    public function subDealerSalarySetAside(int $userId, string $month): int
    {
        return Money::cents((string) CommissionAllocation::query()
            ->where('user_id', $userId)
            ->where('category', 'sub_dealer_salary')
            ->where('status', 'pending')
            ->where('metadata->period_month', $month)
            ->sum('amount'));
    }

    /** Money still in a sub-dealer's own tour fund, in paisa. */
    public function tourFundLeft(int $userId): int
    {
        return max(0, $this->reserves->remainingByCategory($userId)['sub_dealer_tour'] ?? 0);
    }

    /**
     * Done and left toward a taka target. The percent is rounded down, so 100% means really met.
     *
     * @return array{unit: string, current: string, target: string, left: string, percent: float}
     */
    private function moneyBar(int $current, int $target): array
    {
        return [
            'unit' => 'taka',
            'current' => Money::decimal(max(0, $current)),
            'target' => Money::decimal(max(0, $target)),
            'left' => Money::decimal(max(0, $target - $current)),
            'percent' => $this->percent($current, $target),
        ];
    }

    /**
     * Done and left toward a head count.
     *
     * @return array{unit: string, current: int, target: int, left: int, percent: float}
     */
    private function countBar(int $current, int $target): array
    {
        return [
            'unit' => 'people',
            'current' => max(0, $current),
            'target' => max(0, $target),
            'left' => max(0, $target - $current),
            'percent' => $this->percent($current, $target),
        ];
    }

    private function percent(int $current, int $target): float
    {
        if ($target <= 0) {
            return 100.0;
        }

        return min(100.0, floor(max(0, $current) * 1000 / $target) / 10);
    }
}
