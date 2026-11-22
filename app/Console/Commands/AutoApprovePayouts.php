<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\MonthCloseStatus;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\UnownedShareSweeps;
use App\Models\DealerRank;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('finance:auto-approve')]
#[Description('Pay out salary, rank royalty and rank rewards on their own when auto-approval is on for them')]
class AutoApprovePayouts extends Command
{
    /** Pending fund categories each monthly settlement pays out. */
    private const MonthlyFunds = [
        'salary' => ['dealer_monthly_salary_pool', 'sub_dealer_salary'],
        // The four rank funds of newer orders and the single fund of older ones (RankRoyaltyFunds).
        'royalty' => ['rank_royalty_pool', 'rank_royalty_mo_pool', 'rank_royalty_am_pool', 'rank_royalty_sm_pool', 'rank_royalty_me_pool'],
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
            // Owner's decision (2026-11-21): every ended month that still has unpaid fund money is closed, oldest
            // first, however long ago it ended (a missed run never leaves a month open). Each close is idempotent.
            foreach ($this->closedMonthsWithPendingFunds($categories) as $month) {
                $summary[$kind.':'.$month] = $this->closeMonth($kind, $month, fn () => $kind === 'salary'
                    ? $commissions->settleMonthlySalaryPool($month)['status']
                    : $commissions->settleRankRoyaltyPool($month)['status']);
            }
        }

        // Owner rule: a share still held because its target was missed goes to company profit once
        // its month has ended. Every ended month is checked, so a late run cannot leave it held.
        $reserves = app(ReserveService::class);
        foreach ($reserves->closedMonthsWithHeldAreaShares() as $month) {
            $summary['area_quota_close:'.$month] = $this->closeMonth('area_quota', $month, fn () => $reserves->forfeitUnmetQuotaHolds($month)['status']);
        }

        // Owner decision: a share still held after the owner's limit of months (setting, 3 by default, 0 = never) goes to the company fund too.
        $summary['area_held_too_long'] = $this->attempt(fn () => $reserves->forfeitStaleAreaHolds()['status']);

        // Owner decision D3: commission held for a partner who is active again is paid; a closed
        // account's holds go to company profit after 3 months.
        $sweeps = app(UnownedShareSweeps::class);
        $summary['held_for_active_partners'] = $this->attempt(fn () => 'released '.$sweeps->releaseHeldForActivePartners()['released_count']);
        $summary['closed_account_holds'] = $this->attempt(fn () => $sweeps->closedAccountHoldsToCompanyProfit()['status']);
        $summary['suspended_holds'] = $this->attempt(fn () => $sweeps->suspendedHoldsToCompanyProfit()['status']);

        // Month close for the sub-dealer additional-rank fund: every ended month that still has an open fund row.
        foreach ($this->closedMonthsWithPendingFunds(['additional_rank_pool']) as $month) {
            $summary['additional_rank_close:'.$month] = $this->closeMonth('additional_rank', $month, fn () => $reserves->closeAdditionalRankFund($month)['status']);
        }

        if ($settings->isAutoApproved('rank_incentive')) {
            foreach ($this->rankRewardCandidates() as $userId) {
                // Owner rule: every rank reached is rewarded at once (MO and AM in the same run if both were reached).
                $summary['rank_incentive:'.$userId] = $this->attempt(function () use ($commissions, $userId): string {
                    $paid = 0;
                    while ($paid < 4 && ($commissions->claimOneTimeRankIncentive($userId)['success'] ?? false)) {
                        $paid++;
                    }

                    return $paid > 0 ? 'paid' : 'not yet eligible';
                });
            }
        } else {
            $summary['rank_incentive'] = 'needs approval';
        }
        // Owner's decision (2026-11-21): after the prizes above, the rank prize fund keeps what the waiting prizes need;
        // money above that from months that have ended goes to the company profit fund.
        $summary['rank_prize_surplus'] = $this->attempt(fn () => $reserves->closeRankPrizeSurplus()['status']);

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        $failed = array_filter($summary, fn (string $outcome): bool => str_starts_with($outcome, 'failed: '));
        if ($failed !== []) {
            // A failed payout is retried next hour, but someone must look at why it failed.
            Log::critical('Automatic payout approval failed for some payouts.', ['failed' => $failed]);

            return self::FAILURE;
        }

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
     * Active dealers and sub-dealers holding a rank whose one-time reward is not paid yet.
     * The reward itself re-checks every rule, so a candidate who is not yet
     * eligible is simply skipped until a later run.
     *
     * @return list<int>
     */
    private function rankRewardCandidates(): array
    {
        return DealerRank::query()
            ->whereNotIn('current_rank', ['dealer'])
            // Sub-dealers hold ranks too since the owner's rule of 2026-11-21.
            ->whereIn('user_id', User::query()->whereIn('role', ['dealer', 'sub_dealer'])->where('status', 'active')->select('id'))
            // The current rank, or any lower rank reached on the way (MO then AM within one run),
            // whose one-time reward is still unpaid.
            ->where(fn ($query) => $query
                ->whereNotExists(fn ($awards) => $awards->from('rank_incentive_awards')
                    ->whereColumn('rank_incentive_awards.user_id', 'dealer_ranks.user_id')
                    ->whereColumn('rank_incentive_awards.rank', 'dealer_ranks.current_rank'))
                ->orWhereRaw(<<<'SQL'
                    EXISTS (
                        SELECT 1 FROM jsonb_array_elements(COALESCE(dealer_ranks.qualification_history::jsonb, '[]'::jsonb)) AS reached(entry)
                        WHERE reached.entry->>'rank' IN ('mo', 'am', 'sm', 'me')
                          AND NOT EXISTS (
                              SELECT 1 FROM rank_incentive_awards
                              WHERE rank_incentive_awards.user_id = dealer_ranks.user_id
                                AND rank_incentive_awards.rank = reached.entry->>'rank'
                          )
                    )
                SQL))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * A month close that fails is shown on the Settlements page until a later run or a person closes
     * it (MA-01), so a crash never again fails silently every hour.
     */
    private function closeMonth(string $kind, string $month, callable $close): string
    {
        $outcome = $this->attempt($close);
        $status = app(MonthCloseStatus::class);
        if (str_starts_with($outcome, 'failed: ')) {
            $status->recordFailure($kind, $month, substr($outcome, strlen('failed: ')));
        } else {
            $status->clearFailure($kind, $month);
        }

        return $outcome;
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
