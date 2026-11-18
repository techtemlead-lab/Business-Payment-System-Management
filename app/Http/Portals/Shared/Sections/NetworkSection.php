<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionQualification;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The referral network: a partner's downline and the company-wide partner tree.
 */
final class NetworkSection implements WorkspaceSection
{
    /** Commission statuses that count as money earned or already paid; reversals are left out. Held money is shown apart: it was not received. */
    private const EarnedStatuses = ['pending', 'available', 'maturing', 'reserved', 'settled', 'partially_settled'];

    /** The most downline members or partner nodes sent to one page; the CSV export lists everyone. */
    private const DownlineLimit = 2500;

    /** Partners on the first load of the staff tree; each "Show more" adds the same again. */
    public const TreeFirstLoad = 300;

    public function data(SectionContext $context, array $data): array
    {
        $props = [];
        if ($context->section === 'network') {
            $props = $this->downline($context);
        }
        // The whole company's partner tree is for staff only; partners get their own downline above.
        if ($context->isStaff && in_array($context->section, ['network', 'network-tree'], true)) {
            $props = array_merge($props, $this->partnerTree($context->request));
        }

        return $props;
    }

    /**
     * The viewer's downline, the dealer tree for a dealer, and one member's orders when asked.
     *
     * @return array<string, mixed>
     */
    private function downline(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        $props = [];
        if (! $context->isStaff && $user->role === 'dealer') {
            // Dealers see their network in the tree explorer; the plain member list is only drawn for sub-dealers.
            $props['team_summary'] = $this->teamSummary($user);
            $total = $props['team_summary']['members'] + 1;
            $props['dealers'] = $this->networkDealerNodes(
                User::query()
                    ->where(fn (Builder $query) => $query->whereKey($user->id)->orWhereIn('id', DB::table('referral_tree_paths')
                        ->where('ancestor_id', $user->id)->select('descendant_id')))
                    ->orderByRaw('(SELECT depth FROM referral_tree_paths WHERE ancestor_id = ? AND descendant_id = users.id) ASC NULLS FIRST', [$user->id])
                    ->orderBy('id')
                    ->limit(self::DownlineLimit),
                maskPhone: false,
                viewer: $user,
            );
            $props['dealers_truncated'] = $total > self::DownlineLimit;
            $props['dealers_total'] = $total;
        } elseif (! $context->isStaff) {
            $props = [...$props, ...$this->members($user)];
        }
        if ($request->filled('member_id')) {
            $member = $request->validate(['member_id' => 'required|integer'])['member_id'];
            abort_unless(ReferralTreePath::isDescendant($user->id, (int) $member), 404);
            $props['selected_member'] = User::whereKey($member)->first(['id', 'name', 'dealer_code']);
            $props['member_orders'] = Order::where('user_id', $member)->latest()->paginate(12, ['id', 'number', 'status', 'total', 'paid', 'created_at'])->withQueryString();
        }

        return $props;
    }

    /**
     * The viewer's downline as a plain list, nearest levels first, capped so a large team stays fast.
     *
     * One extra row is read to tell whether the list was cut; only then are all members counted.
     *
     * @return array{members: Collection<int, object>, members_total: int, members_truncated: bool}
     */
    private function members(User $user): array
    {
        $query = DB::table('referral_tree_paths')->join('users', 'users.id', '=', 'descendant_id')
            ->where('ancestor_id', $user->id)->where('depth', '>', 0);
        $members = (clone $query)->orderBy('depth')->orderBy('users.id')->limit(self::DownlineLimit + 1)
            ->get(['users.id', 'users.name', 'users.dealer_code', 'users.phone', 'users.sponsor_id', 'depth', 'users.created_at']);
        $truncated = $members->count() > self::DownlineLimit;

        return [
            // Full numbers only for directly referred members; the rest show the last four digits.
            'members' => $members->take(self::DownlineLimit)->map(function (object $member) use ($user): object {
                $member->phone = PhoneIdentity::forDownlineViewer($member->phone, (int) $member->id, $member->sponsor_id !== null ? (int) $member->sponsor_id : null, (int) $user->id);

                return $member;
            })->values(),
            'members_total' => $truncated ? (clone $query)->count() : $members->count(),
            'members_truncated' => $truncated,
        ];
    }

    /**
     * The team at a glance, in one query: how many members, how many the dealer brought in
     * directly, who joined this month, who bought this month, who joined over 60 days ago but has
     * not bought in the last 60 days, and the team's delivered purchases this month.
     *
     * @return array{members: int, direct: int, joined_this_month: int, bought_this_month: int, quiet_60_days: int, team_sales_month: string}
     */
    private function teamSummary(User $user): array
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $monthStart = CarbonImmutable::now($tz)->startOfMonth()->utc();
        $sixtyDaysAgo = CarbonImmutable::now($tz)->startOfDay()->subDays(60)->utc();
        $delivered = "'".implode("','", CommissionQualification::DeliveredStatuses)."'";
        $month = CommissionQualification::currentMonth();
        // "This month" for purchases is the month the goods were delivered (the commission batch month),
        // the same month the salary target and the income list use, so every figure agrees with the money.
        $boughtThisMonth = 'EXISTS (SELECT 1 FROM commission_batches b JOIN orders o ON o.id = b.order_id WHERE o.user_id = u.id AND b.period_month = ? AND b.no_commission = false)';
        $bought60 = fn (string $since): string => "EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.status IN ({$delivered}) AND o.created_at >= {$since})";
        $row = DB::table('referral_tree_paths as p')
            ->join('users as u', 'u.id', '=', 'p.descendant_id')
            ->where('p.ancestor_id', $user->id)
            ->where('p.depth', '>', 0)
            ->selectRaw('COUNT(*) AS members')
            ->selectRaw('COUNT(*) FILTER (WHERE p.depth = 1) AS direct')
            ->selectRaw('COUNT(*) FILTER (WHERE u.created_at >= ?) AS joined_this_month', [$monthStart])
            ->selectRaw('COUNT(*) FILTER (WHERE '.$boughtThisMonth.') AS bought_this_month', [$month])
            ->selectRaw('COUNT(*) FILTER (WHERE u.created_at < ? AND NOT '.$bought60('?').') AS quiet_60_days', [$sixtyDaysAgo, $sixtyDaysAgo])
            ->selectRaw('COALESCE((SELECT SUM(b.cash_basis - b.refunded_amount) FROM commission_batches b JOIN orders o ON o.id = b.order_id JOIN referral_tree_paths t ON t.descendant_id = o.user_id WHERE t.ancestor_id = ? AND t.depth > 0 AND b.period_month = ? AND b.no_commission = false), 0) AS team_sales_month', [$user->id, $month])
            ->first();

        return [
            'members' => (int) ($row->members ?? 0),
            'direct' => (int) ($row->direct ?? 0),
            'joined_this_month' => (int) ($row->joined_this_month ?? 0),
            'bought_this_month' => (int) ($row->bought_this_month ?? 0),
            'quiet_60_days' => (int) ($row->quiet_60_days ?? 0),
            'team_sales_month' => Money::decimal(Money::cents((string) ($row->team_sales_month ?? '0'))),
        ];
    }

    /**
     * Every dealer and sub-dealer for the tree view, capped at 2,500 with phones masked.
     *
     * @return array<string, mixed>
     */
    private function partnerTree(Request $request): array
    {
        $props = [];
        $networkQuery = User::query()->whereIn('role', ['dealer', 'sub_dealer']);
        $rootId = $this->focusedRoot($request);
        if ($rootId !== null) {
            // One partner and everyone under them, every level, nearest levels first.
            $networkQuery->where(fn (Builder $query) => $query->whereKey($rootId)->orWhereIn('id', DB::table('referral_tree_paths')
                ->where('ancestor_id', $rootId)->select('descendant_id')));
            $props['tree_root'] = $rootId;
            $props['tree_root_name'] = (string) User::query()->whereKey($rootId)->value('name');
        }
        $totalPartners = (clone $networkQuery)->count();
        // The page opens with the top of the tree (level by level) and grows by "Show more",
        // so a phone never downloads thousands of partners at once. A chosen partner's own tree
        // opens complete, up to the same 2,500 limit.
        $requested = (int) $request->query('tree_limit', (string) ($rootId !== null ? self::DownlineLimit : self::TreeFirstLoad));
        $limit = min(self::DownlineLimit, max(self::TreeFirstLoad, $requested));
        $props['dealers_truncated'] = $totalPartners > $limit;
        $props['dealers_total'] = $totalPartners;
        $props['dealers_limit'] = $limit;
        $props['dealers_next_limit'] = $totalPartners > $limit && $limit < self::DownlineLimit ? min(self::DownlineLimit, $limit + self::TreeFirstLoad) : null;
        $nodes = $this->networkDealerNodes(
            $networkQuery
                ->orderByRaw($rootId !== null
                    ? '(SELECT depth FROM referral_tree_paths WHERE ancestor_id = ? AND descendant_id = users.id) ASC NULLS FIRST'
                    : '(SELECT COUNT(*) FROM referral_tree_paths AS levels WHERE levels.descendant_id = users.id AND levels.depth > 0) ASC', $rootId !== null ? [$rootId] : [])
                ->orderBy('id')
                ->limit($limit),
            maskPhone: true,
        );
        // Team size and direct referrals come from the tree table, so they are right even for
        // branches whose lower levels are not loaded yet.
        $teams = DB::table('referral_tree_paths')
            ->whereIn('ancestor_id', array_column($nodes, 'id'))
            ->where('depth', '>', 0)
            ->groupBy('ancestor_id')
            ->selectRaw('ancestor_id, COUNT(*) AS team, COUNT(*) FILTER (WHERE depth = 1) AS direct')
            ->get()
            ->keyBy('ancestor_id');
        $props['dealers'] = array_map(fn (array $node): array => [
            ...$node,
            'team_size' => (int) ($teams->get($node['id'])->team ?? 0),
            'direct_count' => (int) ($teams->get($node['id'])->direct ?? 0),
        ], $nodes);
        $props['tree_stats'] = $this->treeStats($rootId);

        return $props;
    }

    /** The partner whose whole tree is asked for, when the address names one that exists. */
    private function focusedRoot(Request $request): ?int
    {
        $asked = $request->query('tree_root');
        if (! is_string($asked) || ! ctype_digit($asked)) {
            return null;
        }

        return User::query()->whereKey((int) $asked)->whereIn('role', ['dealer', 'sub_dealer'])->exists() ? (int) $asked : null;
    }

    /**
     * Partner count, partners per rank and delivered purchases over the whole tree,
     * not just the part loaded on the page.
     *
     * @return array{total: int, ranks: array<string, int>, sales_total: string}
     */
    private function treeStats(?int $rootId = null): array
    {
        $inTree = fn ($query) => $rootId === null ? $query : $query->where(fn ($inner) => $inner->where('users.id', $rootId)
            ->orWhereIn('users.id', DB::table('referral_tree_paths')->where('ancestor_id', $rootId)->select('descendant_id')));
        $ranks = DB::table('users')
            ->leftJoin('dealer_ranks', 'dealer_ranks.user_id', '=', 'users.id')
            ->whereIn('users.role', ['dealer', 'sub_dealer'])
            ->when($rootId !== null, $inTree)
            ->groupByRaw("COALESCE(dealer_ranks.current_rank, 'dealer')")
            ->selectRaw("COALESCE(dealer_ranks.current_rank, 'dealer') AS rank, COUNT(*) AS total")
            ->pluck('total', 'rank')
            ->map(fn ($total): int => (int) $total)
            ->all();
        $sales = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereIn('users.role', ['dealer', 'sub_dealer'])
            ->when($rootId !== null, $inTree)
            ->whereIn('orders.status', CommissionQualification::DeliveredStatuses)
            ->sum(DB::raw(CommissionQualification::deliveredValueSql()));

        return [
            'total' => array_sum($ranks),
            'ranks' => $ranks,
            'sales_total' => Money::decimal(Money::cents((string) $sales)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function networkDealerNodes(Builder $query, bool $maskPhone, ?User $viewer = null): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $partners */
        $partners = $query
            ->with(['rank', 'sponsor:id,name,dealer_code'])
            ->get(['id', 'name', 'dealer_code', 'phone', 'district', 'division', 'sponsor_id', 'role', 'status', 'created_at']);
        $ids = $partners->pluck('id')->all();
        $sales = $this->salesByPartner($ids);
        // The same figure the monthly salary target is checked against: delivered, paid purchases after refunds.
        $qualifying = app(CommissionQualification::class)->monthlyVolumesFor($ids, CommissionQualification::currentMonth());
        $sponsorMoney = $this->sponsorMoneyByPartner($ids);
        $viewerMoney = $viewer ? $this->viewerMoneyByPartner($viewer, $ids) : ['earned' => [], 'held' => []];
        $viewerEarned = $viewerMoney['earned'];

        return $partners
            ->map(function (User $dealer) use ($maskPhone, $sales, $qualifying, $sponsorMoney, $viewerMoney, $viewerEarned, $viewer): array {
                $phone = (string) ($dealer->phone ?? '');
                if ($maskPhone) {
                    $phone = PhoneIdentity::masked($phone);
                } elseif ($viewer) {
                    // A dealer sees full numbers only for members they referred directly.
                    $phone = PhoneIdentity::forDownlineViewer($phone, (int) $dealer->id, $dealer->sponsor_id !== null ? (int) $dealer->sponsor_id : null, (int) $viewer->id);
                }

                return [
                    'id' => $dealer->id,
                    'name' => $dealer->name,
                    'dealer_code' => $dealer->dealer_code,
                    'phone' => $phone,
                    'district' => $dealer->district,
                    'division' => $dealer->division,
                    'sponsor_id' => $dealer->sponsor_id,
                    'sponsor_name' => $dealer->sponsor?->name,
                    'role' => $dealer->role,
                    'status' => $dealer->status,
                    // Ranks belong to dealers; a sub-dealer is always shown as a sub-dealer, never as a dealer.
                    'rank' => $dealer->role === 'sub_dealer' ? 'sub_dealer' : ($dealer->rank?->current_rank ?? 'dealer'),
                    'lifetime_purchases' => (float) ($dealer->rank?->lifetime_purchase_total ?? 0),
                    'created_at' => $dealer->created_at?->toIso8601String(),
                    'sales_total' => Money::decimal($sales[$dealer->id]['total'] ?? 0),
                    'sales_month' => Money::decimal($qualifying[$dealer->id] ?? 0),
                    'qualifying_volume_month' => Money::decimal($qualifying[$dealer->id] ?? 0),
                    'sponsor_earned' => Money::decimal($sponsorMoney['earned'][$dealer->id] ?? 0),
                    'sponsor_held' => Money::decimal($sponsorMoney['held'][$dealer->id] ?? 0),
                    ...($viewer ? [
                        'viewer_earned' => Money::decimal(array_sum($viewerEarned[$dealer->id] ?? [])),
                        'viewer_held' => Money::decimal($viewerMoney['held'][$dealer->id] ?? 0),
                        'viewer_earned_by_type' => collect($viewerEarned[$dealer->id] ?? [])
                            ->map(fn (int $cents, string $category): array => ['category' => $category, 'amount' => Money::decimal($cents)])
                            ->values()
                            ->all(),
                    ] : []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Delivered purchase value per partner, all time and in the current business month, in cents.
     * The same orders the rank page counts: delivered ones, and only the delivered part of a
     * partly delivered one.
     *
     * Counted from orders so both figures always agree; the rank's lifetime total only moves
     * once payouts are live and is kept for rank progress.
     *
     * @param  list<int>  $ids
     * @return array<int, array{total: int, month: int}>
     */
    private function salesByPartner(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $timezone = (string) config('madina.business_timezone', config('app.timezone'));
        $monthStartUtc = CarbonImmutable::createFromFormat('!Y-m', CommissionQualification::currentMonth(), $timezone)->utc();
        $value = CommissionQualification::deliveredValueSql();

        return DB::table('orders')
            ->whereIn('user_id', $ids)
            ->whereIn('status', CommissionQualification::DeliveredStatuses)
            ->groupBy('user_id')
            ->selectRaw("user_id, COALESCE(SUM({$value}), 0) AS total, COALESCE(SUM(CASE WHEN created_at >= ? THEN {$value} ELSE 0 END), 0) AS month", [$monthStartUtc])
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->user_id => [
                'total' => Money::cents((string) $row->total),
                'month' => Money::cents((string) $row->month),
            ]])
            ->all();
    }

    /**
     * What each partner's direct referrer has received from that partner's purchases, and what is
     * held back for the referrer (suspended account or area target not met), in cents.
     *
     * @param  list<int>  $ids
     * @return array{earned: array<int, int>, held: array<int, int>}
     */
    private function sponsorMoneyByPartner(array $ids): array
    {
        $money = ['earned' => [], 'held' => []];
        if ($ids === []) {
            return $money;
        }
        $earnedList = "'".implode("','", self::EarnedStatuses)."'";
        DB::table('commission_allocations')
            ->join('orders', 'orders.id', '=', 'commission_allocations.order_id')
            ->join('users AS buyers', 'buyers.id', '=', 'orders.user_id')
            ->whereIn('orders.user_id', $ids)
            ->whereColumn('commission_allocations.user_id', 'buyers.sponsor_id')
            ->whereIn('commission_allocations.status', [...self::EarnedStatuses, 'held'])
            ->groupBy('orders.user_id')
            ->selectRaw("orders.user_id AS buyer_id, COALESCE(SUM(commission_allocations.amount) FILTER (WHERE commission_allocations.status IN ({$earnedList})), 0) AS earned, COALESCE(SUM(commission_allocations.amount) FILTER (WHERE commission_allocations.status = 'held'), 0) AS held")
            ->get()
            ->each(function (object $row) use (&$money): void {
                $money['earned'][(int) $row->buyer_id] = Money::cents((string) $row->earned);
                $money['held'][(int) $row->buyer_id] = Money::cents((string) $row->held);
            });

        return $money;
    }

    /**
     * What the viewer has received from each partner's purchases, split by commission type, and what
     * is held back for the viewer, in cents.
     *
     * @param  list<int>  $ids
     * @return array{earned: array<int, array<string, int>>, held: array<int, int>}
     */
    private function viewerMoneyByPartner(User $viewer, array $ids): array
    {
        $money = ['earned' => [], 'held' => []];
        if ($ids === []) {
            return $money;
        }
        DB::table('commission_allocations')
            ->join('orders', 'orders.id', '=', 'commission_allocations.order_id')
            ->whereIn('orders.user_id', $ids)
            ->where('commission_allocations.user_id', $viewer->id)
            ->whereIn('commission_allocations.status', [...self::EarnedStatuses, 'held'])
            ->groupBy('orders.user_id', 'commission_allocations.category', 'commission_allocations.status')
            ->orderBy('commission_allocations.category')
            ->selectRaw('orders.user_id AS buyer_id, commission_allocations.category, commission_allocations.status, COALESCE(SUM(commission_allocations.amount), 0) AS earned')
            ->get()
            ->each(function (object $row) use (&$money): void {
                $cents = Money::cents((string) $row->earned);
                if ($row->status === 'held') {
                    $money['held'][(int) $row->buyer_id] = ($money['held'][(int) $row->buyer_id] ?? 0) + $cents;

                    return;
                }
                $money['earned'][(int) $row->buyer_id][(string) $row->category] = ($money['earned'][(int) $row->buyer_id][(string) $row->category] ?? 0) + $cents;
            });

        return $money;
    }
}
