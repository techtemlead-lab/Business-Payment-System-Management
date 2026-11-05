<?php

namespace App\Http\Portals\Shared\Sections;

use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The referral network: a partner's downline and the company-wide partner tree.
 */
final class NetworkSection implements WorkspaceSection
{
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
    private function networkDealerNodes(Builder $query, bool $maskPhone): array
    {
        return $query
            ->with(['rank', 'sponsor:id,name,dealer_code'])
            ->get(['id', 'name', 'dealer_code', 'phone', 'district', 'division', 'sponsor_id', 'role', 'status', 'created_at'])
            ->map(function (User $dealer) use ($maskPhone): array {
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
                ];
            })
            ->values()
            ->all();
    }
}
