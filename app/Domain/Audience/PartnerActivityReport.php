<?php

namespace App\Domain\Audience;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * CR-17, owner only: which dealers and sub-dealers are online now, and when each was last active.
 * Partners who never signed in since tracking began have no row and show as "not seen yet".
 */
final class PartnerActivityReport
{
    public const Filters = ['all', 'dealers', 'sub_dealers', 'online', 'idle'];

    public const PerPage = 25;

    /** "Not seen for a while" means this many days without activity. */
    public const IdleDays = 7;

    /**
     * @return array{filter: string, q: string, counts: array{dealers: int, sub_dealers: int}, rows: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function for(?string $filter, ?string $search, int $page): array
    {
        $filter = in_array($filter, self::Filters, true) ? (string) $filter : 'all';
        $search = trim((string) $search);
        $onlineSince = now()->subMinutes(PartnerActivity::OnlineMinutes);

        $online = DB::table('partner_activity as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('u.role', ['dealer', 'sub_dealer'])->where('a.last_seen_at', '>=', $onlineSince)
            ->selectRaw('u.role, count(*) as total')->groupBy('u.role')->pluck('total', 'role');

        $query = $this->base($filter, $search, $onlineSince);
        $total = (clone $query)->count('u.id');
        $pages = max(1, (int) ceil($total / self::PerPage));
        $page = min(max(1, $page), $pages);
        $rows = $query->orderByRaw('a.last_seen_at is null')->orderByDesc('a.last_seen_at')->orderBy('u.name')
            ->forPage($page, self::PerPage)
            ->get(['u.id', 'u.name', 'u.role', 'u.dealer_code', 'u.district', 'u.division', 'a.last_seen_at', 'a.last_ip', 'a.device_kind', 'a.browser', 'a.os', 'a.last_login_at']);

        return [
            'filter' => $filter,
            'q' => $search,
            'counts' => ['dealers' => (int) ($online['dealer'] ?? 0), 'sub_dealers' => (int) ($online['sub_dealer'] ?? 0)],
            'rows' => $rows->map(fn (\stdClass $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'type' => (string) $row->role,
                'code' => $row->dealer_code,
                'area' => collect([$row->district, $row->division])->filter()->first(),
                'online' => $row->last_seen_at !== null && CarbonImmutable::parse($row->last_seen_at)->greaterThanOrEqualTo($onlineSince),
                'last_seen_at' => $this->iso($row->last_seen_at),
                'ip' => $row->last_ip,
                'device' => $row->device_kind,
                'browser' => $row->browser,
                'os' => $row->os,
                'last_login_at' => $this->iso($row->last_login_at),
            ])->all(),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    private function base(string $filter, string $search, CarbonInterface $onlineSince): Builder
    {
        $query = DB::table('users as u')->leftJoin('partner_activity as a', 'a.user_id', '=', 'u.id')
            ->whereIn('u.role', $filter === 'dealers' ? ['dealer'] : ($filter === 'sub_dealers' ? ['sub_dealer'] : ['dealer', 'sub_dealer']));
        if ($filter === 'online') {
            $query->where('a.last_seen_at', '>=', $onlineSince);
        } elseif ($filter === 'idle') {
            // Never seen counts too: nobody has opened the panel since tracking began.
            $query->where(fn (Builder $q) => $q->whereNull('a.last_seen_at')->orWhere('a.last_seen_at', '<', now()->subDays(self::IdleDays)));
        }
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('u.name', 'ilike', $like)->orWhere('u.phone', 'ilike', $like)->orWhere('u.dealer_code', 'ilike', $like));
        }

        return $query;
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
