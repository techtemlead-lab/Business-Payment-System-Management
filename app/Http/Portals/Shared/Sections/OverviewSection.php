<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\OrderOverviewTotals;
use App\Domain\Orders\StaffDashboardSummary;
use App\Models\User;
use Carbon\CarbonImmutable;

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
        $startOfBusinessDay = CarbonImmutable::now((string) config('madina.business_timezone', config('app.timezone')))->startOfDay()->utc();
        $totals = app(OrderOverviewTotals::class)->for($context->orders, $startOfBusinessDay);
        $props['stats'] = [
            'order_count' => $totals['order_count'],
            'purchases' => $totals['purchases'],
            'dues' => $totals['dues'],
            'pending' => $totals['pending'],
            'supply' => $totals['supply'],
            'accounts' => $user->allows('accounts') ? $this->partnerAccountCount($context->branchScope) : null,
        ];
        $props['lowStock'] = $user->allows('inventory') ? app(StaffDashboardSummary::class)->lowStockProducts($context->branchScope)->limit(5)->get() : [];
        if (in_array($user->role, ['dealer', 'sub_dealer'], true) && ! $context->usingStaffWorkspace) {
            $props['partner_home'] = app(PartnerDashboardHome::class)->for($user);
            $props['money_cards'] = app(PartnerMoneyBook::class)->incomeCards($user);
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
                    'orders_today' => $totals['orders_placed_since'],
                    'pending_review' => $totals['pending'],
                    'partner_accounts' => $props['stats']['accounts'] ?? $this->partnerAccountCount($context->branchScope),
                    'purchases' => $props['stats']['purchases'],
                    'dues' => $props['stats']['dues'],
                ],
                ...app(StaffDashboardSummary::class)->for($user, $context->branchScope),
            ];
        }

        return $props;
    }

    /**
     * Dealers and sub-dealers, only of the picked branch when the top-bar switcher picked one.
     */
    private function partnerAccountCount(?BranchScope $branchScope = null): int
    {
        $partners = User::whereIn('role', ['dealer', 'sub_dealer']);
        $branchScope?->restrictPartners($partners);

        return $partners->count();
    }
}
