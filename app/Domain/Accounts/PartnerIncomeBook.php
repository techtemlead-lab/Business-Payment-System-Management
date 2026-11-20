<?php

namespace App\Domain\Accounts;

use App\Domain\Finance\FundCatalog;
use App\Models\User;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A partner's income, one part at a time: each kind of income has its own name, its own total for the
 * chosen days, its own day-by-day table and its own printed statement. Every figure is read from the
 * same rows My Money reads, so it counts only money that was posted after delivery, and a part's
 * total can never disagree with the wallet.
 */
final class PartnerIncomeBook
{
    /** A row is waiting to be released, ready in the wallet, or taken back after a refund. */
    public const Statuses = ['waiting', 'ready', 'taken_back'];

    /** The longest statement the PDF prints. */
    public const PdfRowLimit = 5000;

    public function __construct(private PartnerMoneyBook $book) {}

    /** The report tabs on the dashboard: every income entry, then one table per head. */
    public const ReportTabs = ['all', 'own', 'gen1', 'gen2', 'team_all', 'rank_bonus', 'royalty', 'area', 'salary', 'other'];

    /**
     * The dashboard report: the tab list, the chosen tab and its table (search, filters and dates as everywhere).
     *
     * @return array{tabs: list<array{key: string, label: string}>, tab: string, table: array<string, mixed>}
     */
    public function reportPage(Request $request, User $partner): array
    {
        $asked = (string) $request->query('rtab', 'all');
        $tab = in_array($asked, self::ReportTabs, true) ? $asked : 'all';
        // The dashboard report opens on every day, so a first look shows all the income, not just this month's.
        $table = MoneyTable::make($request, $this->reportRows($partner, $tab), $this->spec(null));
        $ownOrders = PartnerMoneyBook::ownOrderIds($partner, $table['rows']->getCollection()->pluck('order_id')->all());
        $table['rows'] = $table['rows']->through(fn (object $row): array => [...$this->rowFor($row, $partner->role), 'order_is_own' => $row->order_id !== null && isset($ownOrders[(string) $row->order_id])]);

        return [
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => (string) __('cr_partner.tab_'.$key)], self::ReportTabs),
            'tab' => $tab,
            'table' => $table,
        ];
    }

    /** One report tab's rows as a query the date views can filter. */
    private function reportRows(User $partner, string $tab): Builder
    {
        $rows = DB::query()->fromSub($this->classified((int) $partner->id), 'x');

        return match ($tab) {
            'all' => $rows,
            'gen1' => $rows->where('x.category', 'direct_referral'),
            'gen2' => $rows->where('x.category', 'd2d_gen2'),
            'team_all' => $rows->whereIn('x.category', ['direct_referral', 'd2d_gen2', 'd2d_gen3']),
            'other' => $rows->whereIn('x.part', ['bike', 'car', 'additional_rank', 'reward']),
            default => $rows->where('x.part', $tab),
        };
    }

    /** @return list<string> Every income head key, in display order. */
    public static function parts(): array
    {
        return PartnerIncomeHeads::keys();
    }

    public static function partWord(string $part, ?string $locale = null): string
    {
        return PartnerIncomeHeads::label($part, $locale);
    }

    /**
     * Everything the income screen needs: every head with its totals, the chosen head and its table.
     *
     * @return array{parts: list<array{key: string, label: string, hint: string, amount: string, total: string, entries: int}>, sum: array{amount: string, total: string}, part: string, table: array<string, mixed>}
     */
    public function page(Request $request, User $partner): array
    {
        $overview = $this->overview($request, $partner);
        $parts = $overview['parts'];
        $asked = (string) $request->query('part', '');
        $withMoney = array_values(array_filter($parts, fn (array $part): bool => $part['entries'] > 0 || (float) $part['total'] !== 0.0));
        $part = in_array($asked, array_column($parts, 'key'), true) ? $asked : ($withMoney[0]['key'] ?? $parts[0]['key']);

        return ['parts' => $parts, 'sum' => $overview['sum'], 'part' => $part, 'table' => $this->table($request, $partner, $part)];
    }

    /**
     * Every head the partner's kind of account has, and any head holding money, each with what came in
     * during the chosen days and to date. A head at zero is still listed.
     *
     * @return array{parts: list<array{key: string, label: string, hint: string, amount: string, total: string, entries: int}>, sum: array{amount: string, total: string}}
     */
    public function overview(Request $request, User $partner): array
    {
        $range = MoneyTable::range($request, 'month');
        $ever = $this->book->totalsByHead($partner);
        $inPeriod = $this->book->totalsByHead($partner, $range === null ? [null, null] : [$range['from'], $range['until']]);
        $shown = PartnerIncomeHeads::forRole((string) $partner->role);
        $parts = [];
        $sumPeriod = 0;
        $sumEver = 0;
        foreach (PartnerIncomeHeads::keys() as $key) {
            if (! in_array($key, $shown, true) && ! isset($ever[$key])) {
                continue;
            }
            $sumPeriod += $inPeriod[$key]['cents'] ?? 0;
            $sumEver += $ever[$key]['cents'] ?? 0;
            $parts[] = [
                'key' => $key,
                'label' => PartnerIncomeHeads::label($key, null, $partner->role),
                'hint' => PartnerIncomeHeads::hint($key),
                'amount' => FundCatalog::decimalOf($inPeriod[$key]['cents'] ?? 0),
                'total' => FundCatalog::decimalOf($ever[$key]['cents'] ?? 0),
                'entries' => $inPeriod[$key]['entries'] ?? 0,
            ];
        }

        return ['parts' => $parts, 'sum' => ['amount' => FundCatalog::decimalOf($sumPeriod), 'total' => FundCatalog::decimalOf($sumEver)]];
    }

    /**
     * One part's day-by-day table, with the standard search, filters, date views and sorting.
     *
     * @return array<string, mixed> MoneyTable payload
     */
    public function table(Request $request, User $partner, string $part): array
    {
        $table = MoneyTable::make($request, $this->partRows($partner, $part), $this->spec());
        $ownOrders = PartnerMoneyBook::ownOrderIds($partner, $table['rows']->getCollection()->pluck('order_id')->all());
        $table['rows'] = $table['rows']->through(fn (object $row): array => [...$this->rowFor($row, $partner->role), 'order_is_own' => $row->order_id !== null && isset($ownOrders[(string) $row->order_id])]);

        return $table;
    }

    /**
     * Every row of one part for the chosen days, oldest first, for the printed statement.
     *
     * @return array{rows: list<array<string, mixed>>, total: string, count: int, capped: bool, from: ?CarbonImmutable, until: ?CarbonImmutable}
     */
    public function statement(Request $request, User $partner, string $part): array
    {
        $rows = MoneyTable::filtered($request, $this->partRows($partner, $part), $this->spec());
        $count = (clone $rows)->count();
        $total = (string) (clone $rows)->sum('x.amount');
        $range = MoneyTable::range($request, 'month');

        return [
            'rows' => $rows->orderBy('x.occurred_at')->orderBy('x.id')->limit(self::PdfRowLimit)->get()->map(fn (object $row): array => $this->rowFor($row, $partner->role))->all(),
            'total' => FundCatalog::decimalOf(FundCatalog::centsOf($total)),
            'count' => $count,
            'capped' => $count > self::PdfRowLimit,
            'from' => $range['from'] ?? null,
            'until' => $range['until'] ?? null,
        ];
    }

    private function spec(?string $defaultPeriod = 'month'): MoneyTableSpec
    {
        return new MoneyTableSpec(
            dateColumn: 'x.occurred_at',
            search: ['x.order_number', 'x.from_name'],
            filters: [new MoneyTableFilter('status', (string) __('income.filter_status'), 'x.status', array_map(
                fn (string $status): array => ['value' => $status, 'label' => (string) __('income.status_'.$status)],
                self::Statuses,
            ))],
            sorts: ['date' => 'x.occurred_at', 'amount' => 'x.amount'],
            defaultSort: 'date',
            tieBreaker: 'x.id',
            sums: ['amount' => 'x.amount'],
            defaultPeriod: $defaultPeriod,
        );
    }

    /** One head's rows, as a query the date views can filter. */
    private function partRows(User $partner, string $part): Builder
    {
        abort_unless(in_array($part, self::parts(), true), 404);

        return DB::query()->fromSub(
            DB::query()->fromSub($this->book->headRows((int) $partner->id), 'h')->selectRaw("h.*, CASE WHEN h.amount < 0 THEN 'taken_back' WHEN h.waiting_until IS NOT NULL THEN 'waiting' ELSE 'ready' END AS status"),
            'x',
        )->where('x.head', $part);
    }

    /** Every income row of the partner with its part and its status worked out. */
    private function classified(int $partnerId): Builder
    {
        return DB::query()->fromSub($this->book->incomeRows($partnerId), 'u')->selectRaw(
            "u.*, CASE WHEN u.type = 'reward' THEN CASE WHEN u.program = 'bike' THEN 'bike' WHEN u.program = 'car' THEN 'car' WHEN u.program = 'additional_rank' OR u.category = 'additional_rank_pool' THEN 'additional_rank' ELSE 'reward' END ELSE u.type END AS part,
            CASE WHEN u.amount < 0 THEN 'taken_back' WHEN u.waiting_until IS NOT NULL THEN 'waiting' ELSE 'ready' END AS status",
        );
    }

    /** @return array<string, mixed> */
    private function rowFor(object $row, ?string $role = null): array
    {
        $what = PartnerIncomeHeads::label(PartnerIncomeHeads::headOf($row->type, $row->category, $row->program, $row->kind), null, $role);

        return [
            'id' => (string) $row->id,
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->toIso8601String(),
            'what' => $what,
            'percent' => $row->percent === null ? null : (string) $row->percent,
            'order_id' => $row->order_id,
            'order_number' => $row->order_number,
            'from_name' => $row->from_name,
            'status' => (string) $row->status,
            'waiting_until' => $row->waiting_until,
            'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount)),
        ];
    }
}
