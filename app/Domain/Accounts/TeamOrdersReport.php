<?php

namespace App\Domain\Accounts;

use App\Domain\Orders\OrderListFilter;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every confirmed order placed by the people under one partner (their whole downline,
 * read from the referral tree), with paging, filters and totals for the whole range.
 * The partner full report shows only the latest few; this is the full list.
 */
final class TeamOrdersReport
{
    public const PerPage = 25;

    /**
     * @param  array{from: string, to: string, member: ?int, status: ?string}  $filters
     * @return array{rows: LengthAwarePaginator<int, array<string, mixed>>, totals: array{orders: int, buyers: int, ordered: string, paid: string, due: string}, members: list<array{id: int, name: string, dealer_code: ?string}>}
     */
    public function for(User $leader, array $filters): array
    {
        $page = $this->filtered($leader, $filters)
            ->orderByDesc('orders.created_at')
            ->orderByDesc('orders.id')
            ->paginate(self::PerPage, $this->columns())
            ->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (object $row): array => $this->present($row)));

        return [
            'rows' => $page,
            'totals' => $this->totals($leader, $filters),
            'members' => $this->members($leader),
        ];
    }

    /**
     * Every matching row, oldest first, for the Excel download.
     *
     * @param  array{from: string, to: string, member: ?int, status: ?string}  $filters
     * @return iterable<array<string, mixed>>
     */
    public function exportRows(User $leader, array $filters): iterable
    {
        $rows = $this->filtered($leader, $filters)
            ->orderBy('orders.created_at')
            ->orderBy('orders.id')
            ->select($this->columns())
            ->lazy(500, 'orders.id');
        foreach ($rows as $row) {
            yield $this->present($row);
        }
    }

    /**
     * Totals for every matching order, not only the page on screen.
     *
     * @param  array{from: string, to: string, member: ?int, status: ?string}  $filters
     * @return array{orders: int, buyers: int, ordered: string, paid: string, due: string}
     */
    public function totals(User $leader, array $filters): array
    {
        $sums = $this->filtered($leader, $filters)
            ->selectRaw('COUNT(*) AS orders_count, COUNT(DISTINCT orders.user_id) AS buyers, COALESCE(SUM(orders.total), 0) AS ordered, COALESCE(SUM(orders.paid), 0) AS paid, COALESCE(SUM(GREATEST(orders.total - orders.paid - COALESCE(orders.due_credit, 0), 0)), 0) AS due')
            ->first();

        return [
            'orders' => (int) ($sums->orders_count ?? 0),
            'buyers' => (int) ($sums->buyers ?? 0),
            'ordered' => Money::decimal(Money::cents((string) ($sums->ordered ?? '0'))),
            'paid' => Money::decimal(Money::cents((string) ($sums->paid ?? '0'))),
            'due' => Money::decimal(Money::cents((string) ($sums->due ?? '0'))),
        ];
    }

    /**
     * Everyone in the partner's downline, for the member filter.
     *
     * @return list<array{id: int, name: string, dealer_code: ?string}>
     */
    public function members(User $leader): array
    {
        return DB::table('users')
            ->whereIn('id', $this->downline($leader))
            ->orderBy('name')
            ->get(['id', 'name', 'dealer_code'])
            ->map(fn (object $member): array => [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
                'dealer_code' => $member->dealer_code,
            ])
            ->all();
    }

    /** True when the member sits anywhere under the leader in the referral tree. */
    public function inDownline(User $leader, int $memberId): bool
    {
        return ReferralTreePath::isDescendant((int) $leader->id, $memberId);
    }

    /**
     * Confirmed orders by anyone under the leader, inside the filters.
     *
     * @param  array{from: string, to: string, member: ?int, status: ?string}  $filters
     */
    private function filtered(User $leader, array $filters): Builder
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $fromUtc = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], $tz)->startOfDay()->utc();
        $toUtc = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], $tz)->addDay()->startOfDay()->utc();
        $statuses = $filters['status'] !== null && in_array($filters['status'], OrderListFilter::Confirmed, true)
            ? [$filters['status']]
            : OrderListFilter::Confirmed;

        return DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereIn('orders.user_id', $this->downline($leader))
            ->when($filters['member'] !== null, fn (Builder $query) => $query->where('orders.user_id', $filters['member']))
            ->whereIn('orders.status', $statuses)
            ->where('orders.created_at', '>=', $fromUtc)
            ->where('orders.created_at', '<', $toUtc);
    }

    /** The ids of everyone below the leader, as a sub-query. */
    private function downline(User $leader): Builder
    {
        return DB::table('referral_tree_paths')
            ->select('descendant_id')
            ->where('ancestor_id', $leader->id)
            ->where('depth', '>', 0);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'orders.id',
            'orders.number',
            'orders.status',
            'orders.total',
            'orders.paid',
            'orders.due_credit',
            'orders.created_at',
            'orders.user_id',
            'users.name as member_name',
            'users.dealer_code as member_code',
            'users.role as member_role',
        ];
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $total = Money::cents((string) $row->total);
        $paid = Money::cents((string) $row->paid);
        $due = max(0, $total - $paid - Money::cents((string) ($row->due_credit ?? '0.00')));

        return [
            'id' => (int) $row->id,
            'number' => (string) $row->number,
            'status' => (string) $row->status,
            'member_id' => (int) $row->user_id,
            'member_name' => (string) $row->member_name,
            'member_code' => $row->member_code,
            'member_role' => (string) $row->member_role,
            'total' => Money::decimal($total),
            'paid' => Money::decimal($paid),
            'due' => Money::decimal($due),
            'created_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
        ];
    }
}
