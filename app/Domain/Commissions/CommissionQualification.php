<?php

namespace App\Domain\Commissions;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Models\DealerRank;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionQualification
{
    /** Dealer ranks from lowest to highest. */
    public const RankLevels = ['dealer', 'mo', 'am', 'sm', 'me'];

    /**
     * Ranks won by building a team: the rank => the rank each counted team leader must hold
     * (or beat). The "{rank}_count" rule parameter says how many such leaders are needed.
     */
    public const TeamRankRequirements = ['am' => 'mo', 'sm' => 'am', 'me' => 'sm'];

    /** Order statuses whose goods reached the partner, whole or in part. */
    public const DeliveredStatuses = ['delivered', MixedOutcomeDecisions::PartlyDelivered];

    /**
     * SQL for what an order in DeliveredStatuses counts for: the whole order when delivered, and
     * only the part commission was posted on when partly delivered. Monthly volume reads the same
     * figure from the commission batch. A no-commission order counts for nothing.
     */
    public static function deliveredValueSql(string $orders = 'orders'): string
    {
        return "CASE WHEN {$orders}.status = 'delivered' THEN {$orders}.total"
            ." ELSE COALESCE((SELECT commission_batches.cash_basis FROM commission_batches WHERE commission_batches.order_id = {$orders}.id AND commission_batches.no_commission = false), 0) END";
    }

    public static function currentMonth(): string
    {
        return now(config('madina.business_timezone'))->format('Y-m');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function period(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            throw ValidationException::withMessages(['period_month' => __('portal.err_qual_month')]);
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, config('madina.business_timezone'));

        return [$start, $start->addMonth()];
    }

    public function monthlyVolume(int $userId, string $month): int
    {
        return $this->monthlyVolumesFor([$userId], $month)[$userId] ?? 0;
    }

    /**
     * Batch monthly volumes for many users in one query (settlements UI).
     *
     * @param  list<int>  $userIds
     * @return array<int, int> user_id => cents
     */
    public function monthlyVolumesFor(array $userIds, string $month): array
    {
        self::period($month);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $rows = DB::table('commission_batches')
            ->join('orders', 'orders.id', '=', 'commission_batches.order_id')
            ->whereIn('orders.user_id', $userIds)
            ->where('period_month', $month)
            ->where('no_commission', false)
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id, COALESCE(SUM(cash_basis - refunded_amount), 0) AS volume')
            ->pluck('volume', 'user_id');

        $volumes = [];
        foreach ($userIds as $userId) {
            $volumes[$userId] = Money::cents((string) ($rows[$userId] ?? '0'));
        }

        return $volumes;
    }

    /** @param array<string, array<string, mixed>> $rules */
    public function record(User $buyer, int $cash, array $rules): void
    {
        ReferralTreePath::attachUser($buyer->id, $buyer->sponsor_id);
        $rank = DealerRank::firstOrCreate(['user_id' => $buyer->id]);
        $rank->update(['lifetime_purchase_total' => Money::decimal(Money::cents($rank->lifetime_purchase_total) + $cash),
            'monthly_purchase_total' => Money::decimal($this->monthlyVolume($buyer->id, CommissionQualification::currentMonth())), 'purchase_period' => CommissionQualification::currentMonth()]);
        $parameters = $rules['rank_royalty']['parameters'];
        // From the owner's rule of 2026-11-21 a sub-dealer earns ranks too; older rules ranked dealers only.
        $canRank = $buyer->role === 'dealer' || ($buyer->role === 'sub_dealer' && MonthlyTargets::subDealersEarnRanks($rules));
        if ($canRank && $rank->current_rank === 'dealer' && Money::cents($rank->lifetime_purchase_total) >= Money::cents((string) $parameters['mo_target'])) {
            $this->promote($rank, 'mo');
        }
        $ancestors = ReferralTreePath::getUplines($buyer->id, 100);
        if ($ancestors->isEmpty()) {
            return;
        }

        $ancestorIds = $ancestors->pluck('ancestor_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $existingLeaderIds = DealerRank::query()->whereIn('user_id', $ancestorIds)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_diff($ancestorIds, $existingLeaderIds) as $missingId) {
            DealerRank::query()->firstOrCreate(['user_id' => $missingId]);
        }
        /** @var Collection<int, DealerRank> $leaders */
        $leaders = DealerRank::query()->whereIn('user_id', $ancestorIds)->get()->keyBy('user_id');

        $levels = self::RankLevels;
        $countsByLeader = $this->teamRankCounts($ancestorIds, (int) $parameters['qualification_depth']);

        $milestoneLeaderIds = [];
        foreach ($ancestors as $path) {
            $leaderId = (int) $path->ancestor_id;
            $leader = $leaders->get($leaderId);
            if ($leader === null) {
                continue;
            }
            foreach (self::TeamRankRequirements as $target => $required) {
                if (array_search($leader->current_rank, $levels, true) >= array_search($target, $levels, true)) {
                    continue;
                }
                $count = self::leadersAtOrAbove($countsByLeader[$leaderId] ?? [], $required);
                if ($count >= (int) $parameters[$target.'_count']) {
                    $this->promote($leader, $target);
                    $leader->refresh();
                }
            }
            $milestoneLeaderIds[$leaderId] = $leaderId;
        }

        $this->refreshMilestonesFor(array_values($milestoneLeaderIds));
    }

    /**
     * The team count rank promotion is decided on, per direct-referral line (owner's rule, 2026-11-21): each of the
     * leader's direct referrals is one line, and a line counts ONCE, at the best rank held by an active dealer or
     * sub-dealer in it — the direct referral himself or anyone under him, within $depth levels of the leader. Two MOs
     * in one line still count as one line. Worked out in one query for all leaders.
     *
     * The result is leader id => rank => number of lines whose best rank is that rank, so leadersAtOrAbove() gives the
     * number of lines holding a rank or better.
     *
     * @param  list<int>  $leaderIds
     * @return array<int, array<string, int>> leader id => rank => count of lines
     */
    public function teamRankCounts(array $leaderIds, int $depth): array
    {
        $countsByLeader = [];
        foreach ($leaderIds as $leaderId) {
            $countsByLeader[(int) $leaderId] = array_fill_keys(self::RankLevels, 0);
        }
        if ($countsByLeader === [] || $depth < 1) {
            return $countsByLeader;
        }
        $ids = array_keys($countsByLeader);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $order = [];
        foreach (self::RankLevels as $index => $code) {
            $order[] = "WHEN '{$code}' THEN {$index}";
        }
        $rankIndex = 'CASE dealer_ranks.current_rank '.implode(' ', $order).' ELSE 0 END';
        $rows = DB::select(<<<SQL
            WITH heads AS (
                SELECT ancestor_id AS leader_id, descendant_id AS head_id
                FROM referral_tree_paths
                WHERE ancestor_id IN ({$placeholders}) AND depth = 1
            ), members AS (
                SELECT leader_id, head_id, head_id AS member_id FROM heads
                UNION
                SELECT heads.leader_id, heads.head_id, below.descendant_id
                FROM heads
                INNER JOIN referral_tree_paths AS below ON below.ancestor_id = heads.head_id AND below.depth BETWEEN 1 AND ?
            )
            SELECT members.leader_id, members.head_id, MAX({$rankIndex}) AS best
            FROM members
            INNER JOIN dealer_ranks ON dealer_ranks.user_id = members.member_id
            INNER JOIN users ON users.id = members.member_id
            WHERE users.status = 'active' AND users.role IN ('dealer', 'sub_dealer')
            GROUP BY members.leader_id, members.head_id
        SQL, [...$ids, $depth - 1]);
        foreach ($rows as $row) {
            $code = self::RankLevels[(int) $row->best] ?? 'dealer';
            $countsByLeader[(int) $row->leader_id][$code]++;
        }

        return $countsByLeader;
    }

    /**
     * Checks every active partner's rank against today's rules, for a rule change (such as sub-dealers earning ranks or
     * the line-by-line team count) that only takes effect on a partner's next posted order otherwise. With $apply false
     * nothing is written: it lists who would move up. With $apply true each one is promoted exactly as an order would
     * promote them (history, notice, SMS, and the one-time rank prize when automatic approval is on), and the check is
     * repeated, since one promotion can lift a leader above. A rank is never lowered.
     *
     * @return list<array{user_id: int, role: string, from: string, to: string, steps: list<string>}>
     */
    public function recheckRanks(bool $apply): array
    {
        $rules = app(CommissionPolicy::class)->snapshot()['rules'];
        $roles = MonthlyTargets::subDealersEarnRanks($rules) ? ['dealer', 'sub_dealer'] : ['dealer'];
        $changes = [];
        for ($pass = 0; $pass < count(self::RankLevels); $pass++) {
            $found = $this->rankChanges($rules['rank_royalty']['parameters'], $roles);
            if ($found === []) {
                break;
            }
            array_push($changes, ...$found);
            if (! $apply) {
                break;
            }
            DB::transaction(function () use ($found): void {
                CommissionWallet::lock();
                foreach ($found as $change) {
                    $rank = DealerRank::query()->where('user_id', $change['user_id'])->lockForUpdate()->first();
                    if ($rank === null || $rank->current_rank !== $change['from']) {
                        continue;
                    }
                    foreach ($change['steps'] as $step) {
                        $this->promote($rank, $step);
                        $rank->refresh();
                    }
                }
            }, 3);
        }

        return $changes;
    }

    /**
     * Who would move up now, with the ranks they would pass through in order.
     *
     * @param  array<string, mixed>  $parameters  the rank royalty rule's parameters
     * @param  list<string>  $roles
     * @return list<array{user_id: int, role: string, from: string, to: string, steps: list<string>}>
     */
    private function rankChanges(array $parameters, array $roles): array
    {
        $users = User::query()->where('status', 'active')->whereIn('role', $roles)->pluck('role', 'id');
        $ranks = DealerRank::query()->whereIn('user_id', $users->keys())->where('current_rank', '<>', 'me')->orderBy('user_id')->get();
        if ($ranks->isEmpty()) {
            return [];
        }
        $levels = self::RankLevels;
        $counts = $this->teamRankCounts($ranks->pluck('user_id')->map(fn ($id): int => (int) $id)->all(), (int) $parameters['qualification_depth']);
        $changes = [];
        foreach ($ranks as $rank) {
            $current = (string) $rank->current_rank;
            $reached = (int) array_search($current, $levels, true);
            $steps = [];
            if ($current === 'dealer' && Money::cents((string) $rank->lifetime_purchase_total) >= Money::cents((string) $parameters['mo_target'])) {
                $steps[] = 'mo';
                $reached = (int) array_search('mo', $levels, true);
            }
            foreach (self::TeamRankRequirements as $target => $required) {
                $index = (int) array_search($target, $levels, true);
                if ($index > $reached && self::leadersAtOrAbove($counts[(int) $rank->user_id] ?? [], $required) >= (int) $parameters[$target.'_count']) {
                    $steps[] = $target;
                    $reached = $index;
                }
            }
            if ($steps !== []) {
                $changes[] = ['user_id' => (int) $rank->user_id, 'role' => (string) $users[$rank->user_id], 'from' => $current, 'to' => $steps[count($steps) - 1], 'steps' => $steps];
            }
        }

        return $changes;
    }

    /**
     * Team leaders holding $requiredRank or any higher rank.
     *
     * @param  array<string, int>  $counts  rank => count, from teamRankCounts()
     */
    public static function leadersAtOrAbove(array $counts, string $requiredRank): int
    {
        $minimumIndex = (int) array_search($requiredRank, self::RankLevels, true);
        $count = 0;
        foreach (array_slice(self::RankLevels, $minimumIndex) as $eligibleRank) {
            $count += $counts[$eligibleRank] ?? 0;
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function paidAs(int $userId, ?string $month = null, ?array $rules = null, ?array $kinds = null): array
    {
        return $this->paidAsFor([$userId], $month, $rules, $kinds)[$userId];
    }

    /**
     * Batch paid-as rank evaluation for royalty settlement (one volume query + one rank load).
     *
     * @param  list<int>  $userIds
     * @param  array<string, array<string, mixed>>|null  $rules
     * @return array<int, array<string, mixed>>
     */
    public function paidAsFor(array $userIds, ?string $month = null, ?array $rules = null, ?array $kinds = null): array
    {
        $month ??= self::currentMonth();
        [, $end] = self::period($month);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $rules ??= app(CommissionPolicy::class)->snapshot()['rules'];
        $parameters = $rules['rank_maintenance']['parameters'];
        $volumes = $this->monthlyVolumesFor($userIds, $month);
        $ranks = DealerRank::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');
        // Owner's rule of 2026-11-21: the royalty needs the month's target (a dealer's one target by kind, a
        // sub-dealer's own royalty target) and has no grace period. Older rules keep the per-rank minimums and grace.
        $monthlyTargets = MonthlyTargets::usesMonthlyTargets($rules);
        // A page that already knows the dealer's kind passes it, so it is not read again.
        $kinds = ! $monthlyTargets ? [] : ($kinds ?? app(DealerKinds::class)->for($users->where('role', 'dealer')->keys()->map(fn ($id): int => (int) $id)->all()));
        $asOf = $month === self::currentMonth() ? now() : $end->subSecond();

        $result = [];
        foreach ($userIds as $userId) {
            $rank = $ranks->get($userId);
            $code = $rank?->current_rank ?? 'dealer';
            $lifetime = $code;
            $achievedAt = $rank?->achieved_at;
            if ($achievedAt && CarbonImmutable::parse($achievedAt)->gt($asOf)) {
                $code = 'dealer';
                foreach (($rank->qualification_history ?? []) as $entry) {
                    if (isset($entry['rank'], $entry['achieved_at']) && CarbonImmutable::parse($entry['achieved_at'])->lte($asOf)) {
                        $code = $entry['rank'];
                        $achievedAt = $entry['achieved_at'];
                    }
                }
            }
            $volume = $volumes[$userId] ?? 0;
            $user = $users->get($userId);
            if ($monthlyTargets) {
                $required = (int) MonthlyTargets::royaltyTarget($rules, (string) $user?->role, $kinds[$userId] ?? null);
                $grace = false;
            } else {
                $required = $code === 'dealer' ? 0 : Money::cents((string) $parameters[$code.'_min_monthly_volume']);
                $grace = $achievedAt && CarbonImmutable::parse($achievedAt)->addDays((int) $parameters['grace_period_days'])->gte($asOf);
            }
            $qualified = $volume >= $required;
            if ($user && ($forced = PartnerBenefitControls::forcedRoyaltyRank($user))) {
                $result[$userId] = [
                    'lifetime_rank' => $lifetime,
                    'paid_as_rank' => $forced,
                    'monthly_volume' => Money::decimal($volume),
                    'required_volume' => Money::decimal($required),
                    'is_qualified' => true,
                    'in_grace_period' => false,
                    'status' => 'admin_unconditional',
                ];

                continue;
            }

            $result[$userId] = [
                'lifetime_rank' => $lifetime,
                'paid_as_rank' => $qualified || $grace ? $code : 'dealer',
                'monthly_volume' => Money::decimal($volume),
                'required_volume' => Money::decimal($required),
                'is_qualified' => $qualified,
                'in_grace_period' => ! $qualified && $grace,
                'status' => $qualified ? 'qualified' : ($grace ? 'grace_period' : 'demoted'),
            ];
        }

        return $result;
    }

    /**
     * Owner rule: the one-time reward for a rank is paid as soon as the rank is reached
     * (every reached rank, so MO and AM in the same month both pay). Runs after the sale
     * commits in its own locked transaction, so a reward problem never undoes the sale;
     * the hourly auto-approval run retries anything left unpaid.
     */
    private function payRankRewardsAfterCommit(int $userId): void
    {
        DB::afterCommit(function () use ($userId): void {
            if (! config('madina.financial_activation') || config('madina.preview_mode')
                || ! app(FinanceSettings::class)->isAutoApproved('rank_incentive')) {
                return;
            }
            try {
                for ($paid = 0; $paid < 4; $paid++) {
                    if (! (app(CommissionService::class)->claimOneTimeRankIncentive($userId)['success'] ?? false)) {
                        break;
                    }
                }
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }

    private function promote(DealerRank $rank, string $code): void
    {
        $history = $rank->qualification_history ?? [];
        if ($history === [] && $rank->current_rank !== 'dealer' && $rank->achieved_at) {
            $history[] = ['rank' => $rank->current_rank, 'achieved_at' => $rank->achieved_at->toIso8601String()];
        }
        $history[] = ['rank' => $code, 'achieved_at' => now()->toIso8601String()];
        $rank->update(['current_rank' => $code, 'achieved_at' => now(), 'qualification_history' => $history, 'one_time_incentive_paid' => false]);
        $this->payRankRewardsAfterCommit((int) $rank->user_id);

        $partner = User::find($rank->user_id);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        $rankLabel = __('portal.net_rank_'.$code, [], $locale);
        if ($rankLabel === 'portal.net_rank_'.$code) {
            $rankLabel = strtoupper($code);
        }
        Audit::notice(
            $rank->user_id,
            __('portal.notice_rank_achieved_title', [], $locale),
            __('portal.notice_rank_achieved_body', ['rank' => $rankLabel], $locale),
        );
        $userId = (int) $rank->user_id;
        DB::afterCommit(function () use ($userId, $code): void {
            $partner = User::find($userId);
            if (! $partner) {
                return;
            }
            app(PartnerEventSms::class)->send(
                $partner,
                'rank.achieved',
                SmsText::make('sms.rank_achieved', ['rank' => SmsText::label('portal.net_rank_'.$code, strtoupper($code))]),
            );
        });
    }

    /** @return array<string, mixed> */
    public function milestone(int $userId, string $type): array
    {
        if (! in_array($type, ['bike', 'car'], true)) {
            throw ValidationException::withMessages(['milestone_type' => __('portal.err_qual_milestone')]);
        }
        $parameters = $this->rewardParameters();
        $directLegs = User::query()->where('sponsor_id', $userId)->orderBy('id')->get(['id', 'name']);

        return $this->milestoneFromLegs($type, $parameters, $directLegs, $this->legVolumes($directLegs->pluck('id')->all()), $this->legVolumes([$userId], false)[$userId] ?? 0);
    }

    /** @return array<string, mixed> */
    public function refreshMilestones(int $userId): array
    {
        $result = [];
        foreach (['bike', 'car'] as $type) {
            $data = $this->milestone($userId, $type);
            $this->storeMilestone($userId, $type, $data);
            $result[$type] = $data;
        }

        return $result;
    }

    /**
     * refreshMilestones() for many leaders at once, e.g. every upline of a buyer after a delivery.
     *
     * Each leader ends up with exactly what refreshMilestones() would store, but the team volumes
     * are read in one query for all of them instead of two full team scans per leader. A delivery
     * deep in a large network therefore holds the money lock for a short, steady time.
     *
     * @param  list<int>  $leaderIds
     */
    public function refreshMilestonesFor(array $leaderIds): void
    {
        $leaderIds = array_values(array_unique(array_map('intval', $leaderIds)));
        if ($leaderIds === []) {
            return;
        }
        $parameters = $this->rewardParameters();
        $legs = User::query()->whereIn('sponsor_id', $leaderIds)->orderBy('id')->get(['id', 'name', 'sponsor_id']);
        $volumes = $this->legVolumes($legs->pluck('id')->all());
        $own = $this->legVolumes($leaderIds, false);
        $legsByLeader = $legs->groupBy(fn (User $leg): int => (int) $leg->sponsor_id);
        foreach ($leaderIds as $leaderId) {
            $leaderLegs = $legsByLeader->get($leaderId, collect());
            foreach (['bike', 'car'] as $type) {
                $this->storeMilestone($leaderId, $type, $this->milestoneFromLegs($type, $parameters, $leaderLegs, $volumes, $own[$leaderId] ?? 0));
            }
        }
    }

    /** @return array<string, mixed> */
    private function rewardParameters(): array
    {
        return app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive']['parameters'];
    }

    /**
     * Paid, delivered, net-of-refund purchases of each leg's whole team (the leg and everyone under it).
     * A partly delivered order counts for the part commission was posted on, as monthly volume does.
     *
     * With $withTeam false it is each person's own purchases only (the owner's rule of 2026-11-21 counts them toward
     * the bike and the car too, outside any line's limit).
     *
     * @param  array<int, int|string>  $legIds
     * @return array<int, int> leg user id => cents
     */
    private function legVolumes(array $legIds, bool $withTeam = true): array
    {
        $legIds = array_values(array_unique(array_map('intval', $legIds)));
        if ($legIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($legIds), '?'));
        $team = $withTeam ? 'TRUE' : 'FALSE';
        $rows = DB::select(<<<SQL
            WITH members AS (
                SELECT ancestor_id AS leg_id, descendant_id AS user_id
                FROM referral_tree_paths
                WHERE {$team} AND ancestor_id IN ({$placeholders})
                UNION
                SELECT id AS leg_id, id AS user_id
                FROM users
                WHERE id IN ({$placeholders})
            ), sales AS (
                -- Read from the covering delivered-orders index; refunds are subtracted separately
                -- because only a few orders ever have one.
                SELECT orders.user_id, SUM(orders.total) AS volume
                FROM orders
                WHERE orders.is_opening_balance = false
                  AND orders.status = 'delivered'
                  AND orders.paid = orders.total
                  AND orders.user_id IN (SELECT user_id FROM members)
                GROUP BY orders.user_id
                UNION ALL
                SELECT orders.user_id, SUM(commission_batches.cash_basis) AS volume
                FROM commission_batches
                INNER JOIN orders ON orders.id = commission_batches.order_id
                WHERE orders.is_opening_balance = false
                  AND orders.status = 'partly_delivered'
                  AND commission_batches.no_commission = false
                  AND orders.paid = orders.total
                  AND orders.user_id IN (SELECT user_id FROM members)
                GROUP BY orders.user_id
            ), refunds AS (
                SELECT orders.user_id, SUM(commission_batches.refunded_amount) AS refunded
                FROM commission_batches
                INNER JOIN orders ON orders.id = commission_batches.order_id
                WHERE commission_batches.refunded_amount > 0
                  AND commission_batches.no_commission = false
                  AND orders.is_opening_balance = false
                  AND orders.status IN ('delivered', 'partly_delivered')
                  AND orders.paid = orders.total
                  AND orders.user_id IN (SELECT user_id FROM members)
                GROUP BY orders.user_id
            ), volumes AS (
                SELECT totals.user_id, totals.volume - COALESCE(refunds.refunded, 0) AS volume
                FROM (SELECT user_id, SUM(volume) AS volume FROM sales GROUP BY user_id) AS totals
                LEFT JOIN refunds ON refunds.user_id = totals.user_id
            )
            SELECT members.leg_id, COALESCE(SUM(volumes.volume), 0) AS volume
            FROM members
            INNER JOIN volumes ON volumes.user_id = members.user_id
            GROUP BY members.leg_id
        SQL, [...$legIds, ...$legIds]);

        $volumes = [];
        foreach ($rows as $row) {
            $volumes[(int) $row->leg_id] = Money::cents((string) $row->volume);
        }

        return $volumes;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  Collection<int, User>  $directLegs
     * @param  array<int, int>  $legVolumes
     * @param  int  $own  the person's own purchases: counted in full, outside any line's limit (owner's rule of 2026-11-21)
     * @return array<string, mixed>
     */
    private function milestoneFromLegs(string $type, array $parameters, Collection $directLegs, array $legVolumes, int $own = 0): array
    {
        $target = Money::cents((string) $parameters[$type.'_target']);
        $cap = Money::cents((string) $parameters[$type.'_leg_cap']);
        $raw = $own;
        $qualified = $own;
        $legs = [];
        foreach ($directLegs as $leg) {
            $cents = $legVolumes[(int) $leg->id] ?? 0;
            $raw += $cents;
            $qualified += min($cap, $cents);
            $legs[] = [
                'user_id' => $leg->id,
                'dealer_id' => $leg->id,
                'name' => $leg->name,
                'dealer_name' => $leg->name,
                'raw_volume' => Money::decimal($cents),
                'qualifying_volume' => Money::decimal(min($cap, $cents)),
                'excess_volume' => Money::decimal(max(0, $cents - $cap)),
            ];
        }

        return [
            'milestone_type' => $type,
            'target_amount' => Money::decimal($target),
            'leg_cap_amount' => Money::decimal($cap),
            'raw_team_volume' => Money::decimal($raw),
            'qualifying_volume' => Money::decimal($qualified),
            'own_volume' => Money::decimal($own),
            'achieved' => $target > 0 && $qualified >= $target,
            'progress_percentage' => $target > 0 ? min(100, round($qualified / $target * 100, 2)) : 0.0,
            'percentage' => $target > 0 ? min(100, round($qualified / $target * 100, 2)) : 0.0,
            'legs' => $legs,
        ];
    }

    /** @param array<string, mixed> $data */
    private function storeMilestone(int $userId, string $type, array $data): void
    {
        $row = RewardMilestoneProgress::firstOrCreate(['user_id' => $userId, 'milestone_type' => $type],
            ['target_amount' => $data['target_amount'], 'leg_cap_amount' => $data['leg_cap_amount']]);
        $wasAchieved = (bool) $row->achieved;
        $row->update(['target_amount' => $data['target_amount'], 'leg_cap_amount' => $data['leg_cap_amount'],
            'raw_team_volume' => $data['raw_team_volume'], 'qualifying_volume' => $data['qualifying_volume'], 'achieved' => $data['achieved'],
            'achieved_at' => $data['achieved'] ? ($row->achieved_at ?? now()) : null, 'leg_breakdown' => $data['legs']]);
        if ($data['achieved'] && ! $wasAchieved) {
            $this->notifyMilestoneAchieved($userId, $type);
        }
    }

    private function notifyMilestoneAchieved(int $userId, string $type): void
    {
        $partner = User::find($userId);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        $milestoneLabel = __('portal.milestone_label_'.$type, [], $locale);
        if ($milestoneLabel === 'portal.milestone_label_'.$type) {
            $milestoneLabel = $type;
        }
        Audit::notice(
            $userId,
            __('portal.notice_milestone_achieved_title', [], $locale),
            __('portal.notice_milestone_achieved_body', ['milestone' => $milestoneLabel], $locale),
        );
        DB::afterCommit(function () use ($userId, $type): void {
            $partner = User::find($userId);
            if (! $partner) {
                return;
            }
            app(PartnerEventSms::class)->send(
                $partner,
                'milestone.achieved',
                SmsText::make('sms.milestone_achieved', ['milestone' => SmsText::label('portal.milestone_label_'.$type, $type)]),
            );
        });
    }
}
