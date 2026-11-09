<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Orders\OrderListFilter;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\RewardMilestoneProgress;
use App\Support\ListPage;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Commission policy and payouts: rules and pools, ranks, rewards, monthly settlements and the simulator.
 */
final class CommissionsSection implements WorkspaceSection
{
    /** Rank codes, lowest first. */
    private const RankCodes = ['dealer', 'mo', 'am', 'sm', 'me'];

    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'commissions' => $this->commissions($context),
            'ranks' => $this->ranks($context),
            'rewards' => $this->rewards($context->request),
            'settlements' => $this->settlements($context->request),
            'simulator' => $this->simulator(),
            default => [],
        };
    }

    /**
     * Commission rules and pool limits. The page shows only the rules, so no allocation rows are loaded.
     *
     * @return array<string, mixed>
     */
    private function commissions(SectionContext $context): array
    {
        $props = [];
        $commissionSnapshot = app(CommissionPolicy::class)->snapshot(false);
        $props['rules'] = array_values($commissionSnapshot['rules']);
        $props['policy_limits'] = $this->commissionPoolLimits($commissionSnapshot);

        return $props;
    }

    /**
     * Dealer ranks with a count per rank and the rank policy.
     *
     * @return array<string, mixed>
     */
    private function ranks(SectionContext $context): array
    {
        $user = $context->user;
        $request = $context->request;
        $props = [];
        $mapRank = fn (DealerRank $rank): array => [
            'id' => $rank->id,
            'user_id' => $rank->user_id,
            'name' => $rank->user?->name,
            'dealer_code' => $rank->user?->dealer_code,
            'phone' => $rank->user?->phone,
            'district' => $rank->user?->district,
            'division' => $rank->user?->division,
            'current_rank' => $rank->current_rank,
            'achieved_at' => $rank->achieved_at?->toIso8601String(),
            'lifetime_purchase_total' => (float) $rank->lifetime_purchase_total,
            'monthly_purchase_total' => (float) $rank->monthly_purchase_total,
            'one_time_incentive_paid' => (bool) $rank->one_time_incentive_paid,
            'qualification_history' => collect($rank->qualification_history ?? [])
                ->filter(fn ($entry) => is_array($entry) && isset($entry['rank'], $entry['achieved_at']))
                ->map(fn ($entry) => [
                    'rank' => (string) $entry['rank'],
                    'achieved_at' => (string) $entry['achieved_at'],
                ])
                ->values()
                ->all(),
        ];
        $query = DealerRank::with('user:id,name,dealer_code,phone,district,division,sponsor_id');
        if (! $context->isStaff) {
            $props['ranks'] = $query->where('user_id', $user->id)->get()->map($mapRank);
            $counts = collect($props['ranks'])->countBy('current_rank')->all();
        } else {
            // Staff get 25 leaders a page, searched and filtered on the server; the rank counts cover everyone.
            // Staff accounts added in the past also got a rank row, so only partners are listed and counted.
            $partnersOnly = fn (Builder $ranks) => $ranks->whereHas('user', fn (Builder $users) => $users->whereIn('role', ['dealer', 'sub_dealer']));
            $search = ListPage::query($request);
            $rankFilter = in_array($request->query('rank'), self::RankCodes, true) ? (string) $request->query('rank') : null;
            $props['ranks'] = $query
                ->tap($partnersOnly)
                ->when($search !== '', fn ($q) => $q->whereHas('user', function ($users) use ($search): void {
                    $like = '%'.OrderListFilter::escapeLike($search).'%';
                    $users->where(fn ($match) => $match->where('name', 'ilike', $like)->orWhere('dealer_code', 'ilike', $like)->orWhere('phone', 'like', $like));
                }))
                ->when($rankFilter !== null, fn ($q) => $q->where('current_rank', $rankFilter))
                ->orderByDesc('lifetime_purchase_total')
                ->orderBy('id')
                ->paginate(ListPage::PerPage)
                ->withQueryString()
                ->through($mapRank);
            $counts = DealerRank::query()->tap($partnersOnly)->groupBy('current_rank')->selectRaw('current_rank, COUNT(*) AS total')->pluck('total', 'current_rank')->all();
        }
        $props['stats'] = [];
        foreach (self::RankCodes as $code) {
            $props['stats'][$code.'_count'] = (int) ($counts[$code] ?? 0);
        }
        $policy = app(CommissionPolicy::class)->snapshot()['rules'];
        $props['rank_policy'] = [
            'qualification' => $policy['rank_royalty']['parameters'],
            'incentives' => $policy['rank_incentive']['parameters'],
            'pool_pct' => CommissionPolicy::rate($policy, 'rank_royalty')->decimal(),
        ];

        return $props;
    }

    /**
     * Bike and car reward milestones with their rule parameters.
     *
     * @return array<string, mixed>
     */
    private function rewards(Request $request): array
    {
        $props = [];
        $mapMilestone = fn ($row) => [...$row->toArray(), 'target_volume' => $row->target_amount, 'is_claimed' => $row->claimed,
            'capped_leg_breakdown' => array_map(fn ($leg) => [...$leg, 'leg_user_id' => $leg['user_id'] ?? $leg['dealer_id'],
                'leg_user_name' => $leg['name'] ?? $leg['dealer_name'] ?? '', 'capped_volume' => $leg['qualifying_volume']], $row->leg_breakdown ?? [])];
        // Only the tab on screen is sent, 25 leaders a page, searched on the server; each tab's count covers everyone.
        $type = $request->query('type') === 'car' ? 'car' : 'bike';
        $search = ListPage::query($request);
        $props['milestone_type'] = $type;
        $props['milestone_counts'] = RewardMilestoneProgress::query()->groupBy('milestone_type')->selectRaw('milestone_type, COUNT(*) AS total')->pluck('total', 'milestone_type')
            ->map(fn ($total): int => (int) $total)->all() + ['bike' => 0, 'car' => 0];
        $props['milestones'] = RewardMilestoneProgress::where('milestone_type', $type)
            ->with('user:id,name,dealer_code,phone,district')
            ->when($search !== '', fn ($q) => $q->whereHas('user', function ($users) use ($search): void {
                $like = '%'.OrderListFilter::escapeLike($search).'%';
                $users->where(fn ($match) => $match->where('name', 'ilike', $like)->orWhere('dealer_code', 'ilike', $like)->orWhere('phone', 'like', $like)->orWhere('district', 'ilike', $like));
            }))
            ->orderByDesc('qualifying_volume')
            ->orderBy('id')
            ->paginate(ListPage::PerPage)
            ->withQueryString()
            ->through($mapMilestone);
        $props['rule_params'] = app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive']['parameters'];

        return $props;
    }

    /**
     * Pending salary and rank pools for the settlement month, and who qualifies.
     *
     * @return array<string, mixed>
     */
    private function settlements(Request $request): array
    {
        $props = [];
        $search = ListPage::query($request);
        $currentMonth = $request->input('period_month', now('Asia/Dhaka')->subMonthNoOverflow()->format('Y-m'));
        CommissionQualification::period($currentMonth);
        $salaryPoolAllocations = CommissionAllocation::whereIn('category', ['dealer_monthly_salary_pool', 'sub_dealer_salary'])->where('metadata->period_month', $currentMonth)
            ->where('status', 'pending')
            ->get();
        $rankPoolAllocations = CommissionAllocation::where('category', 'rank_royalty_pool')->where('metadata->period_month', $currentMonth)
            ->where('status', 'pending')
            ->get();
        $maturing = CommissionAllocation::where('status', 'maturing')
            ->whereNotNull('user_id')
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount')
            ->first();

        // Built by the same code that pays, so the list staff check is the list that gets paid.
        $salaryPlan = app(CommissionSettlement::class)->salaryPlan($currentMonth, $salaryPoolAllocations);

        $qualification = app(CommissionQualification::class);
        $rankCandidates = DealerRank::whereIn('current_rank', ['mo', 'am', 'sm', 'me'])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with('user:id,name,dealer_code,phone,district')
            ->get();
        $volumeByUser = $qualification->monthlyVolumesFor($rankCandidates->pluck('user_id')->unique()->values()->all(), $currentMonth);

        // One batch evaluation for every leader instead of a policy, volume, rank and user lookup per leader.
        $paidAsByUser = $qualification->paidAsFor($rankCandidates->pluck('user_id')->all(), $currentMonth);
        $qualifiedRankLeaders = $rankCandidates->map(function ($rank) use ($paidAsByUser, $volumeByUser) {
            $eval = $paidAsByUser[(int) $rank->user_id];

            return [
                'id' => $rank->id,
                'user_id' => $rank->user_id,
                'name' => $rank->user?->name,
                'dealer_code' => $rank->user?->dealer_code,
                'rank' => $rank->current_rank,
                'paid_as_rank' => $eval['paid_as_rank'],
                'is_qualified' => $eval['is_qualified'],
                'in_grace_period' => $eval['in_grace_period'],
                'monthly_volume' => (float) ($eval['monthly_volume'] ?? Money::decimal($volumeByUser[$rank->user_id] ?? 0)),
                'one_time_incentive_paid' => (bool) $rank->one_time_incentive_paid,
            ];
        });

        $settledHistory = DB::table('journal_entries')
            ->where('event_key', 'like', 'commissions.%')
            ->latest()
            ->limit(20)
            ->get();

        $incentiveParameters = app(CommissionPolicy::class)->snapshot()['rules']['rank_incentive']['parameters'] ?? [];
        $rankIncentives = [];
        foreach (['mo', 'am', 'sm', 'me'] as $rankCode) {
            $bonus = $incentiveParameters[$rankCode.'_bonus'] ?? null;
            $rankIncentives[$rankCode] = is_numeric($bonus) ? Money::decimal(Money::cents((string) $bonus)) : null;
        }

        $props['settlement_state'] = [
            'current_month' => $currentMonth,
            'rank_incentives' => $rankIncentives,
            'salary_pool_pending' => (float) $salaryPoolAllocations->sum('amount'),
            'dealer_salary_pool_pending' => (float) $salaryPoolAllocations->where('category', 'dealer_monthly_salary_pool')->sum('amount'),
            'sub_dealer_salary_pending' => (float) $salaryPoolAllocations->where('category', 'sub_dealer_salary')->sum('amount'),
            'salary_pool_allocations_count' => $salaryPoolAllocations->count(),
            'qualified_salary_dealers_count' => count($salaryPlan['dealers']),
            // Lists come 25 to a page (searched on the server); the counts and pool figures above cover everyone.
            'qualified_salary_dealers' => ListPage::of(ListPage::search(array_map(fn (array $d): array => [
                'id' => $d['user_id'],
                'user_id' => $d['user_id'],
                'name' => $d['name'],
                'dealer_code' => $d['dealer_code'],
                'division' => $d['division'],
                'monthly_volume' => (float) $d['monthly_volume'],
                'share' => $d['share'],
            ], $salaryPlan['dealers']), $search, ['name', 'dealer_code', 'division']), $request, 'salary_page'),
            'salary_divisions' => $salaryPlan['divisions'],
            'salary_to_company_profit' => $salaryPlan['to_company_profit'],
            'rank_pool_pending' => (float) $rankPoolAllocations->sum('amount'),
            'rank_pool_allocations_count' => $rankPoolAllocations->count(),
            'qualified_rank_leaders_count' => $qualifiedRankLeaders->where('paid_as_rank', '!=', 'dealer')->count(),
            'qualified_rank_leaders' => ListPage::of(ListPage::search($qualifiedRankLeaders, $search, ['name', 'dealer_code', 'rank']), $request, 'leaders_page'),
            'search' => $search,
            'maturing_count' => (int) ($maturing->total ?? 0),
            'maturing_amount' => (float) ($maturing->amount ?? 0),
            'settled_history' => $settledHistory,
        ];

        return $props;
    }

    /**
     * A plan simulation with the active rules and pool limits.
     *
     * @return array<string, mixed>
     */
    private function simulator(): array
    {
        $props = [];
        $commissionService = app(CommissionService::class);
        $props['simulation'] = $commissionService->simulatePlan([]);
        $props['active_rules'] = CommissionRuleParameter::orderBy('id')->get();
        $props['policy_limits'] = $this->commissionPoolLimits();

        return $props;
    }

    /**
     * Pool limits the commission policy enforces, so policy screens show the live
     * ceiling instead of a number typed into the page. The two ceilings mirror
     * CommissionPolicy::validateSchedule(); the company share comes from the snapshot.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array{dealer_pool_cap_pct: string, sub_dealer_pool_cap_pct: string, company_mrp_pct: string, dealer_rule_keys: list<string>, sub_dealer_rule_keys: list<string>}
     */
    private function commissionPoolLimits(?array $snapshot = null): array
    {
        $snapshot ??= app(CommissionPolicy::class)->snapshot(false);

        return [
            'dealer_pool_cap_pct' => '40.00',
            'sub_dealer_pool_cap_pct' => '50.00',
            'company_mrp_pct' => (string) ($snapshot['company_mrp_percent'] ?? ''),
            'dealer_rule_keys' => CommissionPolicy::Keys,
            'sub_dealer_rule_keys' => array_keys(array_filter(
                CommissionPolicy::defaults(),
                fn (array $rule): bool => $rule['category'] === 'sub_dealer',
            )),
        ];
    }
}
