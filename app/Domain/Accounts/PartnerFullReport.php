<?php

namespace App\Domain\Accounts;

use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final class PartnerFullReport
{
    /** @var array<string, string> */
    private const CategoryLabels = [
        'own_dealer_cashback' => 'Own sale cashback',
        'direct_referral' => 'Direct referral',
        'd2d_gen2' => 'D2D generation 2',
        'd2d_gen3' => 'D2D generation 3',
        'd2d_multitier' => 'D2D multitier',
        'area_division' => 'Area dealer · Division',
        'area_district' => 'Area dealer · District',
        'area_upazila' => 'Area dealer · Upazila',
        'area_union' => 'Area dealer · Union',
        'area_management' => 'Area · Management share',
        'dealer_monthly_salary_pool' => 'Dealer monthly salary',
        'sub_dealer_salary' => 'Sub-dealer salary',
        'sub_dealer_tour' => 'Sub-dealer tour',
        'rank_royalty_pool' => 'Rank royalty pool',
        'rank_incentive_pool' => 'Rank incentive',
        'car_bike_incentive_fund' => 'Car / bike incentive',
        'company_marketing_fund' => 'Company marketing',
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['submitted', 'held', 'approved', 'partially_dispatched', 'dispatched', 'delivered'])
            ->orderByDesc('created_at')
            ->get(['id', 'number', 'status', 'total', 'paid', 'due_credit', 'created_at']);

        $orderIds = $orders->pluck('id');
        $claims = $orderIds->isEmpty()
            ? collect()
            : DB::table('payment_claims')
                ->whereIn('order_id', $orderIds)
                ->orderByDesc('id')
                ->get(['id', 'order_id', 'channel', 'reference', 'amount', 'status', 'created_at'])
                ->groupBy('order_id');

        $ordered = 0;
        $paid = 0;
        $due = 0;
        $orderRows = [];
        foreach ($orders as $order) {
            $ordered += Money::cents((string) $order->total);
            $paid += Money::cents((string) $order->paid);
            $due += Money::cents($order->outstanding());
            $orderClaims = ($claims[$order->id] ?? collect())->map(fn ($claim) => [
                'channel' => $claim->channel,
                'reference' => $claim->reference,
                'amount' => Money::decimal(Money::cents((string) $claim->amount)),
                'status' => $claim->status,
                'created_at' => $claim->created_at,
            ])->values()->all();
            $orderRows[] = [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal(Money::cents((string) $order->paid)),
                'due' => $order->outstanding(),
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'payments' => $orderClaims,
            ];
        }

        $allocations = DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['reversed'])
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        $commissionTotal = 0;
        $byCategory = [];
        foreach ($allocations as $row) {
            $cents = Money::cents((string) $row->total);
            $commissionTotal += $cents;
            $byCategory[] = [
                'category' => $row->category,
                'label' => self::CategoryLabels[$row->category] ?? str_replace('_', ' ', (string) $row->category),
                'amount' => Money::decimal($cents),
                'entries' => (int) $row->entries,
            ];
        }

        $teamIds = ReferralTreePath::query()
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->pluck('descendant_id');

        $teamMembers = $teamIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $teamIds)->orderBy('name')->get(['id', 'name', 'dealer_code', 'role', 'phone', 'status']);

        $teamOrders = $teamIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('user_id', $teamIds)
                ->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])
                ->latest()
                ->limit(40)
                ->get(['id', 'user_id', 'number', 'status', 'total', 'paid', 'due_credit', 'created_at']);

        $teamOrderUserIds = $teamOrders->pluck('user_id')->unique();
        $teamNames = $teamMembers->keyBy('id');

        $teamActivity = $teamOrders->map(function (Order $order) use ($teamNames) {
            $member = $teamNames->get($order->user_id);

            return [
                'order_id' => $order->id,
                'number' => $order->number,
                'member_name' => $member?->name ?? '—',
                'member_code' => $member?->dealer_code,
                'status' => $order->status,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal(Money::cents((string) $order->paid)),
                'due' => $order->outstanding(),
                'created_at' => optional($order->created_at)?->toIso8601String(),
            ];
        })->values()->all();

        $teamCommission = $teamIds->isEmpty()
            ? '0.00'
            : Money::decimal(Money::cents((string) DB::table('commission_allocations')
                ->whereIn('user_id', $teamIds)
                ->whereNotIn('status', ['reversed'])
                ->sum('amount')));

        return [
            'summary' => [
                'orders_count' => $orders->count(),
                'ordered' => Money::decimal($ordered),
                'paid' => Money::decimal($paid),
                'due' => Money::decimal($due),
                'commission' => Money::decimal($commissionTotal),
                'team_members' => $teamMembers->count(),
                'team_commission' => $teamCommission,
            ],
            'commissions' => $byCategory,
            'orders' => $orderRows,
            'team_members' => $teamMembers->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'dealer_code' => $member->dealer_code,
                'role' => $member->role,
                'phone' => $member->phone,
                'status' => $member->status,
            ])->values()->all(),
            'team_activity' => $teamActivity,
            'team_active_buyers' => $teamOrderUserIds->count(),
        ];
    }
}
