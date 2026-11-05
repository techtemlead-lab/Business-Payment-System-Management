<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Orders\StaffDashboardSummary;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Home screen: order totals, low stock, and the partner or staff home cards.
 */
final class OverviewSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'overview') {
            return [];
        }
        $user = $context->user;
        $props = [];
        $approved = (clone $context->orders)->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered']);
        $total = (string) (clone $approved)->sum('total');
        $credits = (string) (clone $approved)->sum('due_credit');
        $paid = (string) (clone $approved)->sum('paid');
        $props['stats'] = ['order_count' => (clone $context->orders)->count(), 'purchases' => Money::decimal(Money::cents($total)),
            'dues' => Money::decimal(Money::cents($total) - Money::cents($paid) - Money::cents($credits)),
            'pending' => (clone $context->orders)->whereIn('status', ['submitted', 'held'])->count(),
            'supply' => DB::table('order_lines')->whereIn('order_id', (clone $approved)->select('id'))->sum(DB::raw('quantity-dispatched')),
            'accounts' => $user->allows('accounts') ? User::whereIn('role', ['dealer', 'sub_dealer'])->count() : null];
        $props['lowStock'] = $user->allows('inventory') ? Product::where('active', true)->whereColumn('stock', '<=', 'low_stock_at')->limit(5)->get(['id', 'name', 'stock', 'sku']) : [];
        if (in_array($user->role, ['dealer', 'sub_dealer'], true) && ! $context->usingStaffWorkspace) {
            $props['partner_home'] = app(PartnerDashboardHome::class)->for($user);
        } elseif ($context->isStaff) {
            $staffProfile = is_array($user->profile) ? $user->profile : [];
            $props['admin_home'] = [
                'profile' => [
                    'name' => $user->name,
                    'role' => $user->role,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'initial' => mb_strtoupper(mb_substr($user->name, 0, 1)),
                    'status' => $user->status,
                    'photo_url' => is_string($staffProfile['photo_url'] ?? null)
                        ? $staffProfile['photo_url']
                        : (is_string($staffProfile['avatar_url'] ?? null) ? $staffProfile['avatar_url'] : null),
                ],
                'snapshot' => [
                    'orders_today' => (clone $context->orders)->where('created_at', '>=', CarbonImmutable::now((string) config('madina.business_timezone', config('app.timezone')))->startOfDay()->utc())->count(),
                    'pending_review' => (clone $context->orders)->whereIn('status', ['submitted', 'held'])->count(),
                    'partner_accounts' => User::whereIn('role', ['dealer', 'sub_dealer'])->count(),
                    'purchases' => $props['stats']['purchases'],
                    'dues' => $props['stats']['dues'],
                ],
                ...app(StaffDashboardSummary::class)->for($user),
            ];
        }

        return $props;
    }
}
