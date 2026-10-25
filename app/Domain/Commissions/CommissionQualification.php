<?php

namespace App\Domain\Commissions;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Models\DealerRank;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionQualification
{
    public static function currentMonth(): string
    {
        return now(config('madina.business_timezone'))->format('Y-m');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function period(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            throw ValidationException::withMessages(['period_month' => 'Choose a valid month in YYYY-MM format.']);
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
        if ($buyer->role === 'dealer' && $rank->current_rank === 'dealer' && Money::cents($rank->lifetime_purchase_total) >= Money::cents((string) $parameters['mo_target'])) {
            $this->promote($rank, 'mo');
        }
        $ancestors = ReferralTreePath::getUplines($buyer->id, 100);
        foreach ($ancestors as $path) {
            $leader = DealerRank::firstOrCreate(['user_id' => $path->ancestor_id]);
            foreach (['am' => 'mo', 'sm' => 'am', 'me' => 'sm'] as $target => $required) {
                $levels = ['dealer', 'mo', 'am', 'sm', 'me'];
                if (array_search($leader->current_rank, $levels, true) >= array_search($target, $levels, true)) {
                    continue;
                }
                $minimumIndex = array_search($required, $levels, true);
                $count = DB::table('referral_tree_paths')->join('dealer_ranks', 'dealer_ranks.user_id', '=', 'referral_tree_paths.descendant_id')
                    ->join('users', 'users.id', '=', 'dealer_ranks.user_id')->where('ancestor_id', $leader->user_id)
                    ->whereBetween('depth', [1, (int) $parameters['qualification_depth']])->where('users.status', 'active')
                    ->where('users.role', 'dealer')->whereIn('current_rank', array_slice($levels, $minimumIndex))->count();
                if ($count >= (int) $parameters[$target.'_count']) {
                    $this->promote($leader, $target);
                }
            }
            $this->refreshMilestones($path->ancestor_id);
        }
    }

    /** @return array<string, mixed> */
    public function paidAs(int $userId, ?string $month = null, ?array $rules = null): array
    {
        $month ??= CommissionQualification::currentMonth();
        [, $end] = self::period($month);
        $rank = DealerRank::where('user_id', $userId)->first();
        $code = $rank?->current_rank ?? 'dealer';
        $lifetime = $code;
        $achievedAt = $rank?->achieved_at;
        $asOf = $month === self::currentMonth() ? now() : $end->subSecond();
        if ($achievedAt && CarbonImmutable::parse($achievedAt)->gt($asOf)) {
            $code = 'dealer';
            foreach (($rank->qualification_history ?? []) as $entry) {
                if (isset($entry['rank'],$entry['achieved_at']) && CarbonImmutable::parse($entry['achieved_at'])->lte($asOf)) {
                    $code = $entry['rank'];
                    $achievedAt = $entry['achieved_at'];
                }
            }
        }
        $rules ??= app(CommissionPolicy::class)->snapshot()['rules'];
        $parameters = $rules['rank_maintenance']['parameters'];
        $volume = $this->monthlyVolume($userId, $month);
        $required = $code === 'dealer' ? 0 : Money::cents((string) $parameters[$code.'_min_monthly_volume']);
        $qualified = $volume >= $required;
        $grace = $achievedAt && CarbonImmutable::parse($achievedAt)->addDays((int) $parameters['grace_period_days'])->gte($asOf);
        $user = User::query()->find($userId);
        if ($user && ($forced = PartnerBenefitControls::forcedRoyaltyRank($user))) {
            return [
                'lifetime_rank' => $lifetime,
                'paid_as_rank' => $forced,
                'monthly_volume' => Money::decimal($volume),
                'required_volume' => Money::decimal($required),
                'is_qualified' => true,
                'in_grace_period' => false,
                'status' => 'admin_unconditional',
            ];
        }

        return ['lifetime_rank' => $lifetime, 'paid_as_rank' => $qualified || $grace ? $code : 'dealer',
            'monthly_volume' => Money::decimal($volume), 'required_volume' => Money::decimal($required), 'is_qualified' => $qualified,
            'in_grace_period' => ! $qualified && $grace, 'status' => $qualified ? 'qualified' : ($grace ? 'grace_period' : 'demoted')];
    }

    private function promote(DealerRank $rank, string $code): void
    {
        $history = $rank->qualification_history ?? [];
        if ($history === [] && $rank->current_rank !== 'dealer' && $rank->achieved_at) {
            $history[] = ['rank' => $rank->current_rank, 'achieved_at' => $rank->achieved_at->toIso8601String()];
        }
        $history[] = ['rank' => $code, 'achieved_at' => now()->toIso8601String()];
        $rank->update(['current_rank' => $code, 'achieved_at' => now(), 'qualification_history' => $history, 'one_time_incentive_paid' => false]);
    }

    /** @return array<string, mixed> */
    public function milestone(int $userId, string $type): array
    {
        if (! in_array($type, ['bike', 'car'], true)) {
            throw ValidationException::withMessages(['milestone_type' => 'Choose bike or car.']);
        }
        $parameters = app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive']['parameters'];
        $target = Money::cents((string) $parameters[$type.'_target']);
        $cap = Money::cents((string) $parameters[$type.'_leg_cap']);
        $directLegs = User::query()->where('sponsor_id', $userId)->orderBy('id')->get(['id', 'name']);
        if ($directLegs->isEmpty()) {
            return [
                'milestone_type' => $type,
                'target_amount' => Money::decimal($target),
                'leg_cap_amount' => Money::decimal($cap),
                'raw_team_volume' => '0.00',
                'qualifying_volume' => '0.00',
                'achieved' => false,
                'progress_percentage' => 0.0,
                'percentage' => 0.0,
                'legs' => [],
            ];
        }

        $legIds = $directLegs->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $paths = ReferralTreePath::query()
            ->whereIn('ancestor_id', $legIds)
            ->get(['ancestor_id', 'descendant_id']);
        /** @var array<int, list<int>> $treeByLeg */
        $treeByLeg = [];
        foreach ($legIds as $legId) {
            $treeByLeg[$legId] = [$legId];
        }
        foreach ($paths as $path) {
            $treeByLeg[(int) $path->ancestor_id][] = (int) $path->descendant_id;
        }
        $allMemberIds = array_values(array_unique(array_merge(...array_values($treeByLeg))));
        $volumesByUser = DB::table('orders')
            ->where('orders.is_opening_balance', false)
            ->leftJoin('commission_batches', 'commission_batches.order_id', '=', 'orders.id')
            ->whereIn('orders.user_id', $allMemberIds)
            ->where('orders.status', 'delivered')
            ->whereColumn('orders.paid', 'orders.total')
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id, COALESCE(SUM(orders.total - COALESCE(commission_batches.refunded_amount, 0)), 0) AS volume')
            ->pluck('volume', 'user_id');

        $raw = 0;
        $qualified = 0;
        $legs = [];
        foreach ($directLegs as $leg) {
            $cents = 0;
            foreach (array_values(array_unique($treeByLeg[(int) $leg->id])) as $memberId) {
                $cents += Money::cents((string) ($volumesByUser[$memberId] ?? '0'));
            }
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
            'achieved' => $qualified >= $target,
            'progress_percentage' => $target > 0 ? min(100, round($qualified / $target * 100, 2)) : 0,
            'percentage' => $target > 0 ? min(100, round($qualified / $target * 100, 2)) : 0,
            'legs' => $legs,
        ];
    }

    /** @return array<string, mixed> */
    public function refreshMilestones(int $userId): array
    {
        $result = [];
        foreach (['bike', 'car'] as $type) {
            $data = $this->milestone($userId, $type);
            $row = RewardMilestoneProgress::firstOrCreate(['user_id' => $userId, 'milestone_type' => $type],
                ['target_amount' => $data['target_amount'], 'leg_cap_amount' => $data['leg_cap_amount']]);
            $row->update(['target_amount' => $data['target_amount'], 'leg_cap_amount' => $data['leg_cap_amount'],
                'raw_team_volume' => $data['raw_team_volume'], 'qualifying_volume' => $data['qualifying_volume'], 'achieved' => $data['achieved'],
                'achieved_at' => $data['achieved'] ? ($row->achieved_at ?? now()) : null, 'leg_breakdown' => $data['legs']]);
            $result[$type] = $data;
        }

        return $result;
    }
}
