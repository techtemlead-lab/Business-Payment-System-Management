<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Orders\OrderListFilter;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The referral network: a partner's downline and the company-wide partner tree.
 */
final class NetworkSection implements WorkspaceSection
{
    /** Order statuses that count as a completed purchase (the goods were approved for release). */
    private const PurchaseStatuses = ['approved', 'partially_dispatched', 'dispatched', 'delivered'];

    /** Commission statuses that count as money earned, held or already paid; reversals are left out. */
    private const EarnedStatuses = ['pending', 'available', 'maturing', 'held', 'reserved', 'settled', 'partially_settled'];

    /** The most downline members or partner nodes sent to one page; the CSV export lists everyone. */
    private const DownlineLimit = 2500;

    public function data(SectionContext $context, array $data): array
    {
        $props = [];
        if ($context->section === 'network') {
            $props = $this->downline($context);
        }
        // The whole company's partner tree is for staff only; partners get their own downline above.
        if ($context->isStaff && in_array($context->section, ['network', 'network-tree'], true)) {
            $props = array_merge($props, $this->partnerTree());
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
            ->get(['users.id', 'users.name', 'users.dealer_code', 'users.sponsor_id', 'depth', 'users.created_at']);
        $truncated = $members->count() > self::DownlineLimit;

        return [
            'members' => $members->take(self::DownlineLimit)->values(),
            'members_total' => $truncated ? (clone $query)->count() : $members->count(),
            'members_truncated' => $truncated,
        ];
    }

    /**
     * The team at a glance, in one query: how many members, how many the dealer brought in
     * directly, who joined this month, who bought this month, who joined over 60 days ago but has
     * not bought in the last 60 days, and the team's confirmed purchases this month.
     *
     * @return array{members: int, direct: int, joined_this_month: int, bought_this_month: int, quiet_60_days: int, team_sales_month: string}
     */
    private function teamSummary(User $user): array
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $monthStart = CarbonImmutable::now($tz)->startOfMonth()->utc();
        $sixtyDaysAgo = CarbonImmutable::now($tz)->startOfDay()->subDays(60)->utc();
        $confirmed = "'".implode("','", OrderListFilter::Confirmed)."'";
        $bought = fn (string $since): string => "EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.status IN ({$confirmed}) AND o.created_at >= {$since})";
        $row = DB::table('referral_tree_paths as p')
            ->join('users as u', 'u.id', '=', 'p.descendant_id')
            ->where('p.ancestor_id', $user->id)
            ->where('p.depth', '>', 0)
            ->selectRaw('COUNT(*) AS members')
            ->selectRaw('COUNT(*) FILTER (WHERE p.depth = 1) AS direct')
            ->selectRaw('COUNT(*) FILTER (WHERE u.created_at >= ?) AS joined_this_month', [$monthStart])
            ->selectRaw('COUNT(*) FILTER (WHERE '.$bought('?').') AS bought_this_month', [$monthStart])
            ->selectRaw('COUNT(*) FILTER (WHERE u.created_at < ? AND NOT '.$bought('?').') AS quiet_60_days', [$sixtyDaysAgo, $sixtyDaysAgo])
            ->selectRaw("COALESCE((SELECT SUM(o.total) FROM orders o JOIN referral_tree_paths t ON t.descendant_id = o.user_id WHERE t.ancestor_id = ? AND t.depth > 0 AND o.status IN ({$confirmed}) AND o.created_at >= ?), 0) AS team_sales_month", [$user->id, $monthStart])
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
    private function partnerTree(): array
    {
        $props = [];
        $networkQuery = User::query()->whereIn('role', ['dealer', 'sub_dealer']);
        $totalPartners = (clone $networkQuery)->count();
        $limit = 2500;
        $props['dealers_truncated'] = $totalPartners > $limit;
        $props['dealers_total'] = $totalPartners;
        $props['dealers'] = $this->networkDealerNodes(
            $networkQuery->orderBy('id')->limit($limit),
            maskPhone: true,
        );

        return $props;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function networkDealerNodes(Builder $query, bool $maskPhone, ?User $viewer = null): array
    {
        $partners = $query
            ->with(['rank', 'sponsor:id,name,dealer_code'])
            ->get(['id', 'name', 'dealer_code', 'phone', 'district', 'division', 'sponsor_id', 'role', 'status', 'created_at']);
        $ids = $partners->pluck('id')->all();
        $sales = $this->salesByPartner($ids);
        // The same figure the monthly salary target is checked against: delivered, paid purchases after refunds.
        $qualifying = app(CommissionQualification::class)->monthlyVolumesFor($ids, CommissionQualification::currentMonth());
        $sponsorEarned = $this->sponsorEarnedByPartner($ids);
        $viewerEarned = $viewer ? $this->viewerEarnedByPartner($viewer, $ids) : [];

        return $partners
            ->map(function (User $dealer) use ($maskPhone, $sales, $qualifying, $sponsorEarned, $viewerEarned, $viewer): array {
                $phone = (string) ($dealer->phone ?? '');
                if ($maskPhone && $phone !== '') {
                    $digits = preg_replace('/\D+/', '', $phone) ?: '';
                    $phone = $digits !== '' ? '••••'.substr($digits, -4) : '';
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
                    'rank' => $dealer->rank?->current_rank ?? 'dealer',
                    'lifetime_purchases' => (float) ($dealer->rank?->lifetime_purchase_total ?? 0),
                    'created_at' => $dealer->created_at?->toIso8601String(),
                    'sales_total' => Money::decimal($sales[$dealer->id]['total'] ?? 0),
                    'sales_month' => Money::decimal($sales[$dealer->id]['month'] ?? 0),
                    'qualifying_volume_month' => Money::decimal($qualifying[$dealer->id] ?? 0),
                    'sponsor_earned' => Money::decimal($sponsorEarned[$dealer->id] ?? 0),
                    ...($viewer ? [
                        'viewer_earned' => Money::decimal(array_sum($viewerEarned[$dealer->id] ?? [])),
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
     * Completed purchase value per partner, all time and in the current business month, in cents.
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

        return DB::table('orders')
            ->whereIn('user_id', $ids)
            ->whereIn('status', self::PurchaseStatuses)
            ->groupBy('user_id')
            ->selectRaw('user_id, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(CASE WHEN created_at >= ? THEN total ELSE 0 END), 0) AS month', [$monthStartUtc])
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->user_id => [
                'total' => Money::cents((string) $row->total),
                'month' => Money::cents((string) $row->month),
            ]])
            ->all();
    }

    /**
     * What each partner's direct referrer has earned from that partner's purchases, in cents.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function sponsorEarnedByPartner(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('commission_allocations')
            ->join('orders', 'orders.id', '=', 'commission_allocations.order_id')
            ->join('users AS buyers', 'buyers.id', '=', 'orders.user_id')
            ->whereIn('orders.user_id', $ids)
            ->whereColumn('commission_allocations.user_id', 'buyers.sponsor_id')
            ->whereIn('commission_allocations.status', self::EarnedStatuses)
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id AS buyer_id, COALESCE(SUM(commission_allocations.amount), 0) AS earned')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->buyer_id => Money::cents((string) $row->earned)])
            ->all();
    }

    /**
     * What the viewer has earned from each partner's purchases, split by commission type, in cents.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function viewerEarnedByPartner(User $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $earned = [];
        DB::table('commission_allocations')
            ->join('orders', 'orders.id', '=', 'commission_allocations.order_id')
            ->whereIn('orders.user_id', $ids)
            ->where('commission_allocations.user_id', $viewer->id)
            ->whereIn('commission_allocations.status', self::EarnedStatuses)
            ->groupBy('orders.user_id', 'commission_allocations.category')
            ->orderBy('commission_allocations.category')
            ->selectRaw('orders.user_id AS buyer_id, commission_allocations.category, COALESCE(SUM(commission_allocations.amount), 0) AS earned')
            ->get()
            ->each(function (object $row) use (&$earned): void {
                $earned[(int) $row->buyer_id][(string) $row->category] = Money::cents((string) $row->earned);
            });

        return $earned;
    }
}
