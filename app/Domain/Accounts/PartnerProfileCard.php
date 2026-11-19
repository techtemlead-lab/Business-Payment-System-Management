<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Geography\ManagementReferral;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\Money;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything a partner (or the office looking at a partner) needs on one profile card: who he is,
 * where he is from, who referred him, whom he referred and what those referrals bought.
 *
 * Sales figures count only goods that reached the buyer, the same way commission does.
 */
final class PartnerProfileCard
{
    /** The most direct referrals listed on the card; the totals still count everyone. */
    private const ListLimit = 100;

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $profile = PartnerProfileLock::profileOf($user);
        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $address = [
            'division' => $this->clean($user->division),
            'district' => $this->clean($user->district),
            'upazila' => $this->clean($profile['upazila'] ?? null),
            'union' => $this->clean($profile['union'] ?? null),
            'village' => $this->clean($profile['village'] ?? null),
        ];
        // Place chain a partner must have for the office to find him: everything down to the union.
        $missing = array_keys(array_filter(
            array_intersect_key($address, array_flip(['division', 'district', 'upazila', 'union'])),
            fn (?string $value): bool => $value === null,
        ));

        return [
            'role' => (string) $user->role,
            'dealer_code' => $user->dealer_code,
            'status' => (string) $user->status,
            'joined_on' => $user->created_at?->timezone($tz)->format('d/m/Y'),
            'shop_name' => $this->clean($profile['shop_name'] ?? $profile['pharmacy'] ?? null),
            'place_code' => $this->clean($profile['place_code'] ?? $profile['area_code'] ?? null),
            'address' => $address,
            'address_missing' => $missing,
            'referred_by' => $this->referredBy($user, $profile),
            'refer_code' => $user->refer_code,
            'refer_url' => $user->refer_code && PartnerJoinLink::enabled() ? PortalHost::url('public', '/join/'.$user->refer_code) : null,
            'referrals' => $this->referrals($user),
            'rank' => $user->role === 'dealer' ? $this->rank($user) : null,
            'locked' => PartnerProfileLock::isLocked($user),
        ];
    }

    /**
     * Who brought this partner in: his sponsor, or the company when the office added him itself.
     *
     * @param  array<string, mixed>  $profile
     * @return array{kind: string, name: string|null, code: string|null, phone: string|null}
     */
    public function referredBy(User $user, array $profile): array
    {
        $sponsor = $user->sponsor_id
            ? User::query()->whereKey($user->sponsor_id)->first(['id', 'name', 'dealer_code', 'refer_code', 'phone'])
            : null;
        if ($sponsor !== null) {
            return [
                'kind' => 'partner',
                'name' => (string) $sponsor->name,
                'code' => $sponsor->dealer_code ?: $sponsor->refer_code,
                'phone' => $sponsor->phone,
            ];
        }

        return [
            'kind' => ($profile['referred_by'] ?? null) === ManagementReferral::ProfileFlag ? 'company' : 'none',
            'name' => null,
            'code' => null,
            'phone' => null,
        ];
    }

    /**
     * Direct and team counts, and what each direct referral (with his own team) has bought.
     *
     * @return array{direct: int, team: int, sales_total: string, items: list<array<string, mixed>>, truncated: bool}
     */
    private function referrals(User $user): array
    {
        $counts = DB::table('referral_tree_paths')
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->selectRaw('COUNT(*) AS team, COUNT(*) FILTER (WHERE depth = 1) AS direct')
            ->first();

        $delivered = "'".implode("','", CommissionQualification::DeliveredStatuses)."'";
        $value = CommissionQualification::deliveredValueSql('o');
        $rows = DB::table('referral_tree_paths as direct')
            ->join('users as u', 'u.id', '=', 'direct.descendant_id')
            ->leftJoin('referral_tree_paths as branch', 'branch.ancestor_id', '=', 'direct.descendant_id')
            ->leftJoin('orders as o', fn ($join) => $join->on('o.user_id', '=', 'branch.descendant_id')->whereRaw("o.status IN ({$delivered})"))
            ->where('direct.ancestor_id', $user->id)
            ->where('direct.depth', 1)
            ->groupBy('u.id', 'u.name', 'u.dealer_code', 'u.role', 'u.status', 'u.created_at')
            ->orderByDesc('u.created_at')
            ->limit(self::ListLimit)
            ->selectRaw('u.id, u.name, u.dealer_code, u.role, u.status, u.created_at')
            ->selectRaw("COALESCE(SUM({$value}) FILTER (WHERE branch.descendant_id = u.id), 0) AS own_sales")
            ->selectRaw("COALESCE(SUM({$value}), 0) AS branch_sales")
            ->selectRaw('COUNT(DISTINCT branch.descendant_id) - 1 AS branch_members')
            ->get();

        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $salesTotal = 0;
        $items = [];
        foreach ($rows as $row) {
            $branchCents = Money::cents((string) $row->branch_sales);
            $salesTotal += $branchCents;
            $items[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'dealer_code' => $row->dealer_code,
                'role' => (string) $row->role,
                'status' => (string) $row->status,
                'joined_on' => CarbonImmutable::parse((string) $row->created_at)->timezone($tz)->format('d/m/Y'),
                'own_sales' => Money::decimal(Money::cents((string) $row->own_sales)),
                'branch_sales' => Money::decimal($branchCents),
                'branch_members' => max(0, (int) $row->branch_members),
            ];
        }
        $direct = (int) ($counts->direct ?? 0);

        return [
            'direct' => $direct,
            'team' => (int) ($counts->team ?? 0),
            'sales_total' => Money::decimal($salesTotal),
            'items' => $items,
            'truncated' => $direct > count($items),
        ];
    }

    /**
     * The dealer's rank and the whole ladder with what each step needs, read from the live rules.
     *
     * @return array{current: string, ladder: list<array<string, mixed>>}
     */
    private function rank(User $user): array
    {
        $current = (string) (DealerRank::query()->where('user_id', $user->id)->value('current_rank') ?? 'dealer');
        $parameters = app(CommissionPolicy::class)->snapshot(false)['rules']['rank_royalty']['parameters'];
        $ladder = [];
        foreach (array_slice(CommissionQualification::RankLevels, 1) as $code) {
            $required = CommissionQualification::TeamRankRequirements[$code] ?? null;
            $ladder[] = $required === null
                ? ['rank' => $code, 'kind' => 'own_purchases', 'amount' => Money::decimal(Money::cents((string) ($parameters[$code.'_target'] ?? '0')))]
                : ['rank' => $code, 'kind' => 'team_leaders', 'count' => (int) ($parameters[$code.'_count'] ?? 0), 'required_rank' => $required];
        }

        return ['current' => $current, 'ladder' => $ladder];
    }

    private function clean(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
