<?php

namespace App\Support;

use Closure;

/**
 * What one money table allows: every piece of SQL here is fixed in the code, never taken from the request.
 */
final class MoneyTableSpec
{
    /**
     * @param  ?string  $dateColumn  fixed SQL; null when the read model applies the range itself (MoneyTable::range)
     * @param  list<string>  $search  fixed SQL columns; the words are always a binding
     * @param  list<MoneyTableFilter>  $filters
     * @param  array<string, string>  $sorts  screen key => fixed SQL expression
     * @param  string  $defaultSort  a key of $sorts
     * @param  string  $tieBreaker  fixed SQL for a unique column, with no direction; always the last sort
     * @param  array<string, string>  $sums  result key => fixed SQL for one row's value, MoneyTable adds it up. On a grouped query name the column aliases of its select.
     * @param  ?string  $defaultPeriod  the view when the address names none; null for all time
     * @param  Closure(array<string, string>): bool|null  $sumsWhen  gets the applied filters; false skips sums when they would mean nothing
     * @param  int|null  $countCap  stop counting past this many rows
     * @param  list<string>  $extraPeriods  date views beyond the usual ones, such as last_month; each must be a keyword OrderListFilter::createdRange knows
     */
    public function __construct(
        public ?string $dateColumn,
        public array $search,
        public array $filters,
        public array $sorts,
        public string $defaultSort,
        public string $tieBreaker,
        public string $defaultDir = 'desc',
        public array $sums = [],
        public ?string $defaultPeriod = 'month',
        public ?Closure $sumsWhen = null,
        public ?int $countCap = null,
        public int $perPage = 25,
        public array $extraPeriods = [],
    ) {}
}
