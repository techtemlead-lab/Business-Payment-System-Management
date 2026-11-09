<?php

namespace App\Support;

use App\Domain\Orders\OrderListFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Search, filters, date view, column sort and totals for one money table, all done by the
 * database, so sorting and totals are right across every page and not only for the rows on screen.
 */
final class MoneyTable
{
    private const Periods = ['today', 'week', 'month', 'year', 'day', 'custom'];

    private const LongestRangeInDays = 366;

    private const LongestFilterText = 60;

    /**
     * @return array{rows: LengthAwarePaginator<int, stdClass>, totals: array{count: int, capped: bool, sums: array<string, string>|null}, state: array{q: string, period: string, from: ?string, to: ?string, sort: string, dir: string, filters: array<string, string>}, filters: list<array{key: string, label: string, options: list<array{value: string, label: string, count?: int}>, type?: string, group?: string, from?: string, to?: string, decimal?: bool}>, sortable: list<string>, extra_periods: list<string>}
     */
    public static function make(Request $request, Builder $query, MoneyTableSpec $spec): array
    {
        $view = self::view($request, $spec->defaultPeriod, $spec->extraPeriods);
        $words = self::searchText($request);
        $filtered = (clone $query)->reorder();

        self::applySearch($filtered, $words, $spec->search);
        $appliedFilters = self::chosenFilters($request, $spec->filters);
        self::applyFilters($filtered, $spec->filters, $appliedFilters);
        self::applyView($filtered, $view, $spec->dateColumn);

        [$sort, $direction] = self::sort($request, $spec);
        $totals = self::totals($filtered, $spec, $appliedFilters);
        $page = min(max(1, (int) ceil($totals['count'] / $spec->perPage)), max(1, (int) $request->query('page', '1')));

        $rows = $filtered
            ->orderByRaw($spec->sorts[$sort].' '.$direction.' NULLS LAST')
            ->orderByRaw($spec->tieBreaker.' '.$direction)
            ->forPage($page, $spec->perPage)
            ->get();

        return [
            'rows' => new LengthAwarePaginator($rows, $totals['count'], $spec->perPage, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'totals' => $totals,
            'state' => ['q' => $words, ...$view, 'sort' => $sort, 'dir' => $direction, 'filters' => $appliedFilters],
            'filters' => array_map(fn (MoneyTableFilter $filter): array => $filter->forScreen(), $spec->filters),
            'sortable' => array_keys($spec->sorts),
            'extra_periods' => $spec->extraPeriods,
        ];
    }

    /**
     * The rows `make` would list, before sorting and paging, for figures a page shows beside the table.
     */
    public static function filtered(Request $request, Builder $query, MoneyTableSpec $spec): Builder
    {
        $filtered = (clone $query)->reorder();
        self::applySearch($filtered, self::searchText($request), $spec->search);
        self::applyFilters($filtered, $request, $spec->filters);
        self::applyView($filtered, self::view($request, $spec->defaultPeriod), $spec->dateColumn);

        return $filtered;
    }

    /**
     * The chosen date view as a UTC window in Dhaka days, for read models that apply it themselves.
     *
     * @param  list<string>  $extraPeriods  the table's MoneyTableSpec::$extraPeriods
     * @return array{from: CarbonImmutable, until: CarbonImmutable}|null
     */
    public static function range(Request $request, ?string $defaultPeriod, array $extraPeriods = []): ?array
    {
        $view = self::view($request, $defaultPeriod, $extraPeriods);

        return OrderListFilter::createdRange($view['period'], $view['from'], $view['to']);
    }

    /**
     * What the address really asks for, kept to the choices offered and to values of the right shape.
     * Anything else is ignored. Read models that apply their own filters start from this too.
     *
     * @param  list<MoneyTableFilter>  $filters
     * @return array<string, string> query string name => value
     */
    public static function chosenFilters(Request $request, array $filters): array
    {
        $chosen = [];

        foreach ($filters as $filter) {
            $chosen += match ($filter->type) {
                MoneyTableFilter::NumberRange => self::chosenNumbers($request, $filter),
                MoneyTableFilter::DateRange => self::chosenDates($request, $filter),
                MoneyTableFilter::Text => self::chosenText($request, $filter),
                default => self::chosenOption($request, $filter),
            };
        }

        return $chosen;
    }

    /**
     * The view that will really be used. A missing period means the table's default, an empty one
     * means all time, and anything unknown or unfinished means the default again.
     *
     * @param  list<string>  $extraPeriods
     * @return array{period: string, from: ?string, to: ?string}
     */
    private static function view(Request $request, ?string $defaultPeriod, array $extraPeriods = []): array
    {
        $default = ['period' => $defaultPeriod ?? '', 'from' => null, 'to' => null];
        $period = $request->query('period');
        if (! $request->query->has('period') || is_array($period)) {
            return $default;
        }

        if ($period === null || $period === '') {
            return ['period' => '', 'from' => null, 'to' => null];
        }

        if (! in_array($period, [...self::Periods, ...$extraPeriods], true)) {
            return $default;
        }

        $from = self::dateText($request, 'from');
        $to = self::dateText($request, 'to');

        if ($period === 'day') {
            return $from === null ? $default : ['period' => 'day', 'from' => $from, 'to' => null];
        }

        if ($period === 'custom') {
            if ($from === null || $to === null) {
                return $default;
            }
            self::refuseBadRange($from, $to);

            return ['period' => 'custom', 'from' => $from, 'to' => $to];
        }

        return ['period' => $period, 'from' => null, 'to' => null];
    }

    private static function refuseBadRange(string $from, string $to): void
    {
        if ($to < $from) {
            throw ValidationException::withMessages(['money_table_range' => __('accounting.err_period_order')]);
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::LongestRangeInDays) {
            throw ValidationException::withMessages(['money_table_range' => __('portal.ops_val_date_range')]);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private static function applySearch(Builder $query, string $text, array $columns): void
    {
        $words = preg_split('/\s+/u', LocalDigits::latin($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            $term = '%'.OrderListFilter::escapeLike($word).'%';
            $query->where(function (Builder $row) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $row->orWhereRaw('CAST('.$column.' AS text) ILIKE ?', [$term]);
                }
            });
        }
    }

    /**
     * @return array<string, string>
     */
    private static function chosenOption(Request $request, MoneyTableFilter $filter): array
    {
        $value = self::text($request, $filter->key);

        return in_array($value, array_map(strval(...), array_column($filter->options, 'value')), true) ? [$filter->key => $value] : [];
    }

    /**
     * @return array<string, string>
     */
    private static function chosenNumbers(Request $request, MoneyTableFilter $filter): array
    {
        $shape = $filter->decimal ? '/^\d{1,10}(\.\d{1,2})?$/D' : '/^\d{1,9}$/D';
        $chosen = [];

        foreach ([(string) $filter->from, (string) $filter->to] as $name) {
            $value = LocalDigits::latin(trim(self::text($request, $name)));
            if (preg_match($shape, $value) === 1) {
                $chosen[$name] = $value;
            }
        }

        return $chosen;
    }

    /**
     * Dates picked the wrong way round still mean the same period.
     *
     * @return array<string, string>
     */
    private static function chosenDates(Request $request, MoneyTableFilter $filter): array
    {
        $from = self::dateText($request, (string) $filter->from);
        $to = self::dateText($request, (string) $filter->to);

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return array_filter([(string) $filter->from => $from, (string) $filter->to => $to], fn (?string $date): bool => $date !== null);
    }

    /**
     * @return array<string, string>
     */
    private static function chosenText(Request $request, MoneyTableFilter $filter): array
    {
        $value = mb_substr(trim((string) preg_replace('/\p{Cc}+/u', ' ', mb_scrub(self::text($request, $filter->key), 'UTF-8'))), 0, self::LongestFilterText);

        return $value === '' ? [] : [$filter->key => $value];
    }

    /**
     * @param  list<MoneyTableFilter>  $filters
     * @param  array<string, string>  $chosen
     */
    private static function applyFilters(Builder $query, array $filters, array $chosen): void
    {
        foreach ($filters as $filter) {
            if ($filter->column === null) {
                continue;
            }

            if ($filter->type === MoneyTableFilter::NumberRange || $filter->type === MoneyTableFilter::DateRange) {
                foreach ([[(string) $filter->from, '>='], [(string) $filter->to, '<=']] as [$name, $comparison]) {
                    if (isset($chosen[$name])) {
                        $query->whereRaw($filter->column.' '.$comparison.' ?', [$chosen[$name]]);
                    }
                }

                continue;
            }

            if (! isset($chosen[$filter->key])) {
                continue;
            }

            if ($filter->type === MoneyTableFilter::Text) {
                $query->whereRaw('CAST('.$filter->column.' AS text) ILIKE ?', ['%'.OrderListFilter::escapeLike($chosen[$filter->key]).'%']);

                continue;
            }

            $query->whereRaw($filter->column.' = ?', [$chosen[$filter->key]]);
        }
    }

    /**
     * @param  array{period: string, from: ?string, to: ?string}  $view
     */
    private static function applyView(Builder $query, array $view, ?string $dateColumn): void
    {
        $range = OrderListFilter::createdRange($view['period'], $view['from'], $view['to']);

        if ($dateColumn !== null && $range !== null) {
            $query->whereRaw($dateColumn.' >= ?', [$range['from']])
                ->whereRaw($dateColumn.' <= ?', [$range['until']]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function sort(Request $request, MoneyTableSpec $spec): array
    {
        $sort = self::text($request, 'sort');
        if (! array_key_exists($sort, $spec->sorts)) {
            return [$spec->defaultSort, $spec->defaultDir];
        }

        $direction = self::text($request, 'dir');

        return [$sort, in_array($direction, ['asc', 'desc'], true) ? $direction : $spec->defaultDir];
    }

    /**
     * One pass over the whole filtered set, before it is cut into pages.
     *
     * @param  array<string, string>  $filters
     * @return array{count: int, capped: bool, sums: array<string, string>|null}
     */
    private static function totals(Builder $filtered, MoneyTableSpec $spec, array $filters): array
    {
        $sums = $spec->sumsWhen === null || ($spec->sumsWhen)($filters) ? $spec->sums : [];

        if ($spec->countCap !== null) {
            $seen = self::wholeSet($filtered->clone()->limit($spec->countCap + 1), forceSubquery: true)->count();
            if ($seen > $spec->countCap) {
                return ['count' => $spec->countCap, 'capped' => true, 'sums' => null];
            }
            if ($sums === []) {
                return ['count' => $seen, 'capped' => false, 'sums' => null];
            }
        }

        $columns = ['COUNT(*) AS money_table_count'];
        foreach (array_values($sums) as $index => $expression) {
            $columns[] = 'ROUND(COALESCE(SUM('.$expression.'), 0)::numeric, 2) AS money_table_sum_'.$index;
        }

        /** @var object $row */
        $row = self::wholeSet($filtered)->select(DB::raw(implode(', ', $columns)))->first();

        $sumValues = [];
        foreach (array_keys($sums) as $index => $key) {
            $sumValues[$key] = self::decimal((string) $row->{'money_table_sum_'.$index});
        }

        return ['count' => (int) $row->money_table_count, 'capped' => false, 'sums' => $sums === [] ? null : $sumValues];
    }

    /**
     * A query over the filtered rows that aggregates can read: a grouped or limited
     * query must become a subquery, or its totals would describe only one group.
     */
    private static function wholeSet(Builder $filtered, bool $forceSubquery = false): Builder
    {
        $changesRowCount = $filtered->groups !== null || $filtered->havings !== null || $filtered->distinct !== false || $filtered->unions !== null;

        if (! $forceSubquery && ! $changesRowCount) {
            return $filtered->clone();
        }

        $inner = $changesRowCount ? $filtered->clone() : $filtered->clone()->select(DB::raw('1'));

        return $filtered->newQuery()->fromSub($inner, 'money_table_rows_set');
    }

    /** A signed sum from the database as a plain decimal string, through Money. */
    private static function decimal(string $value): string
    {
        $text = Money::decimal(Money::cents(ltrim($value, '-')));

        return str_starts_with($value, '-') && $text !== '0.00' ? '-'.$text : $text;
    }

    /** The search words: valid text with no control characters, at most 100 letters. */
    private static function searchText(Request $request): string
    {
        $text = (string) preg_replace('/\p{Cc}+/u', ' ', mb_scrub(self::text($request, 'q'), 'UTF-8'));

        return mb_substr(trim($text), 0, 100);
    }

    /** A real calendar date in Y-m-d form, or null. */
    private static function dateText(Request $request, string $name): ?string
    {
        $value = self::text($request, $name);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }

    private static function text(Request $request, string $name): string
    {
        $value = $request->query($name);

        return is_string($value) ? $value : '';
    }
}
