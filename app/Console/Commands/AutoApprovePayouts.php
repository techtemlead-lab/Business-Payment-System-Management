<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinanceSettings;
use App\Models\DealerRank;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('finance:auto-approve')]
#[Description('Pay out salary, rank royalty and rank rewards on their own when auto-approval is on for them')]
class AutoApprovePayouts extends Command
{
    /** Pending fund categories each monthly settlement pays out. */
    /** How many closed months back the automatic run may settle (last month, plus one for a missed run). */
    public const AutomaticMonthsBack = 2;

    private const MonthlyFunds = [
        'salary' => ['dealer_monthly_salary_pool', 'sub_dealer_salary'],
        'royalty' => ['rank_royalty_pool'],
    ];

    public function handle(FinanceSettings $settings, CommissionService $commissions): int
    {
        if (! config('madina.financial_activation') || config('madina.preview_mode')) {
            $this->info('Money movement is not switched on. No payouts made.');

            return self::SUCCESS;
        }

        $summary = [];
        foreach (self::MonthlyFunds as $kind => $categories) {
            if (! $settings->isAutoApproved($kind)) {
                $summary[$kind] = 'needs approval';

                continue;
            }
            $oldestAutomatic = CarbonImmutable::createFromFormat('!Y-m', CommissionQualification::currentMonth())
                ->subMonthsNoOverflow(self::AutomaticMonthsBack)->format('Y-m');
            foreach ($this->closedMonthsWithPendingFunds($categories) as $month) {
                // Older months are left for a person to settle on the Settlements page, so turning
                // auto-approval on never pays a long backlog in one go with today's dealer list.
                if ($month < $oldestAutomatic) {
                    $summary[$kind.':'.$month] = 'older month: needs approval';

                    continue;
                }
                $summary[$kind.':'.$month] = $this->attempt(fn () => $kind === 'salary'
                    ? $commissions->settleMonthlySalaryPool($month)['status']
                    : $commissions->settleRankRoyaltyPool($month)['status']);
            }
        }

        if ($settings->isAutoApproved('rank_incentive')) {
            foreach ($this->rankRewardCandidates() as $userId) {
                $summary['rank_incentive:'.$userId] = $this->attempt(
                    fn () => ($commissions->claimOneTimeRankIncentive($userId)['success'] ?? false) ? 'paid' : 'not yet eligible',
                );
            }
        } else {
            $summary['rank_incentive'] = 'needs approval';
        }

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Months that have ended but still hold unpaid fund money.
     *
     * @param  list<string>  $categories
     * @return list<string>
     */
    private function closedMonthsWithPendingFunds(array $categories): array
    {
        $current = CommissionQualification::currentMonth();

        return DB::table('commission_allocations')
            ->whereIn('category', $categories)
            ->where('status', 'pending')
            ->selectRaw("DISTINCT metadata->>'period_month' AS month")
            ->pluck('month')
            ->filter(fn ($month): bool => is_string($month) && $month < $current)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Active dealers holding a rank whose one-time reward is not paid yet.
     * The reward itself re-checks every rule, so a candidate who is not yet
     * eligible is simply skipped until a later run.
     *
     * @return list<int>
     */
    private function rankRewardCandidates(): array
    {
        return DealerRank::query()
            ->whereNotIn('current_rank', ['dealer'])
            ->whereIn('user_id', User::query()->where('role', 'dealer')->where('status', 'active')->select('id'))
            ->whereNotExists(fn ($query) => $query->from('rank_incentive_awards')
                ->whereColumn('rank_incentive_awards.user_id', 'dealer_ranks.user_id')
                ->whereColumn('rank_incentive_awards.rank', 'dealer_ranks.current_rank'))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** One failed payout must not stop the others; the next run tries again. */
    private function attempt(callable $payout): string
    {
        try {
            return (string) $payout();
        } catch (Throwable $error) {
            report($error);

            return 'failed: '.$error->getMessage();
        }
    }
}
