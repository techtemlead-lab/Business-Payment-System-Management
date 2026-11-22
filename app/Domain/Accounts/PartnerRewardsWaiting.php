<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\ReserveService;
use App\Models\DealerRank;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The prizes a partner has already earned but that are not in the wallet yet: the one-time rank prize (MO, AM, SM, ED)
 * of a rank the partner holds, and a bike or car prize whose sales target is met. They are shown as waiting with a
 * plain reason, never as received. It only reads rows that already exist and writes nothing: amounts, rules and the
 * moment of posting stay exactly as the engine has them.
 *
 * A prize that has been paid is not here; it is a wallet statement row and a line of its income head.
 */
final class PartnerRewardsWaiting
{
    /** @var array<int, string|null>|null the partner's dealer kind when the caller already knows it */
    private ?array $kinds = null;

    public function __construct(private CommissionQualification $qualification, private CommissionPolicy $policies, private ReserveService $reserves) {}

    /**
     * `state` is "pending" (earned or coming, not in the balance) or "set_aside" (the partner's own fund, spent by the office);
     * `amount` is null when no honest figure exists before the month closes.
     *
     * @return list<array{id: string, head: string, what: string, amount: ?string, reason: string, state: string, since: ?string}>
     */
    public function for(User $partner, array $context = []): array
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true) || $partner->status !== 'active') {
            return [];
        }
        // The caller may already hold what this needs (the dashboard has the rules, the rank row and the bike and car rows), so nothing is
        // read twice. The rules are read only when there is a prize or a rank to look at: most partners have neither.
        $rules = null;
        $rulesOf = function () use (&$rules, $context): array {
            return $rules ??= $context['rules'] ?? $this->policies->snapshot(false)['rules'];
        };
        // The dashboard passes the dealer's kind it already read, so the royalty check reads no seats again.
        $this->kinds = $context['kinds'] ?? null;
        $milestones = $context['milestones'] ?? RewardMilestoneProgress::query()->where('user_id', $partner->id)->whereIn('milestone_type', ['bike', 'car'])->get()->keyBy('milestone_type');
        // A sub-dealer may hold a rank too (owner's rule of 2026-11-21); one who holds none reads no rank lines.
        $rank = array_key_exists('rank', $context) ? $context['rank'] : DealerRank::query()->where('user_id', $partner->id)->first();
        $holdsRank = $rank !== null && in_array($rank->current_rank, CommissionSettlement::LeadershipRanks, true);

        return [
            ...($holdsRank ? $this->rankPrizes($partner, $rank, $rulesOf) : []),
            ...$this->vehiclePrizes($partner, $milestones, $rulesOf),
            ...($holdsRank ? $this->royaltyNote($partner, $rulesOf) : []),
            ...($partner->role === 'sub_dealer' ? $this->tourFund($partner) : []),
        ];
    }

    /**
     * The rank royalty is a share of the month's pool among everyone who qualifies, so its amount exists only when the month closes.
     * A dealer who is paid as a rank holder is told so, with no figure.
     *
     * @param  Closure(): array<string, array<string, mixed>>  $rules
     * @return list<array{id: string, head: string, what: string, amount: ?string, reason: string, state: string, since: ?string}>
     */
    private function royaltyNote(User $partner, Closure $rules): array
    {
        $paidAs = (string) ($this->qualification->paidAs((int) $partner->id, null, $rules(), $this->kinds)['paid_as_rank'] ?? 'dealer');
        if (! in_array($paidAs, CommissionSettlement::LeadershipRanks, true)) {
            return [];
        }

        return [['id' => 'royalty-month', 'head' => 'royalty', 'what' => PartnerIncomeHeads::label('royalty'), 'amount' => null, 'reason' => 'royalty_month_end', 'state' => 'pending', 'since' => null]];
    }

    /**
     * A sub-dealer's own tour fund: money set aside for them from their orders. The office spends it on tours; it is never paid into the balance.
     *
     * @return list<array{id: string, head: string, what: string, amount: ?string, reason: string, state: string, since: ?string}>
     */
    private function tourFund(User $partner): array
    {
        $left = max(0, $this->reserves->remainingByCategory((int) $partner->id)['sub_dealer_tour'] ?? 0);
        if ($left <= 0) {
            return [];
        }

        return [['id' => 'tour-fund', 'head' => 'tour', 'what' => PartnerIncomeHeads::label('tour'), 'amount' => FundCatalog::decimalOf($left), 'reason' => 'tour_fund', 'state' => 'set_aside', 'since' => null]];
    }

    /**
     * One line for each reached rank whose one-time prize has not been paid. The engine pays them lowest first, as soon as the
     * rank is reached when the office has switched on automatic approval, otherwise when the office pays it.
     *
     * @param  Closure(): array<string, array<string, mixed>>  $rulesOf
     * @return list<array{id: string, head: string, what: string, amount: string, reason: string, state: string, since: ?string}>
     */
    private function rankPrizes(User $partner, DealerRank $rank, Closure $rulesOf): array
    {
        $rules = $rulesOf();
        $paid = DB::table('rank_incentive_awards')->where('user_id', $partner->id)->pluck('rank')->all();
        // A prize paid before the award record existed is only a flag; the engine refuses a second one, so none is waiting.
        if ($rank->one_time_incentive_paid && $paid === []) {
            return [];
        }
        $reached = collect($rank->qualification_history ?? [])->pluck('rank')->push($rank->current_rank)
            ->filter(fn ($code): bool => is_string($code) && CommissionSettlement::rankAtLeast((string) $rank->current_rank, $code))
            ->unique();
        $kept = ($this->qualification->paidAs((int) $partner->id, null, $rules, $this->kinds)['paid_as_rank'] ?? 'dealer') === $rank->current_rank;
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
                'state' => 'pending',
                'since' => optional($rank->achieved_at)->toIso8601String(),
            ];
        }

        return $lines;
    }

    /**
     * A bike or car prize whose target is met: waiting for the office to ask for it, or for the request to be approved.
     *
     * @param  Collection<string, RewardMilestoneProgress>  $milestones  the partner's bike and car rows by type
     * @param  Closure(): array<string, array<string, mixed>>  $rulesOf
     * @return list<array{id: string, head: string, what: string, amount: string, reason: string, state: string, since: ?string}>
     */
    private function vehiclePrizes(User $partner, Collection $milestones, Closure $rulesOf): array
    {
        $lines = [];
        $reached = $milestones->filter(fn (RewardMilestoneProgress $row): bool => (bool) $row->achieved)->map(fn (RewardMilestoneProgress $row) => $row->achieved_at);
        foreach (['bike', 'car'] as $program) {
            if (! $reached->has($program)) {
                continue;
            }
            $amount = FundCatalog::centsOf((string) ($rulesOf()['car_bike_incentive']['parameters'][$program.'_award_amount'] ?? '0'));
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
                'state' => 'pending',
                'since' => $reached->get($program)?->toIso8601String(),
            ];
        }

        return $lines;
    }
}
