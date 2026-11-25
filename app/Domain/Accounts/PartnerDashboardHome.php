<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\DealerKinds;
use App\Domain\Commissions\MonthlyTargets;
use App\Domain\Finance\FundSpendPartners;
use App\Domain\Finance\Percentage;
use App\Domain\Notifications\BirthdayGreetings;
use App\Domain\Orders\DeliveredMoney;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PartnerCustomer;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use App\Support\PortalHost;
use App\Support\RankLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PartnerDashboardHome
{
    /** This month's volume and rank, kept from the dealer targets so the owner's overrides cost no further reads. */
    private int $targetsVolume = 0;

    private bool $targetsVolumeLoaded = false;

    private string $targetsRank = 'dealer';

    /** The rank row and the bike and car rows the targets already read, handed to the prize lines so they are not read again. Reset on every call of for(). */
    private ?DealerRank $rankRow = null;

    private bool $rankRowLoaded = false;

    /** @var Collection<string, RewardMilestoneProgress>|null */
    private ?Collection $vehicleRows = null;

    public function __construct(private CommissionQualification $qualification, private PartnerProgress $progress, private PartnerEarningsPresenter $earnings, private PartnerMoneyBook $moneyBook, private PartnerPendingMoney $pending) {}

    /**
     * @return array{
     *   profile: array<string, mixed>,
     *   snapshot: array{own_sales_today: string, team_sales_today: string, commission_month: string, commission_today: string},
     *   targets: list<array<string, mixed>>,
     *   referral: array{code: string, url: string, qr_url: string}|null,
     *   sponsor: array{id: int, name: string, dealer_code: string|null, phone: string|null}|null,
     *   wallet: array{available: string, pending_withdrawal: string, commission_waiting: array{total: string, amounts: list<array{ready_on: ?string, amount: string}>}, commission_held: array{total: string, count: int, lines: list<array<string, mixed>>}},
     *   is_birthday: bool,
     *   follow_up_due: int
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
        $this->rankRow = null;
        $this->rankRowLoaded = false;
        $this->targetsVolumeLoaded = false;
        $this->vehicleRows = null;
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
        $commissionMonth = $this->moneyBook->earnedTotal((int) $user->id, $monthStartUtc);
        $commissionToday = $this->moneyBook->earnedTotal((int) $user->id, $todayUtc);

        // Read once: the card shows it, and its kind of dealer sets the dealer's monthly target.
        $appointment = app(PartnerAppointment::class)->for($user);
        $targets = $this->targets($user, $rules, $month, DealerKinds::fromAppointmentKind($appointment['kind'] ?? null));
        // One read says whether the partner has any company-spend lines at all; the lines themselves are read only when they have.
        $wallet = $this->walletSummary($user);
        $hasBenefits = $wallet['benefit_rows'] > 0;
        unset($wallet['benefit_rows']);
        $rewardContext = [...$this->rewardContext($rules), 'kinds' => [(int) $user->id => DealerKinds::fromAppointmentKind($appointment['kind'] ?? null)]];

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
            'card' => $this->card($user, $profile, $appointment) + $this->identityTop($user, $targets, $month),
            'totals' => $this->totals($user),
            'targets' => $targets,
            'referral' => $user->refer_code && PartnerJoinLink::enabled() ? [
                'code' => $user->refer_code,
                'url' => PortalHost::url('public', '/join/'.$user->refer_code),
                'qr_url' => '/referral-qr.svg',
            ] : null,
            'sponsor' => $this->sponsorCard($user),
            // Shown behind a "tap to see balance" button on the home screen.
            'wallet' => [...$wallet, 'commission_waiting' => $this->earnings->waitingCommission($user), 'commission_held' => $this->moneyBook->heldCommission($user, 3, false), 'pending' => $this->pending->for($user, 1, false, $rewardContext), 'benefits' => $hasBenefits ? app(FundSpendPartners::class)->linesFor($user, 3) : FundSpendPartners::none()],
            // Shows a small "happy birthday" card; read from the profile already loaded, no query.
            'is_birthday' => BirthdayGreetings::isBirthdayToday($user),
            // Customers the partner meant to call today or earlier: the home page's "call today" card.
            'follow_up_due' => $this->followUpsDue($user),
        ];
    }

    private function followUpsDue(User $user): int
    {
        return PartnerCustomer::query()
            ->where('partner_id', $user->id)
            ->whereNotNull('follow_up_on')
            ->where('follow_up_on', '<=', CustomerBook::today())
            ->count();
    }

    /**
     * The profile card on the left of the dashboard: who the partner is, where, when they joined, and how many people they brought.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $appointment  PartnerAppointment::for($user), already read
     * @return array<string, mixed>
     */
    private function card(User $user, array $profile, array $appointment): array
    {
        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $counts = DB::table('referral_tree_paths AS p')
            ->join('users AS u', 'u.id', '=', 'p.descendant_id')
            ->where('p.ancestor_id', $user->id)
            ->selectRaw('COUNT(*) FILTER (WHERE p.depth = 1) AS direct, COUNT(*) FILTER (WHERE p.depth = 1 AND u.status = ?) AS gen1, COUNT(*) FILTER (WHERE p.depth = 2) AS gen2', ['active'])
            ->first();

        return [
            'name' => $user->name,
            'type' => $user->role,
            'photo_url' => $profile['photo_url'] ?? $profile['avatar_url'] ?? null,
            'initial' => mb_strtoupper(mb_substr((string) $user->name, 0, 1)),
            // The personal address (where the person lives); the appointed area is in 'appointment'.
            'area' => app(PartnerAreaLine::class)->for($user),
            'appointment' => $appointment,
            'phone' => $user->phone,
            'email' => $user->email,
            'joined_on' => $user->created_at?->timezone($tz)->format('d/m/Y'),
            // The shop name belongs to sub-dealers only; a dealer's card never shows one.
            'shop_name' => $user->role === 'sub_dealer'
                ? ($profile['shop_name'] ?? $profile['pharmacy'] ?? $profile['pharmacy_name'] ?? null)
                : null,
            'direct' => (int) ($counts->direct ?? 0),
            'gen1' => (int) ($counts->gen1 ?? 0),
            'gen2' => (int) ($counts->gen2 ?? 0),
        ];
    }

    /**
     * The extra boxes at the top of the dashboard: code, rank, this month's purchase and what is left of the target.
     * Every figure is one the dashboard already works out; nothing is calculated a new way here.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return array{code: string|null, rank: string|null, month_purchase: string, target_left: string|null}
     */
    private function identityTop(User $user, array $targets, string $month): array
    {
        $isDealer = $user->role === 'dealer';
        $volume = $this->targetsVolumeLoaded ? $this->targetsVolume : $this->qualification->monthlyVolume($user->id, $month);
        $salary = collect($targets)->firstWhere('key', 'monthly_salary');

        return [
            'code' => $user->dealer_code,
            'rank' => $isDealer || $this->rankRowLoaded ? $this->targetsRank : null,
            'month_purchase' => Money::decimal($volume),
            'target_left' => $isDealer && is_array($salary) ? (string) $salary['remaining'] : null,
        ];
    }

    /**
     * The three figures at the top of the middle column. "Withdrawn" is every taka that left the wallet:
     * paid out as cash and used to pay for orders.
     *
     * @return array{income: string, withdrawn: string, balance: string}
     */
    private function totals(User $user): array
    {
        $summary = $this->moneyBook->summary($user);
        $out = Money::cents($summary['lifetime']['withdrawn']) + Money::cents($summary['lifetime']['spent']);

        return [
            'income' => $summary['lifetime']['earned'],
            'withdrawn' => Money::decimal($out),
            'balance' => $summary['available'],
        ];
    }

    /**
     * What the dashboard has already read that the prize lines also need (the rules, the rank row, the bike and car rows), so they are not read twice.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array{rules: array<string, array<string, mixed>>, rank?: ?DealerRank, milestones?: Collection<string, RewardMilestoneProgress>}
     */
    private function rewardContext(array $rules): array
    {
        $context = ['rules' => $rules];
        if ($this->rankRowLoaded) {
            $context['rank'] = $this->rankRow;
        }
        if ($this->vehicleRows !== null) {
            $context['milestones'] = $this->vehicleRows;
        }

        return $context;
    }

    /**
     * Money ready to withdraw and money already asked for, in one query.
     *
     * The same read also counts the partner's company-spend lines (benefit_rows), so those are read only when there are any.
     *
     * @return array{available: string, pending_withdrawal: string, benefit_rows: int}
     */
    private function walletSummary(User $user): array
    {
        $row = DB::query()->selectRaw(
            '(SELECT available FROM wallets WHERE user_id = ?) AS available, '
            ."(SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id = ? AND status IN ('requested', 'approved', 'pending')) AS pending, "
            .'(SELECT COUNT(*) FROM fund_spend_partners WHERE user_id = ?) AS benefit_rows',
            [$user->id, $user->id, $user->id],
        )->first();

        return [
            'available' => Money::decimal(Money::cents((string) ($row->available ?? '0'))),
            'pending_withdrawal' => Money::decimal(Money::cents((string) ($row->pending ?? '0'))),
            'benefit_rows' => (int) ($row->benefit_rows ?? 0),
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
    private function targets(User $user, array $rules, string $month, ?string $kind): array
    {
        $targets = $user->role === 'sub_dealer'
            ? $this->subDealerTargets($user, $rules, $month)
            : $this->dealerTargets($user, $rules, $month, $kind);

        if (DashboardManagement::partnerFeatureEnabled($user, 'rewards')) {
            // The bike and car rows are read together, so the prize lines can use the same read.
            $this->vehicleRows = RewardMilestoneProgress::query()
                ->where('user_id', $user->id)
                ->whereIn('milestone_type', ['bike', 'car'])
                ->get()->keyBy('milestone_type');
            // One reward row at a time: the bike first; the car starts only once the bike target is full.
            $vehicleParams = PartnerBenefitControls::effectiveVehicleParameters($user, $rules);
            $bikeTarget = Money::cents((string) $vehicleParams['bike_target']);
            $bikeCurrent = $this->vehicleVolume($user, 'bike');
            if ($bikeTarget > 0 && $bikeCurrent >= $bikeTarget) {
                $targets[] = $this->progress(
                    key: 'car_incentive',
                    label: __('portal.target_car_incentive'),
                    current: $this->vehicleVolume($user, 'car'),
                    target: Money::cents((string) $vehicleParams['car_target']),
                    hint: __('portal.target_car_incentive_hint'),
                );
            } else {
                $targets[] = $this->progress(
                    key: 'bike_incentive',
                    label: __('portal.target_bike_incentive'),
                    current: $bikeCurrent,
                    target: $bikeTarget,
                    hint: __('portal.target_bike_incentive_hint'),
                );
            }
        }

        // The owner's own target for this dealer this month is put into the rows by the targets class.
        $targets = $user->role === 'dealer'
            ? app(PartnerMonthlyTargets::class)->forHome($user, $targets, $month)
            : $targets;

        return $this->withSalaryState($targets);
    }

    /**
     * A sub-dealer's card: what he bought this month, his salary step (owner's rule of 2026-11-21: 1%, 2% or 3% of his
     * own month's purchase from ৳10,000, ৳20,000 and ৳40,000), his own target for the rank royalty and his next rank
     * (the tour fund only when ShowTourFund is on).
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function subDealerTargets(User $user, array $rules, string $month): array
    {
        $ranks = MonthlyTargets::subDealersEarnRanks($rules) ? [$this->rankTarget($user, $rules)] : [];
        if (! DashboardManagement::partnerFeatureEnabled($user, 'salary')) {
            return $ranks;
        }
        $monthlyVolume = $this->qualification->monthlyVolume($user->id, $month);
        $this->targetsVolume = $monthlyVolume;
        $this->targetsVolumeLoaded = true;
        $controls = PartnerBenefitControls::for($user);
        $effectiveRules = PartnerBenefitControls::effectiveSubDealerRules($user, $rules);
        $isOn = CommissionPolicy::rate($rules, 'sub_dealer_salary')->basisPoints > 0;

        $rows = [
            [
                'key' => 'monthly_purchase',
                'kind' => 'amount',
                'label' => __('portal.target_monthly_progress'),
                'value' => Money::decimal($monthlyVolume),
                'hint' => __('portal.target_subdealer_progress_hint'),
            ],
            $controls['unconditional_salary']
                ? [
                    'key' => 'sub_dealer_salary',
                    'kind' => 'amount',
                    'label' => __('portal.target_subdealer_salary'),
                    'value' => Money::decimal($this->progress->subDealerSalarySetAside((int) $user->id, $month)),
                    'hint' => __('portal.target_unconditional_salary_hint'),
                ]
                : (MonthlyTargets::salaryTiers($effectiveRules) !== []
                    ? self::salaryTierRow($effectiveRules, $monthlyVolume, $isOn)
                    : [
                        'key' => 'sub_dealer_salary',
                        'kind' => 'amount',
                        'label' => __('portal.target_subdealer_salary'),
                        'value' => Money::decimal($this->progress->subDealerSalarySetAside((int) $user->id, $month)),
                        'hint' => $isOn
                            ? __('portal.target_subdealer_salary_hint', ['rate' => rtrim(rtrim((string) $rules['sub_dealer_salary']['percentage'], '0'), '.')])
                            : __('portal.target_switched_off_hint'),
                    ]),
        ];
        if (MonthlyTargets::subDealersEarnRanks($rules)) {
            $rows[] = $this->progress(
                key: 'sub_dealer_royalty',
                label: __('portal.target_subdealer_royalty'),
                current: $monthlyVolume,
                target: PartnerBenefitControls::effectiveSubDealerRoyaltyTarget($user, $rules),
                hint: CommissionPolicy::rate($rules, 'rank_royalty')->basisPoints > 0 ? __('portal.target_subdealer_royalty_hint') : __('portal.target_switched_off_hint'),
            );
            array_push($rows, ...$ranks);
        }
        // Hidden until the company decides to show the sub-dealer tour fund again (see PartnerProgress::ShowTourFund).
        // @phpstan-ignore if.alwaysFalse (a code switch, off until the company shows the tour fund again)
        if (PartnerProgress::ShowTourFund) {
            $isTourOn = CommissionPolicy::rate($rules, 'sub_dealer_tour')->basisPoints > 0;
            $rows[] = [
                'key' => 'tour_fund',
                'kind' => 'amount',
                'label' => __('portal.target_tour_fund'),
                'value' => Money::decimal($this->progress->tourFundLeft((int) $user->id)),
                'hint' => $isTourOn
                    ? __('portal.target_tour_fund_hint', ['rate' => rtrim(rtrim((string) $rules['sub_dealer_tour']['percentage'], '0'), '.')])
                    : __('portal.target_switched_off_hint'),
            ];
        }

        return $rows;
    }

    /**
     * The salary row of a dealer says in words whether this month's salary is on the way. It is worked out from the
     * monthly purchase row (after the owner's own target for this dealer is applied), so both always agree.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    private function withSalaryState(array $targets): array
    {
        $progress = collect($targets)->firstWhere('key', 'monthly_salary');
        foreach ($targets as $index => $row) {
            if (($row['key'] ?? '') !== 'salary_state' || ! is_array($progress)) {
                continue;
            }
            $state = match (true) {
                $row['state'] === 'off', $row['state'] === 'waived' => $row['state'],
                Money::cents((string) $progress['remaining']) === 0 => 'met',
                default => 'not_met',
            };
            $targets[$index] = [
                ...$row,
                'state' => $state,
                'value' => __('portal.target_salary_state_'.$state),
                'hint' => __('portal.target_salary_state_'.$state.'_hint'),
            ];
        }

        return $targets;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function dealerTargets(User $user, array $rules, string $month, ?string $kind): array
    {
        $targets = [];
        $controls = PartnerBenefitControls::for($user);
        $monthlyVolume = $this->qualification->monthlyVolume($user->id, $month);
        $this->targetsVolume = $monthlyVolume;
        $this->targetsVolumeLoaded = true;
        // The dealer's one monthly target by kind of dealer (owner's rule of 2026-11-21) or custom override.
        $salaryTarget = PartnerBenefitControls::effectiveDealerMonthlyTarget($user, $rules, $kind);
        $monthHint = MonthlyTargets::usesMonthlyTargets($rules)
            ? ($kind !== null ? __('portal.target_dealer_month_hint', ['kind' => __('portal.target_kind_'.$kind)]) : __('portal.target_dealer_month_no_area_hint'))
            : __('portal.target_dealer_salary_hint');
        if ($controls['unconditional_salary']) {
            $targets[] = [
                'key' => 'monthly_salary',
                'label' => __('portal.target_monthly_progress'),
                'current' => Money::decimal($monthlyVolume),
                'target' => Money::decimal($salaryTarget),
                'remaining' => '0.00',
                'percent' => 100.0,
                'hint' => __('portal.target_unconditional_salary_hint'),
            ];
        } else {
            $targets[] = $this->progress(
                key: 'monthly_salary',
                label: __('portal.target_monthly_progress'),
                current: $monthlyVolume,
                target: $salaryTarget,
                hint: $monthHint,
            );
        }

        $targets[] = [
            'key' => 'salary_state',
            'kind' => 'status',
            'label' => __('portal.target_monthly_salary'),
            'state' => match (true) {
                CommissionPolicy::rate($rules, 'dealer_monthly_salary')->basisPoints <= 0 => 'off',
                $controls['unconditional_salary'] => 'waived',
                default => 'not_met',
            },
            'value' => '',
            'hint' => '',
        ];
        $targets[] = $this->rankTarget($user, $rules, $controls);

        return $targets;
    }

    /**
     * The sub-dealer's salary step as a progress row: his month's purchase toward the next step, with the percent he
     * earns now in the hint (and as tier_pct).
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function salaryTierRow(array $rules, int $volume, bool $isOn): array
    {
        $tiers = MonthlyTargets::salaryTiers($rules);
        $earned = (int) MonthlyTargets::salaryBasisPoints($rules, $volume);
        $next = collect($tiers)->first(fn (array $tier): bool => $tier['volume'] > $volume);
        $pct = fn (int $basisPoints): string => rtrim(rtrim(Percentage::fromBasisPoints($basisPoints)->decimal(), '0'), '.');
        // Whole takas read better without ".00"; the page turns the digits into Bangla.
        $taka = fn (int $cents): string => (string) preg_replace('/\.00$/', '', Money::grouped(Money::decimal($cents)));
        $hint = match (true) {
            ! $isOn => __('portal.target_switched_off_hint'),
            $next === null => __('portal.target_subdealer_tier_top_hint', ['pct' => $pct($earned)]),
            $earned === 0 => __('portal.target_subdealer_tier_none_hint', ['next' => $taka($next['volume']), 'next_pct' => $pct($next['basis_points'])]),
            default => __('portal.target_subdealer_tier_hint', ['pct' => $pct($earned), 'next' => $taka($next['volume']), 'next_pct' => $pct($next['basis_points'])]),
        };

        return [
            ...$this->progress(
                key: 'sub_dealer_salary',
                label: __('portal.target_subdealer_salary_tiers'),
                current: $volume,
                target: $next['volume'] ?? $tiers[count($tiers) - 1]['volume'],
                hint: $hint,
            ),
            'tier_pct' => Percentage::fromBasisPoints($isOn ? $earned : 0)->decimal(),
        ];
    }

    /**
     * The rank row of a dealer or sub-dealer: own purchases toward MO, then the direct-referral lines toward the next rank.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, bool|int|string|null>|null  $controls  PartnerBenefitControls::for($user) when already read
     * @return array<string, mixed>
     */
    private function rankTarget(User $user, array $rules, ?array $controls = null): array
    {
        $controls ??= PartnerBenefitControls::for($user);
        // Read only: a page view never creates the rank row (posting creates it with the first purchase).
        $rank = DealerRank::query()->where('user_id', $user->id)->first();
        $this->rankRow = $rank;
        $this->rankRowLoaded = true;
        $currentRank = $rank->current_rank ?? 'dealer';
        $this->targetsRank = $currentRank;
        $rankParameters = PartnerBenefitControls::effectiveRankParameters($user, $rules);
        $moTarget = Money::cents((string) ($rankParameters['mo_target'] ?? '500000.00'));
        $lifetime = Money::cents((string) ($rank->lifetime_purchase_total ?? '0'));
        $targets = [];

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
                $needed = (int) ($rankParameters[$code.'_count'] ?? 10);
                $depth = (int) ($rankParameters['qualification_depth'] ?? 3);
                // The same team count rank promotion uses.
                $counts = $this->qualification->teamRankCounts([(int) $user->id], $depth)[(int) $user->id] ?? [];
                $count = CommissionQualification::leadersAtOrAbove($counts, $requiredRank);
                $requiredLabel = RankLabel::of($requiredRank);
                $targets[] = [
                    'key' => 'rank_reward',
                    'label' => __('portal.target_named_rank_reward', ['rank' => RankLabel::of($code)]),
                    'current' => (string) $count,
                    'target' => (string) $needed,
                    'remaining' => (string) max(0, $needed - $count),
                    'percent' => $needed > 0 ? min(100, round(($count / $needed) * 100, 1)) : 0.0,
                    'hint' => __('portal.target_rank_lines_hint', [
                        'needed' => $needed,
                        'rank' => $requiredLabel,
                        'depth' => $depth,
                    ]),
                ];
            } else {
                $targets[] = [
                    'key' => 'rank_reward',
                    'label' => __('portal.target_rank_reward'),
                    'current' => RankLabel::of('me'),
                    'target' => RankLabel::of('me'),
                    'remaining' => '0',
                    'percent' => 100.0,
                    'hint' => __('portal.target_highest_rank_hint'),
                ];
            }
        }

        return $targets[0];
    }

    /** The team volume that counts toward the bike or the car, from the row already read or worked out when there is none yet. */
    private function vehicleVolume(User $user, string $type): int
    {
        $row = $this->vehicleRows?->get($type);

        return Money::cents((string) ($row === null
            ? $this->qualification->milestone($user->id, $type)['qualifying_volume']
            : $row->qualifying_volume));
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
            ->whereRaw(DeliveredMoney::countedSql())
            ->sum(DB::raw(DeliveredMoney::valueSql()));

        return Money::cents((string) $total);
    }
}
