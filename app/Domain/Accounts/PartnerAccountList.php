<?php

namespace App\Domain\Accounts;

use App\Domain\Geography\PlaceTree;
use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderListFilter;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableSpec;
use App\Support\TableWorkbook;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff list of dealers or sub-dealers: search, status, area, rank and
 * "no confirmed order lately" filters, plus each partner's rank, last
 * confirmed order and this month's confirmed purchases. The page and the
 * Excel download share these filters, so the file always matches the list.
 */
final class PartnerAccountList
{
    /** Ranks a dealer can hold, lowest first. */
    public const Ranks = ['dealer', 'mo', 'am', 'sm', 'me'];

    /** "No confirmed order in the last N days" choices. */
    public const QuietDays = [30, 60, 90];

    /**
     * Identity details on a partner's profile. No account screen shows them, so they stay out of the page
     * data; ID papers are opened through the identity permission (PartnerKycDocuments) instead.
     */
    private const PrivateProfileKeys = ['birth_certificate_number', 'date_of_birth'];

    /** The status tab for accounts the owner closed; they are left out of every other tab. */
    public const ClosedStatus = 'closed';

    /** Columns the list shows; never the NID or any secret. */
    private const Columns = ['users.id', 'users.name', 'users.email', 'users.phone', 'users.role', 'users.linked_staff_role', 'users.status', 'users.dealer_code', 'users.division', 'users.district', 'users.latitude', 'users.longitude', 'users.directory_visible', 'users.closed_at', 'users.profile', 'users.sponsor_id', 'users.created_at'];

    /**
     * Validation rules for the list filters, shared by the page and the download.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:40',
            // Place code ids, never names: "Dhaka" can be a division, a district or an upazila.
            'division' => 'nullable|integer|min:1',
            'district' => 'nullable|integer|min:1',
            'rank' => 'nullable|in:'.implode(',', self::Ranks),
            'no_order_days' => 'nullable|in:'.implode(',', self::QuietDays),
        ];
    }

    /**
     * The filters as plain values, read from an already validated request.
     *
     * @return array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}
     */
    public static function filters(Request $request): array
    {
        return [
            'q' => $request->string('q')->trim()->toString(),
            'status' => $request->string('status')->trim()->toString(),
            'division' => $request->string('division')->trim()->toString(),
            'district' => $request->string('district')->trim()->toString(),
            'rank' => $request->string('rank')->trim()->toString(),
            'no_order_days' => (int) $request->integer('no_order_days'),
        ];
    }

    /**
     * Partners of one role matching every filter except status, so the status tabs keep their counts.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @param  BranchScope|null  $branchScope  On a branch picked in the top bar, only that branch's partners.
     * @return EloquentBuilder<User>
     */
    public function matching(string $role, array $filters, ?BranchScope $branchScope = null): EloquentBuilder
    {
        return User::query()
            ->where('users.role', $role)
            ->when($branchScope !== null, fn (EloquentBuilder $query) => $branchScope?->restrictPartners($query))
            ->when($filters['q'] !== '', function (EloquentBuilder $query) use ($filters): void {
                $term = '%'.OrderListFilter::escapeLike($filters['q']).'%';
                $query->where(function (EloquentBuilder $sub) use ($term): void {
                    $sub->where('users.name', 'ilike', $term)
                        ->orWhere('users.dealer_code', 'ilike', $term)
                        ->orWhere('users.email', 'ilike', $term)
                        ->orWhere('users.phone', 'ilike', $term)
                        ->orWhere('users.profile->shop_name', 'ilike', $term);
                });
            })
            ->when($filters['division'] !== '', fn (EloquentBuilder $query) => PlaceTree::whereInside($query, [(int) $filters['division']]))
            ->when($filters['district'] !== '', fn (EloquentBuilder $query) => PlaceTree::whereInside($query, [(int) $filters['district']]))
            ->when($role === 'dealer' && $filters['rank'] !== '', function (EloquentBuilder $query) use ($filters): void {
                // A dealer without a rank row is still a plain dealer.
                if ($filters['rank'] === 'dealer') {
                    $query->whereNotExists(fn (Builder $rank) => $rank->selectRaw('1')->from('dealer_ranks')
                        ->whereColumn('dealer_ranks.user_id', 'users.id')->where('dealer_ranks.current_rank', '<>', 'dealer'));
                } else {
                    $query->whereExists(fn (Builder $rank) => $rank->selectRaw('1')->from('dealer_ranks')
                        ->whereColumn('dealer_ranks.user_id', 'users.id')->where('dealer_ranks.current_rank', $filters['rank']));
                }
            })
            ->when($filters['no_order_days'] > 0, fn (EloquentBuilder $query) => $query->whereNotExists(fn (Builder $orders) => $orders->selectRaw('1')->from('orders')
                ->whereColumn('orders.user_id', 'users.id')
                ->whereIn('orders.status', OrderListFilter::Confirmed)
                ->where('orders.created_at', '>=', now()->subDays($filters['no_order_days']))));
    }

    /**
     * Status tab counts for the filtered partners.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @return array<string, int>
     */
    public function statusCounts(string $role, array $filters, ?BranchScope $branchScope = null): array
    {
        return $this->matching($role, $filters, $branchScope)->toBase()
            ->selectRaw('CASE WHEN users.closed_at IS NULL THEN users.status ELSE ? END AS account_tab', [self::ClosedStatus])
            ->selectRaw('COUNT(*) AS aggregate')->groupBy('account_tab')
            ->pluck('aggregate', 'account_tab')->map(fn ($count): int => (int) $count)->all();
    }

    /**
     * One page of partners, each with rank, last confirmed order and this month's purchases, sorted
     * by the database so the order is right across every page. The search stays the one the
     * Excel download uses, so the file always matches the list.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @param  BranchScope|null  $branchScope  On a branch picked in the top bar, only that branch's partners.
     * @return array{rows: LengthAwarePaginator<int, User>, totals: array{count: int, capped: bool, sums: array<string, string>|null}, state: array{q: string, period: string, from: ?string, to: ?string, sort: string, dir: string, filters: array<string, string>}, sortable: list<string>}
     */
    public function table(Request $request, string $role, array $filters, ?BranchScope $branchScope = null): array
    {
        $sort = $request->query('sort');
        $query = $this->withStatus($this->matching($role, $filters, $branchScope), $filters)->toBase()
            ->select('users.id')
            ->when(in_array($sort, ['last_order_at', 'month_purchase'], true), fn (Builder $rows) => $rows->leftJoinSub($this->activity(), 'activity', 'activity.user_id', '=', 'users.id'))
            ->when($sort === 'rank', fn (Builder $rows) => $rows->leftJoin('dealer_ranks', 'dealer_ranks.user_id', '=', 'users.id'));

        $table = MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: null,
            search: [],
            filters: [],
            sorts: [
                'name' => 'users.name',
                'place' => "COALESCE(users.profile->>'place_code', users.profile->>'area_code')",
                'rank' => "CASE COALESCE(dealer_ranks.current_rank, 'dealer') WHEN 'dealer' THEN 0 WHEN 'mo' THEN 1 WHEN 'am' THEN 2 WHEN 'sm' THEN 3 WHEN 'me' THEN 4 ELSE 0 END",
                'last_order_at' => 'activity.last_order_at',
                'month_purchase' => 'activity.month_purchase',
                'status' => 'users.status',
                'newest' => 'users.id',
            ],
            defaultSort: 'newest',
            tieBreaker: 'users.id',
            defaultPeriod: null,
            perPage: 20,
        ));

        $partners = $this->withListFacts($table['rows']->getCollection()->pluck('id')->all(), $role);
        /** @var LengthAwarePaginator<int, User> $rows */
        $rows = $table['rows']->through(fn (object $row): User => $partners[$row->id]);

        return ['rows' => $rows, 'totals' => $table['totals'], 'state' => $table['state'], 'sortable' => $table['sortable']];
    }

    /**
     * The partners of one page with what the list shows about each: rank, last confirmed order and this month's purchases.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function withListFacts(array $ids, string $role): Collection
    {
        $partners = User::query()->whereIn('users.id', $ids)
            ->select(self::Columns)
            ->addSelect(['current_rank' => DB::table('dealer_ranks')->select('current_rank')->whereColumn('dealer_ranks.user_id', 'users.id')->limit(1)])
            ->with('sponsor:id,name,dealer_code')
            ->get()
            ->keyBy('id');
        $activity = $this->activity()->whereIn('orders.user_id', $ids)->get()->keyBy('user_id');
        $partners->each(function (User $user) use ($activity, $role): void {
            $row = $activity->get($user->id);
            $user->setAttribute('current_rank', $role === 'dealer' ? ($user->getAttribute('current_rank') ?? 'dealer') : null);
            $user->setAttribute('last_order_at', $row?->last_order_at ? CarbonImmutable::parse($row->last_order_at, 'UTC')->toIso8601String() : null);
            $user->setAttribute('month_purchase', $this->decimal($row?->month_purchase));
            $user->setAttribute('profile', array_diff_key((array) $user->profile, array_flip(self::PrivateProfileKeys)));
        });

        return $partners;
    }

    /**
     * Every filtered partner as one export row, newest first like the list.
     *
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @param  BranchScope|null  $branchScope  On a branch picked in the top bar, only that branch's partners.
     * @return Generator<int, array<string, mixed>>
     */
    public function exportRows(string $role, array $filters, ?BranchScope $branchScope = null): Generator
    {
        $query = $this->withStatus($this->matching($role, $filters, $branchScope), $filters)->toBase()
            ->leftJoin('users as sponsors', 'sponsors.id', '=', 'users.sponsor_id')
            ->leftJoin('dealer_ranks', 'dealer_ranks.user_id', '=', 'users.id')
            ->leftJoinSub($this->activity(), 'activity', 'activity.user_id', '=', 'users.id')
            ->select(['users.id', 'users.name', 'users.email', 'users.phone', 'users.role', 'users.status', 'users.dealer_code', 'users.division', 'users.district', 'users.profile', 'users.created_at',
                'sponsors.name as sponsor_name', 'sponsors.dealer_code as sponsor_code', 'dealer_ranks.current_rank', 'activity.last_order_at', 'activity.month_purchase'])
            ->orderByDesc('users.id');
        foreach ($query->cursor() as $row) {
            $profile = json_decode((string) ($row->profile ?? '{}'), true);
            $profile = is_array($profile) ? $profile : [];
            yield [
                'name' => $row->name,
                'name_bn' => $profile['name_bn'] ?? '',
                'shop_name' => $profile['shop_name'] ?? '',
                'phone' => $row->phone,
                'email' => $row->email,
                'dealer_code' => $row->dealer_code,
                'sponsor' => trim(($row->sponsor_name ?? '').($row->sponsor_code ? ' ('.$row->sponsor_code.')' : '')),
                'place' => $profile['place_code'] ?? ($profile['area_code'] ?? ''),
                'role' => __('portal.acct_role_'.$row->role),
                'district' => $row->district,
                'division' => $row->division,
                'joined' => $row->created_at ? CarbonImmutable::parse($row->created_at, 'UTC')->timezone('Asia/Dhaka')->format('d M Y') : '',
                'status' => $this->statusLabel((string) $row->status),
                'rank' => __('portal.net_rank_'.($row->current_rank ?: 'dealer')),
                'last_order_at' => $row->last_order_at ? CarbonImmutable::parse($row->last_order_at, 'UTC')->timezone('Asia/Dhaka')->format('d M Y') : '',
                'month_purchase' => $this->decimal($row->month_purchase),
            ];
        }
    }

    /**
     * Divisions and their districts that partners of this role live in, for the area
     * filters, worked out from the partners' place codes (values are place code ids).
     *
     * @return list<array{division_id: int, division: string, districts: list<array{id: int, name: string}>}>
     */
    public function areaOptions(string $role): array
    {
        return PlaceTree::divisionAndDistrictOptions(User::query()->where('users.role', $role));
    }

    /**
     * The chosen division/district filter as names for a summary line.
     */
    public static function placeName(string $placeId): string
    {
        return (string) (PlaceCode::query()->whereKey((int) $placeId)->value('name') ?? $placeId);
    }

    /**
     * Last confirmed order and this Dhaka month's confirmed purchases, one row per partner.
     */
    private function activity(): Builder
    {
        $monthStart = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->utc();

        return DB::table('orders')
            ->whereIn('orders.status', OrderListFilter::Confirmed)
            ->groupBy('orders.user_id')
            ->select('orders.user_id')
            ->selectRaw('MAX(orders.created_at) AS last_order_at')
            ->selectRaw('COALESCE(SUM('.DeliveredMoney::valueSql().') FILTER (WHERE orders.created_at >= ?), 0) AS month_purchase', [$monthStart]);
    }

    /**
     * @param  EloquentBuilder<User>  $query
     * @param  array{q: string, status: string, division: string, district: string, rank: string, no_order_days: int}  $filters
     * @return EloquentBuilder<User>
     */
    private function withStatus(EloquentBuilder $query, array $filters): EloquentBuilder
    {
        if ($filters['status'] === self::ClosedStatus) {
            return $query->whereNotNull('users.closed_at');
        }

        return $query->whereNull('users.closed_at')
            ->when($filters['status'] !== '', fn (EloquentBuilder $q) => $q->where('users.status', $filters['status']));
    }

    private function decimal(mixed $value): string
    {
        return Money::decimal(max(0, TableWorkbook::signedCents($value)));
    }

    private function statusLabel(string $status): string
    {
        $key = 'portal.status_'.$status;
        $label = __($key);

        return $label === $key ? ucfirst($status) : $label;
    }
}
