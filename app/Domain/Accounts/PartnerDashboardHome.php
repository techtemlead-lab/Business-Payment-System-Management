<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PartnerDashboardHome
{
    public function __construct(private CommissionQualification $qualification) {}

    /**
     * @return array{
     *   profile: array<string, mixed>,
     *   snapshot: array{own_sales_today: string, team_sales_today: string, commission_month: string},
     *   chart: list<array{key: string, label: string, value: string, cents: int, color: string}>,
     *   targets: list<array{key: string, label: string, current: string, target: string, remaining: string, percent: float, hint: string}>,
     *   referral: array{code: string, url: string, hint: string}|null
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
        $teamIds = ReferralTreePath::query()
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->pluck('descendant_id');
        $teamSalesToday = $teamIds->isEmpty()
            ? 0
            : $this->purchaseTotal(
                Order::query()->whereIn('user_id', $teamIds)->where('created_at', '>=', $todayUtc),
            );
        $commissionMonth = Money::cents((string) DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $monthStartUtc)
            ->whereIn('status', ['pending', 'available', 'maturing', 'held', 'reserved', 'settled', 'partially_settled'])
            ->sum('amount'));

        $chart = [
            ['key' => 'own', 'label' => 'Today purchase', 'value' => Money::decimal($ownSalesToday), 'cents' => $ownSalesToday, 'color' => '#2e3192'],
            ['key' => 'team', 'label' => 'Team sales', 'value' => Money::decimal($teamSalesToday), 'cents' => $teamSalesToday, 'color' => '#0f766e'],
            ['key' => 'commission', 'label' => 'Commission (month)', 'value' => Money::decimal($commissionMonth), 'cents' => $commissionMonth, 'color' => '#ef4a23'],
        ];

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
            ],
            'chart' => $chart,
            'targets' => $this->targets($user, $rules, $month),
            'referral' => $user->refer_code ? [
                'code' => $user->refer_code,
                'url' => PortalHost::url('public', '/join/'.$user->refer_code),
                'hint' => 'Share this link so new partners apply under your referral code.',
            ] : null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{key: string, label: string, current: string, target: string, remaining: string, percent: float, hint: string}>
     */
    private function targets(User $user, array $rules, string $month): array
    {
        $targets = [];
        $controls = PartnerBenefitControls::for($user);
        $monthlyVolume = $this->qualification->monthlyVolume($user->id, $month);
        $salaryTarget = Money::cents((string) ($rules['dealer_monthly_salary']['parameters']['min_monthly_volume'] ?? '10000.00'));
        if ($controls['unconditional_salary']) {
            $targets[] = [
                'key' => 'monthly_salary',
                'label' => $user->role === 'sub_dealer' ? 'Monthly purchase goal' : 'Monthly salary volume',
                'current' => Money::decimal($monthlyVolume),
                'target' => Money::decimal($salaryTarget),
                'remaining' => '0.00',
                'percent' => 100.0,
                'hint' => 'Admin granted unconditional salary access — volume target is waived.',
            ];
        } else {
            $targets[] = $this->progress(
                key: 'monthly_salary',
                label: $user->role === 'sub_dealer' ? 'Monthly purchase goal' : 'Monthly salary volume',
                current: $monthlyVolume,
                target: $salaryTarget,
                hint: $user->role === 'sub_dealer'
                    ? 'Buy this much more from the company this month to stay on a healthy activity track.'
                    : 'Remaining company purchase needed this month to qualify for the monthly salary pool.',
            );
        }

        $rank = DealerRank::query()->firstOrCreate(['user_id' => $user->id]);
        $moTarget = Money::cents((string) ($rules['rank_royalty']['parameters']['mo_target'] ?? '250000.00'));
        $lifetime = Money::cents((string) $rank->lifetime_purchase_total);

        if ($controls['unconditional_rank_royalty'] || $controls['unconditional_rank_incentive']) {
            $targets[] = [
                'key' => 'rank_reward',
                'label' => 'Rank reward',
                'current' => Money::decimal($lifetime),
                'target' => Money::decimal($moTarget),
                'remaining' => '0.00',
                'percent' => 100.0,
                'hint' => 'Admin granted unconditional rank benefit access for this partner.',
            ];
        } elseif ($user->role === 'sub_dealer' || ($rank->current_rank ?? 'dealer') === 'dealer') {
            $targets[] = $this->progress(
                key: 'rank_reward',
                label: 'MO rank reward',
                current: $lifetime,
                target: $moTarget,
                hint: $user->role === 'sub_dealer'
                    ? 'Cumulative own purchases toward the Marketing Officer (৳2.5L) rank reward track.'
                    : 'Cumulative own purchases required to promote to Marketing Officer.',
            );
        } else {
            $next = match ($rank->current_rank) {
                'mo' => ['am', 'am_count', 'mo'],
                'am' => ['sm', 'sm_count', 'am'],
                'sm' => ['me', 'me_count', 'sm'],
                default => null,
            };
            if ($next) {
                [$code, $countKey, $requiredRank] = $next;
                $needed = (int) ($rules['rank_royalty']['parameters'][$countKey] ?? 10);
                $depth = (int) ($rules['rank_royalty']['parameters']['qualification_depth'] ?? 3);
                $levels = ['dealer', 'mo', 'am', 'sm', 'me'];
                $minimumIndex = (int) array_search($requiredRank, $levels, true);
                $count = DB::table('referral_tree_paths')
                    ->join('dealer_ranks', 'dealer_ranks.user_id', '=', 'referral_tree_paths.descendant_id')
                    ->join('users', 'users.id', '=', 'dealer_ranks.user_id')
                    ->where('ancestor_id', $user->id)
                    ->whereBetween('depth', [1, $depth])
                    ->where('users.status', 'active')
                    ->where('users.role', 'dealer')
                    ->whereIn('current_rank', array_slice($levels, $minimumIndex))
                    ->count();
                $requiredLabel = strtoupper($requiredRank);
                $targets[] = [
                    'key' => 'rank_reward',
                    'label' => strtoupper($code).' rank reward',
                    'current' => (string) $count,
                    'target' => (string) $needed,
                    'remaining' => (string) max(0, $needed - $count),
                    'percent' => $needed > 0 ? min(100, round(($count / $needed) * 100, 1)) : 0.0,
                    'hint' => "Need {$needed} {$requiredLabel} leaders in {$depth}-gen downline.",
                ];
            } else {
                $targets[] = [
                    'key' => 'rank_reward',
                    'label' => 'Rank reward',
                    'current' => 'ME',
                    'target' => 'ME',
                    'remaining' => '0',
                    'percent' => 100.0,
                    'hint' => 'Highest rank achieved.',
                ];
            }
        }

        $bike = $this->qualification->milestone($user->id, 'bike');
        $targets[] = $this->progress(
            key: 'bike_incentive',
            label: 'Bike incentive',
            current: Money::cents((string) $bike['qualifying_volume']),
            target: Money::cents((string) $bike['target_amount']),
            hint: 'Qualified team volume toward the motorcycle incentive (leg caps applied).',
        );

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
            ->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])
            ->sum('total');

        return Money::cents((string) $total);
    }
}
