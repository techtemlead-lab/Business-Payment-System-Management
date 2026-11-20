<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\FundCatalog;
use App\Models\DealerRank;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The prizes a partner has already earned but that are not in the wallet yet: the one-time rank prize (MO, AM, SM, ME)
 * of a rank the partner holds, and a bike or car prize whose sales target is met. They are shown as waiting with a
 * plain reason, never as received. It only reads rows that already exist and writes nothing: amounts, rules and the
 * moment of posting stay exactly as the engine has them.
 *
 * A prize that has been paid is not here; it is a wallet statement row and a line of its income head.
 */
final class PartnerRewardsWaiting
{
    public function __construct(private CommissionQualification $qualification, private CommissionPolicy $policies) {}

    /**
     * @return list<array{id: string, head: string, what: string, amount: string, reason: string, since: ?string}>
     */
    public function for(User $partner): array
    {
        if ($partner->role !== 'dealer' || $partner->status !== 'active') {
            return [];
        }
        $rules = $this->policies->snapshot()['rules'];

        return [...$this->rankPrizes($partner, $rules), ...$this->vehiclePrizes($partner, $rules)];
    }

    /**
     * One line for each reached rank whose one-time prize has not been paid. The engine pays them lowest first, as soon as the
     * rank is reached when the office has switched on automatic approval, otherwise when the office pays it.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{id: string, head: string, what: string, amount: string, reason: string, since: ?string}>
     */
    private function rankPrizes(User $partner, array $rules): array
    {
        $rank = DealerRank::where('user_id', $partner->id)->first();
        if (! $rank || ! in_array($rank->current_rank, CommissionSettlement::LeadershipRanks, true)) {
            return [];
        }
        $paid = DB::table('rank_incentive_awards')->where('user_id', $partner->id)->pluck('rank')->all();
        // A prize paid before the award record existed is only a flag; the engine refuses a second one, so none is waiting.
        if ($rank->one_time_incentive_paid && $paid === []) {
            return [];
        }
        $reached = collect($rank->qualification_history ?? [])->pluck('rank')->push($rank->current_rank)
            ->filter(fn ($code): bool => is_string($code) && CommissionSettlement::rankAtLeast((string) $rank->current_rank, $code))
            ->unique();
        $kept = ($this->qualification->paidAs((int) $partner->id, null, $rules)['paid_as_rank'] ?? 'dealer') === $rank->current_rank;
        $lines = [];
        foreach (CommissionSettlement::LeadershipRanks as $code) {
            if (! $reached->contains($code) || in_array($code, $paid, true)) {
                continue;
            }
            $amount = FundCatalog::centsOf((string) ($rules['rank_incentive']['parameters'][$code.'_bonus'] ?? '0'));
            if ($amount <= 0) {
                continue;
            }
            $lines[] = [
                'id' => 'rank-'.$code,
                'head' => 'rank_'.$code,
                'what' => PartnerIncomeHeads::label('rank_'.$code),
                'amount' => FundCatalog::decimalOf($amount),
                'reason' => $kept ? 'rank_prize_office' : 'rank_prize_keep',
                'since' => optional($rank->achieved_at)->toIso8601String(),
            ];
        }

        return $lines;
    }

    /**
     * A bike or car prize whose target is met: waiting for the office to ask for it, or for the request to be approved.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{id: string, head: string, what: string, amount: string, reason: string, since: ?string}>
     */
    private function vehiclePrizes(User $partner, array $rules): array
    {
        $lines = [];
        $reached = DB::table('reward_milestone_progress')->where('user_id', $partner->id)->where('achieved', true)
            ->whereIn('milestone_type', ['bike', 'car'])->pluck('achieved_at', 'milestone_type');
        foreach (['bike', 'car'] as $program) {
            if (! $reached->has($program)) {
                continue;
            }
            $amount = FundCatalog::centsOf((string) ($rules['car_bike_incentive']['parameters'][$program.'_award_amount'] ?? '0'));
            if ($amount <= 0) {
                continue;
            }
            $award = DB::table('reserve_disbursements')->where('award_key', $program.':'.$partner->id)->value('status');
            // A request the office has paid (credited or paid) is a wallet row already.
            if ($award !== null && $award !== 'requested') {
                continue;
            }
            $lines[] = [
                'id' => $program.'-prize',
                'head' => $program,
                'what' => PartnerIncomeHeads::label($program),
                'amount' => FundCatalog::decimalOf($amount),
                'reason' => $award === 'requested' ? 'prize_review' : 'prize_office',
                'since' => $reached->get($program) !== null ? (string) $reached->get($program) : null,
            ];
        }

        return $lines;
    }
}
