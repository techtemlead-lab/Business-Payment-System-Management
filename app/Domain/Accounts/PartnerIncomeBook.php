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
    /** The parts every partner sees, even when empty. */
    public const CoreParts = ['own', 'referral', 'team', 'area', 'salary', 'royalty', 'rank_bonus'];

    /** Parts shown only once the partner has had money in them. */
    public const ExtraParts = ['bike', 'car', 'additional_rank', 'reward'];

    /** A row is waiting to be released, ready in the wallet, or taken back after a refund. */
    public const Statuses = ['waiting', 'ready', 'taken_back'];

    /** The longest statement the PDF prints. */
    public const PdfRowLimit = 5000;

    public function __construct(private PartnerMoneyBook $book) {}

    /** @return list<string> */
    public static function parts(): array
    {
        return [...self::CoreParts, ...self::ExtraParts];
    }

    public static function partWord(string $part, ?string $locale = null): string
    {
        return (string) __('income.part_'.$part, [], $locale);
    }

    /**
     * Everything the income screen needs: the parts with their totals for the chosen days, the chosen
     * part, and that part's day-by-day table.
     *
     * @return array{parts: list<array{key: string, label: string, hint: string, amount: string, entries: int}>, part: string, table: array<string, mixed>}
     */
    public function page(Request $request, User $partner): array
    {
        $parts = $this->overview($request, $partner);
        $asked = (string) $request->query('part', '');
        $part = in_array($asked, array_column($parts, 'key'), true) ? $asked : $parts[0]['key'];

        return ['parts' => $parts, 'part' => $part, 'table' => $this->table($request, $partner, $part)];
    }

    /**
     * Every part with what came in during the chosen days. A part with no money ever is left out
     * unless every partner sees it.
     *
     * @return list<array{key: string, label: string, hint: string, amount: string, entries: int}>
     */
    public function overview(Request $request, User $partner): array
    {
        $ever = $this->totals($partner, null);
        $inPeriod = $this->totals($partner, MoneyTable::range($request, 'month'));
        $parts = [];
        foreach (self::parts() as $key) {
            if (! in_array($key, self::CoreParts, true) && ! isset($ever[$key])) {
                continue;
            }
            $parts[] = [
                'key' => $key,
                'label' => self::partWord($key),
                'hint' => (string) __('income.hint_'.$key),
                'amount' => FundCatalog::decimalOf($inPeriod[$key]['cents'] ?? 0),
                'entries' => $inPeriod[$key]['entries'] ?? 0,
            ];
        }

        return $parts;
    }

    /**
     * One part's day-by-day table, with the standard search, filters, date views and sorting.
     *
     * @return array<string, mixed> MoneyTable payload
     */
    public function table(Request $request, User $partner, string $part): array
    {
        $table = MoneyTable::make($request, $this->partRows($partner, $part), $this->spec());
        $table['rows'] = $table['rows']->through(fn (object $row): array => $this->rowFor($row));

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
            'rows' => $rows->orderBy('x.occurred_at')->orderBy('x.id')->limit(self::PdfRowLimit)->get()->map(fn (object $row): array => $this->rowFor($row))->all(),
            'total' => FundCatalog::decimalOf(FundCatalog::centsOf($total)),
            'count' => $count,
            'capped' => $count > self::PdfRowLimit,
            'from' => $range['from'] ?? null,
            'until' => $range['until'] ?? null,
        ];
    }

    private function spec(): MoneyTableSpec
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
            defaultPeriod: 'month',
        );
    }

    /** One part's rows, as a query the date views can filter. */
    private function partRows(User $partner, string $part): Builder
    {
        abort_unless(in_array($part, self::parts(), true), 404);

        return DB::query()->fromSub($this->classified((int) $partner->id), 'x')->where('x.part', $part);
    }

    /** Every income row of the partner with its part and its status worked out. */
    private function classified(int $partnerId): Builder
    {
        return DB::query()->fromSub($this->book->incomeRows($partnerId), 'u')->selectRaw(
            "u.*, CASE WHEN u.type = 'reward' THEN CASE WHEN u.program = 'bike' THEN 'bike' WHEN u.program = 'car' THEN 'car' WHEN u.program = 'additional_rank' OR u.category = 'additional_rank_pool' THEN 'additional_rank' ELSE 'reward' END ELSE u.type END AS part,
            CASE WHEN u.amount < 0 THEN 'taken_back' WHEN u.waiting_until IS NOT NULL THEN 'waiting' ELSE 'ready' END AS status",
        );
    }

    /**
     * What each part earned (cents and number of entries) in a window, or ever when there is none.
     *
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     * @return array<string, array{cents: int, entries: int}>
     */
    private function totals(User $partner, ?array $range): array
    {
        $rows = DB::query()->fromSub($this->classified((int) $partner->id), 'x')
            ->when($range !== null, fn (Builder $query) => $query->where('x.occurred_at', '>=', $range['from'])->where('x.occurred_at', '<', $range['until']))
            ->groupBy('x.part')
            ->selectRaw('x.part, SUM(x.amount) AS total, COUNT(*) AS entries')
            ->get();
        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->part] = ['cents' => FundCatalog::centsOf($row->total), 'entries' => (int) $row->entries];
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    private function rowFor(object $row): array
    {
        $what = $row->category !== null
            ? FundCatalog::typeLabel((string) $row->category)
            : ($row->program !== null ? $this->programWord((string) $row->program) : self::partWord((string) $row->part));

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

    private function programWord(string $program): string
    {
        $key = 'portal.reward_program_'.$program;
        $word = (string) __($key);

        return $word === $key ? $program : $word;
    }
}
