<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use App\Support\PhoneIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a partner should act on: direct partners who stopped ordering, this month's salary target
 * or rank maintenance in danger, and a rank that is nearly reached. Every figure is read from the
 * qualification code the month-end settlement pays from, so the warning and the payment agree.
 */
final class PartnerAlerts
{
    /** "At risk" means the target is still open with this many days or fewer left in the month. */
    public const RiskWindowDays = 10;

    /** A rank counts as close once the partner has this share of what it needs. */
    public const CloseToRankPercent = 80;

    /** How many inactive partners the dashboard card lists by name. */
    public const InactiveListed = 5;

    /** The dashboard card re-reads the figures at most this often. */
    public const CardCacheSeconds = 600;

    public function __construct(
        private CommissionQualification $qualification,
        private CommissionPolicy $policy,
    ) {}

    /**
     * @return array{alerts: list<array{kind: string, values: array<string, string|int>, period: string}>, inactive_partners: list<array<string, mixed>>, inactive_days: int}
     */
    public function cardFor(User $user): array
    {
        return Cache::remember('partner-tools:alerts:'.$user->id, self::CardCacheSeconds, fn (): array => $this->forUsers(collect([$user]))[(int) $user->id]);
    }

    /**
     * The same answer for many partners at once, with one set of queries per batch.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{alerts: list<array{kind: string, values: array<string, string|int>, period: string}>, inactive_partners: list<array<string, mixed>>, inactive_days: int}>
     */
    public function forUsers(Collection $users): array
    {
        $month = CommissionQualification::currentMonth();
        [, $end] = CommissionQualification::period($month);
        $today = CarbonImmutable::now((string) config('madina.business_timezone'))->startOfDay();
        $daysLeft = max(1, (int) $today->diffInDays($end));
        $rules = $this->policy->snapshot(false)['rules'];
        $inactiveDays = $this->inactiveDays();
        $dealers = $users->where('role', 'dealer')->values();
        $dealerIds = $dealers->map(fn (User $user): int => (int) $user->id)->all();
        $paidAs = $dealerIds === [] ? [] : $this->qualification->paidAsFor($dealerIds, $month, $rules);
        $ranks = DealerRank::query()->whereIn('user_id', $dealerIds)->get()->keyBy('user_id');
        $leaderIds = $ranks->filter(fn (DealerRank $rank): bool => $rank->current_rank !== 'dealer')->keys()->map(fn ($id): int => (int) $id)->values()->all();
        $depth = (int) $rules['rank_royalty']['parameters']['qualification_depth'];
        $teamCounts = $leaderIds === [] ? [] : $this->qualification->teamRankCounts($leaderIds, $depth);
        $inactive = $this->inactiveDirectPartners($users->map(fn (User $user): int => (int) $user->id)->all(), $today->subDays($inactiveDays));

        $result = [];
        foreach ($users as $user) {
            $id = (int) $user->id;
            $alerts = [];
            if ($user->role === 'dealer') {
                $alerts = [
                    ...$this->salaryAlert($user, $rules, $paidAs[$id] ?? null, $daysLeft, $month),
                    ...$this->rankKeepAlert($user, $rules, $paidAs[$id] ?? null, $daysLeft, $month),
                    ...$this->rankCloseAlert($user, $rules, $ranks->get($id), $teamCounts[$id] ?? [], $month),
                ];
            }
            $mine = $inactive->get($id, collect());
            if ($mine->isNotEmpty()) {
                $alerts[] = [
                    'kind' => 'inactive_partners',
                    'values' => ['count' => $mine->count(), 'days' => $inactiveDays],
                    'period' => $today->format('o-\WW'),
                ];
            }
            $result[$id] = [
                'alerts' => $alerts,
                'inactive_partners' => $mine->take(self::InactiveListed)->map(fn (object $partner): array => [
                    'id' => (int) $partner->id,
                    'name' => (string) $partner->name,
                    'dealer_code' => $partner->dealer_code,
                    // The same rule as the team list: full number for your own referrals, masked beyond them.
                    'phone' => PhoneIdentity::forDownlineViewer($partner->phone, (int) $partner->id, $partner->sponsor_id !== null ? (int) $partner->sponsor_id : null, $id),
                    'last_active_on' => CarbonImmutable::parse($partner->last_active_at, 'UTC')->setTimezone((string) config('madina.business_timezone'))->toDateString(),
                ])->values()->all(),
                'inactive_days' => $inactiveDays,
            ];
        }

        return $result;
    }

    public function inactiveDays(): int
    {
        return (int) (DashboardManagement::get()['partner_inactive_days'] ?? 30);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, mixed>|null  $paidAs
     * @return list<array{kind: string, values: array<string, string|int>, period: string}>
     */
    private function salaryAlert(User $user, array $rules, ?array $paidAs, int $daysLeft, string $month): array
    {
        if ($daysLeft > self::RiskWindowDays || $paidAs === null
            || CommissionPolicy::rate($rules, 'dealer_monthly_salary')->basisPoints <= 0
            || PartnerBenefitControls::waivesSalaryVolume($user)
            || ! DashboardManagement::partnerFeatureEnabled($user, 'salary')) {
            return [];
        }
        $target = Money::cents((string) $rules['dealer_monthly_salary']['parameters']['min_monthly_volume']);
        $volume = Money::cents((string) $paidAs['monthly_volume']);
        if ($volume >= $target) {
            return [];
        }

        return [['kind' => 'salary_at_risk', 'values' => ['left' => Money::decimal($target - $volume), 'days' => $daysLeft], 'period' => $month]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, mixed>|null  $paidAs
     * @return list<array{kind: string, values: array<string, string|int>, period: string}>
     */
    private function rankKeepAlert(User $user, array $rules, ?array $paidAs, int $daysLeft, string $month): array
    {
        if ($daysLeft > self::RiskWindowDays || $paidAs === null
            || ! in_array($paidAs['status'], ['grace_period', 'demoted'], true)
            || CommissionPolicy::rate($rules, 'rank_royalty')->basisPoints <= 0
            || ! DashboardManagement::partnerFeatureEnabled($user, 'ranks')) {
            return [];
        }
        $left = max(0, Money::cents((string) $paidAs['required_volume']) - Money::cents((string) $paidAs['monthly_volume']));
        if ($left === 0) {
            return [];
        }

        return [['kind' => 'rank_keep_at_risk', 'values' => ['rank' => (string) $paidAs['lifetime_rank'], 'left' => Money::decimal($left), 'days' => $daysLeft], 'period' => $month]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, int>  $teamCounts
     * @return list<array{kind: string, values: array<string, string|int>, period: string}>
     */
    private function rankCloseAlert(User $user, array $rules, ?DealerRank $rank, array $teamCounts, string $month): array
    {
        if (! DashboardManagement::partnerFeatureEnabled($user, 'ranks')) {
            return [];
        }
        $parameters = $rules['rank_royalty']['parameters'];
        $current = $rank->current_rank ?? 'dealer';
        if ($current === 'dealer') {
            $done = Money::cents((string) ($rank->lifetime_purchase_total ?? '0'));
            $target = Money::cents((string) $parameters['mo_target']);
            if (! $this->isClose($done, $target)) {
                return [];
            }

            return [['kind' => 'rank_close_purchases', 'values' => ['rank' => 'mo', 'left' => Money::decimal($target - $done)], 'period' => $month]];
        }
        $next = CommissionQualification::RankLevels[array_search($current, CommissionQualification::RankLevels, true) + 1] ?? null;
        $required = $next !== null ? (CommissionQualification::TeamRankRequirements[$next] ?? null) : null;
        if ($next === null || $required === null) {
            return [];
        }
        $done = CommissionQualification::leadersAtOrAbove($teamCounts, $required);
        $target = (int) $parameters[$next.'_count'];
        if (! $this->isClose($done, $target)) {
            return [];
        }

        return [['kind' => 'rank_close_team', 'values' => ['rank' => $next, 'required' => $required, 'left' => $target - $done], 'period' => $month]];
    }

    private function isClose(int $done, int $target): bool
    {
        return $target > 0 && $done < $target && ($done * 100) >= ($target * self::CloseToRankPercent);
    }

    /**
     * Active direct partners whose last real order (or joining date, if they never ordered) is
     * older than the cut-off, longest quiet first, grouped by the partner who referred them.
     *
     * @param  list<int>  $sponsorIds
     * @return Collection<int, Collection<int, object>>
     */
    private function inactiveDirectPartners(array $sponsorIds, CarbonImmutable $cutoff): Collection
    {
        if ($sponsorIds === []) {
            return collect();
        }
        $lastOrders = DB::table('orders')
            ->whereIn('user_id', DB::table('users')->whereIn('sponsor_id', $sponsorIds)->select('id'))
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('is_opening_balance', false)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(created_at) AS last_order_at');

        return DB::table('users as partners')
            ->leftJoinSub($lastOrders, 'last_orders', 'last_orders.user_id', '=', 'partners.id')
            ->whereIn('partners.sponsor_id', $sponsorIds)
            ->where('partners.status', 'active')
            ->whereIn('partners.role', ['dealer', 'sub_dealer'])
            ->whereRaw('COALESCE(last_orders.last_order_at, partners.created_at) < ?', [$cutoff->utc()])
            ->orderByRaw('COALESCE(last_orders.last_order_at, partners.created_at)')
            ->selectRaw('partners.id, partners.name, partners.dealer_code, partners.phone, partners.sponsor_id, COALESCE(last_orders.last_order_at, partners.created_at) AS last_active_at')
            ->get()
            ->groupBy(fn (object $partner): int => (int) $partner->sponsor_id);
    }
}
