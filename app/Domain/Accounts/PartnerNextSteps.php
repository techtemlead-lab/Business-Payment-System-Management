<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Plain next steps toward this month's salary target and the next rank: what to buy or who to
 * bring along, read from the qualification code the settlement uses. It only ever states the
 * action and the remaining purchase or head count, never what the partner would earn.
 */
final class PartnerNextSteps
{
    /** The dashboard card re-reads the figures at most this often. */
    public const CacheSeconds = 600;

    public function __construct(
        private CommissionQualification $qualification,
        private CommissionPolicy $policy,
    ) {}

    /** @return list<array{kind: string, values: array<string, string|int>}> */
    public function for(User $user): array
    {
        return Cache::remember('partner-tools:next-steps:'.$user->id, self::CacheSeconds, fn (): array => $this->build($user));
    }

    /** @return list<array{kind: string, values: array<string, string|int>}> */
    private function build(User $user): array
    {
        if ($user->role !== 'dealer') {
            return DashboardManagement::partnerFeatureEnabled($user, 'salary') ? [['kind' => 'sub_dealer_keep_ordering', 'values' => []]] : [];
        }
        $rules = $this->policy->snapshot(false)['rules'];
        $steps = [];
        if (DashboardManagement::partnerFeatureEnabled($user, 'salary') && CommissionPolicy::rate($rules, 'dealer_monthly_salary')->basisPoints > 0) {
            $steps[] = $this->salaryStep($user, $rules);
        }
        if (DashboardManagement::partnerFeatureEnabled($user, 'ranks')) {
            array_push($steps, ...$this->rankSteps($user, $rules));
        }

        return $steps;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return array{kind: string, values: array<string, string|int>}
     */
    private function salaryStep(User $user, array $rules): array
    {
        if (PartnerBenefitControls::waivesSalaryVolume($user)) {
            return ['kind' => 'salary_waived', 'values' => []];
        }
        $target = Money::cents((string) $rules['dealer_monthly_salary']['parameters']['min_monthly_volume']);
        $volume = $this->qualification->monthlyVolume((int) $user->id, CommissionQualification::currentMonth());
        if ($volume >= $target) {
            return ['kind' => 'salary_met', 'values' => []];
        }

        return ['kind' => 'salary_buy', 'values' => ['left' => Money::decimal($target - $volume), 'target' => Money::decimal($target)]];
    }

    /**
     * The purchase needed to keep the rank this month, then what the next rank asks for.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{kind: string, values: array<string, string|int>}>
     */
    private function rankSteps(User $user, array $rules): array
    {
        $rank = DealerRank::query()->where('user_id', $user->id)->first();
        $current = $rank->current_rank ?? 'dealer';
        $parameters = $rules['rank_royalty']['parameters'];
        $steps = [];
        if ($current !== 'dealer') {
            $paidAs = $this->qualification->paidAs((int) $user->id, CommissionQualification::currentMonth(), $rules);
            $left = Money::cents((string) $paidAs['required_volume']) - Money::cents((string) $paidAs['monthly_volume']);
            if ($paidAs['status'] !== 'admin_unconditional' && $left > 0) {
                $steps[] = ['kind' => 'rank_keep', 'values' => ['rank' => (string) $paidAs['lifetime_rank'], 'left' => Money::decimal($left)]];
            }
        }
        $next = CommissionQualification::RankLevels[array_search($current, CommissionQualification::RankLevels, true) + 1] ?? null;
        if ($next === null) {
            $steps[] = ['kind' => 'rank_top', 'values' => []];

            return $steps;
        }
        if ($current === 'dealer') {
            $target = Money::cents((string) $parameters['mo_target']);
            $done = Money::cents((string) ($rank->lifetime_purchase_total ?? '0'));
            $steps[] = ['kind' => 'rank_buy', 'values' => ['rank' => 'mo', 'left' => Money::decimal(max(0, $target - $done))]];

            return $steps;
        }
        $required = CommissionQualification::TeamRankRequirements[$next];
        $depth = (int) $parameters['qualification_depth'];
        $counts = $this->qualification->teamRankCounts([(int) $user->id], $depth)[(int) $user->id] ?? [];
        $needed = (int) $parameters[$next.'_count'];
        $done = CommissionQualification::leadersAtOrAbove($counts, $required);
        $steps[] = ['kind' => 'rank_team', 'values' => ['rank' => $next, 'required' => $required, 'depth' => $depth, 'left' => max(0, $needed - $done)]];

        return $steps;
    }
}
