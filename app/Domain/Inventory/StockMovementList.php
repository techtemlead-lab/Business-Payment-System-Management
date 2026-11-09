<?php

namespace App\Domain\Inventory;

use App\Domain\Orders\OrderListFilter;
use App\Models\CompanyBranch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The stock movement log (স্টক আসা-যাওয়া): search, Dhaka date range, branch,
 * product and movement type filters, units in and out, and totals for the
 * whole filtered range. The page and the Excel download share these filters
 * and the same top-bar branch scope.
 */
final class StockMovementList
{
    /** Every kind of stock movement the app writes. */
    public const Kinds = [BranchOperations::KindProductIn, BranchOperations::KindProductOut, 'dispatch', 'receipt', 'adjustment', 'opening_import', 'transfer_in', 'transfer_out'];

    /**
     * Validation rules for the list filters, shared by the page and the download.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'q' => 'nullable|string|max:100',
            'period' => 'nullable|in:today,week,month,last_month,year,day,custom',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|min:1',
            'movement' => 'nullable|in:'.implode(',', self::Kinds),
        ];
    }

    /**
     * Which branches the log shows: the top-bar branch, or every branch on "All
     * branches" for staff who may view them, the same as the stock on hand above it.
     */
    public static function scope(User $actor, Request $request, ?CompanyBranch $activeBranch): BranchScope
    {
        if ($request->session()->get(BranchOperations::SessionView) === 'all' && app(BranchOperations::class)->canViewAllBranches($actor)) {
            return new BranchScope(true, []);
        }

        return new BranchScope(false, $activeBranch ? [$activeBranch->id] : [], $activeBranch);
    }

    /**
     * The name of a movement type in the reader's language, like the list on screen.
     */
    public static function kindLabel(string $kind): string
    {
        foreach (['portal.stock_kind_'.$kind, 'portal.label_'.$kind] as $key) {
            $label = __($key);
            if ($label !== $key) {
                return $label;
            }
        }

        return ucfirst(str_replace('_', ' ', $kind));
    }

    /**
     * A stored reason in plain words: a reason code ("damaged" or "damaged: broken
     * lid") reads the same as on screen, and free text stays as it was typed.
     */
    public static function reasonText(string $reason): string
    {
        [$code, $note] = array_pad(explode(':', $reason, 2), 2, null);
        $key = 'portal.stock_reason_code_'.trim($code);
        $words = __($key);
        if ($words === $key) {
            return $reason;
        }

        return $note === null ? $words : $words.':'.$note;
    }

    /**
     * The filters as plain values, read from an already validated request.
     *
     * @return array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}
     */
    public static function filters(Request $request): array
    {
        return [
            'q' => $request->string('q')->trim()->toString(),
            'period' => $request->string('period')->toString(),
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
            'branch_id' => (int) $request->integer('branch_id'),
            'product_id' => (int) $request->integer('product_id'),
            'movement' => $request->string('movement')->toString(),
        ];
    }

    /**
     * One page of movements, newest first. Pass the count from totals() to skip a second count query.
     *
     * @param  array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function page(array $filters, BranchScope $scope, int $perPage = 20, ?int $total = null): LengthAwarePaginator
    {
        return $this->listed($filters, $scope)->paginate($perPage, ['*'], 'page', null, $total)->withQueryString();
    }

    /**
     * Units in, units out and the number of movements for the whole filtered list.
     *
     * @param  array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}  $filters
     * @return array{count: int, units_in: int, units_out: int, net: int}
     */
    public function totals(array $filters, BranchScope $scope): array
    {
        $row = $this->matching($filters, $scope)
            ->selectRaw('COUNT(*) AS movement_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN stock_movements.quantity > 0 THEN stock_movements.quantity ELSE 0 END), 0) AS units_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN stock_movements.quantity < 0 THEN -stock_movements.quantity ELSE 0 END), 0) AS units_out')
            ->first();
        $in = (int) ($row->units_in ?? 0);
        $out = (int) ($row->units_out ?? 0);

        return ['count' => (int) ($row->movement_count ?? 0), 'units_in' => $in, 'units_out' => $out, 'net' => $in - $out];
    }

    /**
     * Every filtered movement as one export row, newest first like the list.
     *
     * @param  array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}  $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function exportRows(array $filters, BranchScope $scope): Generator
    {
        foreach ($this->listed($filters, $scope)->lazy(500) as $row) {
            yield [
                'date' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->timezone('Asia/Dhaka')->format('d M Y H:i'),
                'product' => $row->name,
                'sku' => $row->sku,
                'branch' => $row->branch_name ?: ($row->branch_code ?: ''),
                'kind' => self::kindLabel((string) $row->kind),
                'units_in' => $row->quantity > 0 ? (int) $row->quantity : null,
                'units_out' => $row->quantity < 0 ? (int) -$row->quantity : null,
                'reason' => self::reasonText((string) $row->reason),
                'actor' => $row->actor_name,
            ];
        }
    }

    /**
     * Movements matching the filters with product, branch and staff names, newest first.
     *
     * @param  array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}  $filters
     */
    private function listed(array $filters, BranchScope $scope): Builder
    {
        return $this->matching($filters, $scope)
            ->leftJoin('company_branches', 'company_branches.id', '=', 'stock_movements.branch_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.actor_id')
            ->select('stock_movements.id', 'stock_movements.product_id', 'stock_movements.branch_id', 'stock_movements.quantity', 'stock_movements.kind', 'stock_movements.reason', 'stock_movements.created_at',
                'products.name', 'products.sku', 'company_branches.code as branch_code', 'company_branches.name as branch_name', 'users.name as actor_name')
            ->selectRaw('GREATEST(stock_movements.quantity, 0) AS units_in')
            ->selectRaw('GREATEST(-stock_movements.quantity, 0) AS units_out')
            ->orderByDesc('stock_movements.id');
    }

    /**
     * @param  array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}  $filters
     */
    private function matching(array $filters, BranchScope $scope): Builder
    {
        $range = OrderListFilter::createdRange($filters['period'], $filters['from'], $filters['to']);

        return $scope->restrict(DB::table('stock_movements'), 'stock_movements.branch_id')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->when($range !== null, fn (Builder $query) => $query->where('stock_movements.created_at', '>=', $range['from'])->where('stock_movements.created_at', '<=', $range['until']))
            ->when($scope->allBranches && $filters['branch_id'] > 0, fn (Builder $query) => $query->where('stock_movements.branch_id', $filters['branch_id']))
            ->when($filters['product_id'] > 0, fn (Builder $query) => $query->where('stock_movements.product_id', $filters['product_id']))
            ->when($filters['movement'] !== '', fn (Builder $query) => $query->where('stock_movements.kind', $filters['movement']))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.OrderListFilter::escapeLike($filters['q']).'%';
                $query->where(fn (Builder $sub) => $sub->where('products.name', 'ilike', $term)
                    ->orWhere('products.sku', 'ilike', $term)
                    ->orWhere('stock_movements.reason', 'ilike', $term));
            });
    }
}
