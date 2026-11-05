<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionQualification;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    public function data(SectionContext $context, array $data): array
    {
        $props = [];
        if ($context->section === 'network') {
            $props = $this->downline($context);
        }
        if ($context->section === 'network-tree' || ($context->isStaff && $context->section === 'network')) {
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
        $props['members'] = DB::table('referral_tree_paths')->join('users', 'users.id', '=', 'descendant_id')->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)->orderBy('depth')->get(['users.id', 'users.name', 'users.dealer_code', 'users.sponsor_id', 'depth', 'users.created_at']);
        if (! $context->isStaff && $user->role === 'dealer') {
            $networkIds = DB::table('referral_tree_paths')
                ->where('ancestor_id', $user->id)
                ->pluck('descendant_id')
                ->push($user->id)
                ->unique()
                ->values()
                ->all();
            $props['dealers'] = $this->networkDealerNodes(
                User::query()->whereIn('id', $networkIds),
                maskPhone: false,
                viewer: $user,
            );
            $props['dealers_truncated'] = false;
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
        $sponsorEarned = $this->sponsorEarnedByPartner($ids);
        $viewerEarned = $viewer ? $this->viewerEarnedByPartner($viewer, $ids) : [];

        return $partners
            ->map(function (User $dealer) use ($maskPhone, $sales, $sponsorEarned, $viewerEarned, $viewer): array {
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
