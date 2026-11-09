<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Notifications\BirthdayGreetings;
use App\Domain\Orders\DeliveredMoney;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PartnerDashboardHome
{
    public function __construct(private CommissionQualification $qualification, private PartnerProgress $progress, private PartnerEarningsPresenter $earnings) {}

    /**
     * @return array{
     *   profile: array<string, mixed>,
     *   snapshot: array{own_sales_today: string, team_sales_today: string, commission_month: string},
     *   targets: list<array<string, mixed>>,
     *   referral: array{code: string, url: string, qr_url: string}|null,
     *   sponsor: array{id: int, name: string, dealer_code: string|null, phone: string|null}|null,
     *   wallet: array{available: string, pending_withdrawal: string, commission_waiting: array{total: string, amounts: list<array{ready_on: ?string, amount: string}>}},
     *   is_birthday: bool
     * }
     */
    public function for(User $user): array
    {
        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        // Business-day bounds are Asia/Dhaka; timestamps in the DB are UTC.
        $todayUtc = CarbonImmutable::now($tz)->startOfDay()->utc();
        $month = CommissionQualification::currentMonth();
        $monthStartUtc = CarbonImmutable::createFromFormat('!Y-m', $month, $tz)->utc();
        $rules = app(CommissionPolicy::class)->snapshot(false)['rules'];
        $profile = is_array($user->profile) ? $user->profile : [];

        $ownSalesToday = $this->purchaseTotal(
            Order::query()->where('user_id', $user->id)->where('created_at', '>=', $todayUtc),
        );
        // The team is matched inside the database, so a large downline is never loaded into PHP.
        $teamIds = ReferralTreePath::query()
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->select('descendant_id');
        $teamSalesToday = $this->purchaseTotal(
            Order::query()->whereIn('user_id', $teamIds)->where('created_at', '>=', $todayUtc),
        );
        $activeStatuses = ['pending', 'available', 'maturing', 'held', 'reserved', 'settled', 'partially_settled'];
        $commissionMonth = Money::cents((string) DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $monthStartUtc)
            ->whereIn('status', $activeStatuses)
            ->sum('amount'));
        $commissionToday = Money::cents((string) DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $todayUtc)
            ->whereIn('status', $activeStatuses)
            ->sum('amount'));

        return [
            'profile' => [
                'name' => $user->name,
                'role' => $user->role,
                'dealer_code' => $user->dealer_code,
                'phone' => $user->phone,
                'email' => $user->email,
                'shop_name' => $user->role === 'sub_dealer'
                    ? ($profile['shop_name'] ?? $profile['pharmacy'] ?? $profile['pharmacy_name'] ?? null)
                    : null,
                'area_code' => $profile['place_code'] ?? $profile['area_code'] ?? $user->dealer_code,
                'division' => $user->division,
                'district' => $user->district,
                'photo_url' => $profile['photo_url'] ?? $profile['avatar_url'] ?? null,
                'initial' => mb_strtoupper(mb_substr($user->name, 0, 1)),
                'status' => $user->status,
                'refer_code' => $user->refer_code,
            ],
            'snapshot' => [
                'own_sales_today' => Money::decimal($ownSalesToday),
                'team_sales_today' => Money::decimal($teamSalesToday),
                'commission_month' => Money::decimal($commissionMonth),
                'commission_today' => Money::decimal($commissionToday),
            ],
            'targets' => $this->targets($user, $rules, $month),
            'referral' => $user->refer_code ? [
                'code' => $user->refer_code,
                'url' => PortalHost::url('public', '/join/'.$user->refer_code),
                'qr_url' => '/referral-qr.svg',
            ] : null,
            'sponsor' => $this->sponsorCard($user),
            // Shown behind a "tap to see balance" button on the home screen.
            'wallet' => [...$this->walletSummary($user), 'commission_waiting' => $this->earnings->waitingCommission($user)],
            // Shows a small "happy birthday" card; read from the profile already loaded, no query.
            'is_birthday' => BirthdayGreetings::isBirthdayToday($user),
        ];
    }

    /**
     * Money ready to withdraw and money already asked for, in one query.
     *
     * @return array{available: string, pending_withdrawal: string}
     */
    private function walletSummary(User $user): array
    {
        $row = DB::query()->selectRaw(
            '(SELECT available FROM wallets WHERE user_id = ?) AS available, '
            ."(SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id = ? AND status IN ('requested', 'approved', 'pending')) AS pending",
            [$user->id, $user->id],
        )->first();

        return [
            'available' => Money::decimal(Money::cents((string) ($row->available ?? '0'))),
            'pending_withdrawal' => Money::decimal(Money::cents((string) ($row->pending ?? '0'))),
        ];
    }

    /**
     * @return array{id: int, name: string, dealer_code: string|null, phone: string|null}|null
     */
    private function sponsorCard(User $user): ?array
    {
        if ($user->role !== 'sub_dealer' || ! $user->sponsor_id) {
            return null;
        }
        $sponsor = User::query()
            ->whereKey($user->sponsor_id)
            ->where('status', 'active')
            ->first(['id', 'name', 'dealer_code', 'phone']);
        if (! $sponsor) {
            return null;
        }

        return [
            'id' => (int) $sponsor->id,
            'name' => (string) $sponsor->name,
            'dealer_code' => $sponsor->dealer_code !== null ? (string) $sponsor->dealer_code : null,
            'phone' => $sponsor->phone !== null ? (string) $sponsor->phone : null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function targets(User $user, array $rules, string $month): array
    {
        $targets = $user->role === 'sub_dealer'
            ? $this->subDealerTargets($user, $rules, $month)
            : $this->dealerTargets($user, $rules, $month);

        if (DashboardManagement::partnerFeatureEnabled($user, 'rewards')) {
            $bike = RewardMilestoneProgress::query()
                ->where('user_id', $user->id)
                ->where('milestone_type', 'bike')
                ->first();
            $bikeCurrent = Money::cents((string) ($bike === null
                ? $this->qualification->milestone($user->id, 'bike')['qualifying_volume']
                : $bike->qualifying_volume));
            $bikeTarget = Money::cents((string) $rules['car_bike_incentive']['parameters']['bike_target']);
            $targets[] = $this->progress(
                key: 'bike_incentive',
                label: __('portal.target_bike_incentive'),
                current: $bikeCurrent,
                target: $bikeTarget,
                hint: __('portal.target_bike_incentive_hint'),
            );
        }

        return $targets;
    }

    /**
     * Sub-dealers have no purchase target and no rank: their salary is a flat share of every
     * paid and delivered purchase, and a second share is kept in their own tour fund.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function subDealerTargets(User $user, array $rules, string $month): array
    {
        if (! DashboardManagement::partnerFeatureEnabled($user, 'salary')) {
            return [];
        }
        $amountRow = function (string $key, string $label, string $rule, int $value, string $hint) use ($rules): array {
            $isOn = CommissionPolicy::rate($rules, $rule)->basisPoints > 0;

            return [
                'key' => $key,
                'kind' => 'amount',
                'label' => $label,
                'value' => Money::decimal($value),
                'hint' => $isOn
                    ? __($hint, ['rate' => rtrim(rtrim((string) $rules[$rule]['percentage'], '0'), '.')])
                    : __('portal.target_switched_off_hint'),
            ];
        };

        return [
            $amountRow('sub_dealer_salary', __('portal.target_subdealer_salary'), 'sub_dealer_salary',
                $this->progress->subDealerSalarySetAside((int) $user->id, $month), 'portal.target_subdealer_salary_hint'),
            $amountRow('tour_fund', __('portal.target_tour_fund'), 'sub_dealer_tour',
                $this->progress->tourFundLeft((int) $user->id), 'portal.target_tour_fund_hint'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function dealerTargets(User $user, array $rules, string $month): array
    {
        $targets = [];
        $controls = PartnerBenefitControls::for($user);
        $monthlyVolume = $this->qualification->monthlyVolume($user->id, $month);
        $salaryTarget = Money::cents((string) ($rules['dealer_monthly_salary']['parameters']['min_monthly_volume'] ?? '10000.00'));
        if ($controls['unconditional_salary']) {
            $targets[] = [
                'key' => 'monthly_salary',
                'label' => __('portal.target_monthly_salary_volume'),
                'current' => Money::decimal($monthlyVolume),
                'target' => Money::decimal($salaryTarget),
                'remaining' => '0.00',
                'percent' => 100.0,
                'hint' => __('portal.target_unconditional_salary_hint'),
            ];
        } else {
            $targets[] = $this->progress(
                key: 'monthly_salary',
                label: __('portal.target_monthly_salary_volume'),
                current: $monthlyVolume,
                target: $salaryTarget,
                hint: __('portal.target_dealer_salary_hint'),
            );
        }

        // Read only: a page view never creates the rank row (posting creates it with the first purchase).
        $rank = DealerRank::query()->where('user_id', $user->id)->first();
        $currentRank = $rank->current_rank ?? 'dealer';
        $moTarget = Money::cents((string) ($rules['rank_royalty']['parameters']['mo_target'] ?? '250000.00'));
        $lifetime = Money::cents((string) ($rank->lifetime_purchase_total ?? '0'));

        if ($controls['unconditional_rank_royalty'] || $controls['unconditional_rank_incentive']) {
            $targets[] = [
                'key' => 'rank_reward',
                'label' => __('portal.target_rank_reward'),
                'current' => Money::decimal($lifetime),
                'target' => Money::decimal($moTarget),
                'remaining' => '0.00',
                'percent' => 100.0,
                'hint' => __('portal.target_unconditional_rank_hint'),
            ];
        } elseif ($currentRank === 'dealer') {
            $targets[] = $this->progress(
                key: 'rank_reward',
                label: __('portal.target_mo_rank_reward'),
                current: $lifetime,
                target: $moTarget,
                hint: __('portal.target_dealer_mo_hint'),
            );
        } else {
            $levels = CommissionQualification::RankLevels;
            $code = $levels[array_search($currentRank, $levels, true) + 1] ?? null;
            $requiredRank = $code !== null ? (CommissionQualification::TeamRankRequirements[$code] ?? null) : null;
            if ($code !== null && $requiredRank !== null) {
                $needed = (int) ($rules['rank_royalty']['parameters'][$code.'_count'] ?? 10);
                $depth = (int) ($rules['rank_royalty']['parameters']['qualification_depth'] ?? 3);
                // The same team count rank promotion uses.
                $counts = $this->qualification->teamRankCounts([(int) $user->id], $depth)[(int) $user->id] ?? [];
                $count = CommissionQualification::leadersAtOrAbove($counts, $requiredRank);
                $requiredLabel = strtoupper($requiredRank);
                $targets[] = [
                    'key' => 'rank_reward',
                    'label' => __('portal.target_named_rank_reward', ['rank' => strtoupper($code)]),
                    'current' => (string) $count,
                    'target' => (string) $needed,
                    'remaining' => (string) max(0, $needed - $count),
                    'percent' => $needed > 0 ? min(100, round(($count / $needed) * 100, 1)) : 0.0,
                    'hint' => __('portal.target_rank_leaders_hint', [
                        'needed' => $needed,
                        'rank' => $requiredLabel,
                        'depth' => $depth,
                    ]),
                ];
            } else {
                $targets[] = [
                    'key' => 'rank_reward',
                    'label' => __('portal.target_rank_reward'),
                    'current' => 'ME',
                    'target' => 'ME',
                    'remaining' => '0',
                    'percent' => 100.0,
                    'hint' => __('portal.target_highest_rank_hint'),
                ];
            }
        }

        return $targets;
    }

    /**
     * @return array{key: string, label: string, current: string, target: string, remaining: string, percent: float, hint: string}
     */
    private function progress(string $key, string $label, int $current, int $target, string $hint): array
    {
        $safeTarget = max(0, $target);
        $percent = $safeTarget > 0 ? min(100, round(($current / $safeTarget) * 100, 1)) : ($current > 0 ? 100.0 : 0.0);

        return [
            'key' => $key,
            'label' => $label,
            'current' => Money::decimal($current),
            'target' => Money::decimal($safeTarget),
            'remaining' => Money::decimal(max(0, $safeTarget - $current)),
            'percent' => $percent,
            'hint' => $hint,
        ];
    }

    private function purchaseTotal($query): int
    {
        $total = (clone $query)
            ->whereIn('status', DeliveredMoney::Statuses)
            ->sum(DB::raw(DeliveredMoney::valueSql()));

        return Money::cents((string) $total);
    }
}
