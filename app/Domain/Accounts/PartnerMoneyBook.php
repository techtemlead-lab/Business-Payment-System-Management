<?php

namespace App\Domain\Accounts;

use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\OrderMoneyReport;
use App\Models\User;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One partner's money: what they have now, where every taka came from and went, and their
 * lifetime figures. The income cards and the statement rows come from one query, so a card and
 * the filtered total under it can never disagree, and the rows add up to the wallet.
 */
final class PartnerMoneyBook
{
    /** Kinds of money the partner earned. */
    public const IncomeTypes = ['own', 'referral', 'team', 'area', 'salary', 'royalty', 'rank_bonus', 'reward'];

    /** Every kind of row on a statement. */
    public const Types = [...self::IncomeTypes, 'opening', 'returned', 'withdrawn', 'spent', 'taken_back'];

    private const Accounts = "('wallet_liability', 'commission_held_liability')";

    /**
     * @return array{available: string, waiting: string, asked: string, total: string, lifetime: array{ordered_mrp: string, cash_given: string, withdrawn: string, spent: string, earned: string}}
     */
    public function summary(User $partner): array
    {
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first(['available', 'held', 'commission_held']);
        $available = FundCatalog::centsOf($wallet?->available);
        $held = FundCatalog::centsOf($wallet?->held);
        $waiting = FundCatalog::centsOf($wallet?->commission_held);
        $delivered = OrderMoneyReport::deliveredOrders()->where('o.user_id', $partner->id)
            ->selectRaw('COALESCE(SUM('.OrderMoneyReport::mrpSql().'), 0) AS ordered_mrp, COALESCE(SUM('.OrderMoneyReport::cashGivenSql().'), 0) AS cash_given')->first();
        $withdrawn = DB::table('withdrawals')->where('user_id', $partner->id)->where('status', 'paid')->sum('amount');
        $earned = array_sum(array_map(fn (object $row): int => FundCatalog::centsOf($row->total), array_filter($this->totalsByType($partner), fn (object $row): bool => in_array($row->type, self::IncomeTypes, true))));

        return [
            'available' => Money::decimal($available),
            'waiting' => Money::decimal($waiting),
            'asked' => Money::decimal($held - $waiting),
            'total' => Money::decimal($available + $held),
            'lifetime' => [
                'ordered_mrp' => (string) $delivered->ordered_mrp,
                'cash_given' => (string) $delivered->cash_given,
                'withdrawn' => (string) $withdrawn,
                'spent' => $this->spentOnOrders($partner),
                'earned' => FundCatalog::decimalOf($earned),
            ],
        ];
    }

    /**
     * One card per kind of income the partner has money in.
     *
     * @return list<array{key: string, label: string, amount: string}>
     */
    public function incomeCards(User $partner): array
    {
        $cards = [];
        foreach ($this->totalsByType($partner) as $row) {
            if (in_array($row->type, [...self::IncomeTypes, 'opening'], true) && FundCatalog::centsOf($row->total) !== 0) {
                $cards[] = ['key' => (string) $row->type, 'label' => self::typeWord((string) $row->type), 'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->total))];
            }
        }

        return $cards;
    }

    /**
     * What people earned between two moments, head by head. Read from the statement rows, so every
     * other page that shows earnings adds up to the same figure as My Money: money held back counts
     * only once it is released, and money taken back after a refund is taken off.
     *
     * @param  int|Builder  $people  One partner's id, or a sub-query of partner ids.
     * @return list<array{head: string, amount: int, entries: int}> Amounts are in cents.
     */
    public function earnedByHead(int|Builder $people, ?CarbonInterface $from = null, ?CarbonInterface $until = null): array
    {
        return DB::query()->fromSub($this->statementRows($people), 'u')
            ->whereIn('u.type', self::IncomeTypes)
            ->when($from !== null, fn (Builder $query) => $query->where('u.occurred_at', '>=', $from))
            ->when($until !== null, fn (Builder $query) => $query->where('u.occurred_at', '<', $until))
            ->groupByRaw('COALESCE(u.category, u.type)')
            ->orderByRaw('COALESCE(u.category, u.type)')
            ->selectRaw('COALESCE(u.category, u.type) AS head, SUM(u.amount) AS total, COUNT(*) FILTER (WHERE u.amount > 0) AS entries')
            ->get()
            ->map(fn (object $row): array => ['head' => (string) $row->head, 'amount' => FundCatalog::centsOf($row->total), 'entries' => (int) $row->entries])
            ->reject(fn (array $row): bool => $row['amount'] === 0)
            ->values()
            ->all();
    }

    /**
     * The whole of earnedByHead() as one figure, in cents.
     *
     * @param  int|Builder  $people  One partner's id, or a sub-query of partner ids.
     */
    public function earnedTotal(int|Builder $people, ?CarbonInterface $from = null, ?CarbonInterface $until = null): int
    {
        return array_sum(array_column($this->earnedByHead($people, $from, $until), 'amount'));
    }

    /** A commission head or kind of income as people read it. */
    public static function headWord(string $head, ?string $locale = null): string
    {
        foreach (['portal.report_cat_'.$head, 'my_money.type_'.$head] as $key) {
            $word = (string) __($key, [], $locale);
            if ($word !== $key) {
                return $word;
            }
        }

        return Str::headline($head);
    }

    /**
     * Every movement of this partner's money. The running total is worked out on all rows before
     * any filter; once a type is chosen it means nothing and is sent as null.
     *
     * @return array<string, mixed> MoneyTable payload
     */
    public function table(Request $request, User $partner): array
    {
        $rows = DB::query()->fromSub($this->statementRows((int) $partner->id), 'u')
            ->selectRaw('u.*, SUM(u.amount) OVER (ORDER BY u.occurred_at, u.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running');
        $table = MoneyTable::make($request, DB::query()->fromSub($rows, 'x'), new MoneyTableSpec(
            dateColumn: 'x.occurred_at',
            search: ['x.order_number', 'x.from_name'],
            filters: [new MoneyTableFilter('type', (string) __('treasury.filter_type'), 'x.type', array_map(
                fn (string $type): array => ['value' => $type, 'label' => self::typeWord($type)],
                self::Types,
            ))],
            sorts: ['date' => 'x.occurred_at', 'amount' => 'x.amount'],
            defaultSort: 'date',
            tieBreaker: 'x.id',
            sums: ['amount' => 'x.amount'],
            defaultPeriod: null,
        ));
        $typeChosen = isset($table['state']['filters']['type']);
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => $row->id,
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->toIso8601String(),
            'type' => $row->type,
            'type_label' => $this->rowWord($row),
            'percent' => $row->percent === null ? null : (string) $row->percent,
            'order_id' => $row->order_id,
            'order_number' => $row->order_number,
            'from_name' => $row->from_name,
            'withdrawal_id' => $row->withdrawal_id,
            'receipt_url' => $row->withdrawal_id !== null ? '/withdrawals/'.$row->withdrawal_id.'/receipt' : null,
            'waiting_until' => $row->waiting_until,
            'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount)),
            'running' => $typeChosen ? null : FundCatalog::decimalOf(FundCatalog::centsOf($row->running)),
        ]);

        return $table;
    }

    /**
     * The partner's own requests that are not statement rows: waiting, approved, or refused in the
     * last 30 days. Once paid, a request is a statement row and leaves this list.
     *
     * @return list<array{id: string, created_at: string, amount: string, status: string, reason: ?string}>
     */
    public function withdrawalRequests(User $partner): array
    {
        return DB::table('withdrawals')
            ->where('user_id', $partner->id)
            ->where(fn (Builder $query) => $query->whereIn('status', ['requested', 'approved'])
                ->orWhere(fn (Builder $refused) => $refused->where('status', 'rejected')->where('updated_at', '>=', now()->subDays(30))))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'created_at', 'amount', 'status', 'reason'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'created_at' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->toIso8601String(),
                'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount)),
                'status' => (string) $row->status,
                'reason' => $row->status === 'rejected' ? (string) $row->reason : null,
            ])
            ->all();
    }

    /**
     * Area commission the partner did not earn because they missed that month's target, newest
     * month first. None of it was ever in their wallet.
     *
     * @return list<array{month: string, amount: string}>
     */
    public function notEarnedAreaCommission(User $partner): array
    {
        return DB::table('commission_allocations')
            ->where('user_id', $partner->id)
            ->where('status', 'forfeited')
            ->where('category', 'like', 'area_%')
            ->groupByRaw("metadata->>'period_month'")
            ->orderByRaw("metadata->>'period_month' DESC")
            ->limit(12)
            ->selectRaw("metadata->>'period_month' AS month, SUM(amount) AS amount")
            ->get()
            ->map(fn (object $row): array => ['month' => (string) $row->month, 'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount))])
            ->all();
    }

    /** @return list<object> */
    private function totalsByType(User $partner): array
    {
        return DB::query()->fromSub($this->statementRows((int) $partner->id), 'u')->groupBy('u.type')->selectRaw('u.type, SUM(u.amount) AS total')->get()->all();
    }

    private function spentOnOrders(User $partner): string
    {
        $paid = (string) DB::table('receipts as r')->join('orders as o', 'o.id', '=', 'r.order_id')->where('o.user_id', $partner->id)->where('r.channel', 'Account')->sum('r.amount');
        $toAccount = OrderMoneyReport::refundToAccountSql();
        $returned = (string) DB::table('order_refunds as f')->where('f.user_id', $partner->id)->selectRaw("COALESCE(SUM({$toAccount}), 0) AS total")->value('total');

        return FundCatalog::decimalOf(FundCatalog::centsOf($paid) - FundCatalog::centsOf($returned));
    }

    /** The three kinds of movement, as one list of rows. */
    private function statementRows(int|Builder $people): Builder
    {
        return $this->commissionRows($people)->unionAll($this->entryRows($people))->unionAll($this->takenBackRows($people));
    }

    /** Limits a query to one partner, or to a sub-query of partners. */
    private function forPeople(Builder $query, string $column, int|Builder $people): Builder
    {
        return is_int($people) ? $query->where($column, $people) : $query->whereIn($column, $people);
    }

    /** Commission credited when an order posted, one row per commission line. */
    private function commissionRows(int|Builder $people): Builder
    {
        return $this->forPeople(DB::table('commission_allocations as a'), 'a.user_id', $people)
            ->join('journal_entries as j', 'j.event_key', '=', DB::raw("'commissions.order.' || a.order_id::text"))
            ->join('orders as o', 'o.id', '=', 'a.order_id')->leftJoin('users as buyer', 'buyer.id', '=', 'o.user_id')
            ->whereRaw("a.metadata->>'released_at' IS NULL")
            ->whereRaw("(a.status IN ('available', 'maturing') OR (a.status = 'reversed' AND NOT EXISTS (SELECT 1 FROM commission_reversals cr WHERE cr.allocation_id = a.id AND cr.source_status = 'held')))")
            ->whereRaw('COALESCE(a.original_amount, a.amount) > 0')
            ->selectRaw("('a-' || a.id::text) AS id, j.created_at AS occurred_at, ".self::typeOfCategory('a.category')." AS type, a.category, a.rate_percentage AS percent, o.id::text AS order_id, o.number AS order_number, buyer.name AS from_name, NULL AS withdrawal_id, CASE WHEN a.status = 'maturing' THEN a.metadata->>'maturation_due_at' END AS waiting_until, COALESCE(a.original_amount, a.amount) AS amount");
    }

    /** Every other money movement, one row per record, typed by what caused it. */
    private function entryRows(int|Builder $people): Builder
    {
        $accounts = self::Accounts;
        $entries = $this->forPeople(DB::table('journal_lines as l'), 'l.user_id', $people)->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->whereRaw("l.account IN {$accounts}")
            ->whereRaw("NOT starts_with(j.event_key, 'commissions.order.') AND NOT starts_with(j.event_key, 'commissions.reversal.')")
            ->groupBy('j.id', 'j.event_key', 'j.created_at', 'l.user_id')->havingRaw('SUM(l.credit) <> SUM(l.debit)')
            ->selectRaw('j.id, j.event_key, j.created_at, SUM(l.credit) - SUM(l.debit) AS net');
        $holdRelease = "starts_with(e.event_key, 'commission.hold_release.')";
        $accountPay = "starts_with(e.event_key, 'order.account_pay.')";
        $accountReturn = "starts_with(e.event_key, 'order.account_return.')";
        $payout = "starts_with(e.event_key, 'manual-payout:')";

        return DB::query()->fromSub($entries, 'e')
            ->leftJoin('commission_allocations as h', 'h.id', '=', DB::raw("(CASE WHEN {$holdRelease} THEN split_part(e.event_key, '.', 3)::bigint END)"))
            ->leftJoin('order_refunds as f', 'f.id', '=', DB::raw("(CASE WHEN {$accountReturn} THEN split_part(e.event_key, '.', 3)::uuid END)"))
            ->leftJoin('orders as o', 'o.id', '=', DB::raw("COALESCE(h.order_id, f.order_id, (CASE WHEN {$accountPay} THEN split_part(e.event_key, '.', 3)::uuid END))"))
            ->leftJoin('users as buyer', 'buyer.id', '=', 'o.user_id')
            ->selectRaw("('j-' || e.id::text) AS id, e.created_at AS occurred_at, CASE WHEN {$holdRelease} THEN ".self::typeOfCategory('h.category')."
                WHEN starts_with(e.event_key, 'commissions.settlement.salary.') THEN 'salary'
                WHEN starts_with(e.event_key, 'commissions.settlement.royalty.') THEN 'royalty'
                WHEN starts_with(e.event_key, 'commissions.settlement.') THEN 'rank_bonus'
                WHEN starts_with(e.event_key, 'reserve.award.') THEN 'reward'
                WHEN starts_with(e.event_key, 'opening.') THEN 'opening'
                WHEN {$accountReturn} THEN 'returned'
                WHEN {$payout} THEN 'withdrawn'
                WHEN {$accountPay} THEN 'spent'
                WHEN starts_with(e.event_key, 'commissions.debt_recovery.') THEN 'taken_back'
                WHEN e.net < 0 THEN 'taken_back' ELSE 'returned' END AS type,
                h.category, h.rate_percentage AS percent, o.id::text AS order_id, o.number AS order_number, buyer.name AS from_name, CASE WHEN {$payout} THEN substr(e.event_key, 15) END AS withdrawal_id, NULL AS waiting_until, e.net AS amount");
    }

    /**
     * Commission taken back after a refund, one row per commission line. A reversal row keeps
     * adding money recovered later, but that later money is its own record, so each row counts only
     * what this refund itself took, up to the total the refund took from this partner.
     */
    private function takenBackRows(int|Builder $people): Builder
    {
        $accounts = self::Accounts;
        $reversals = $this->forPeople(DB::table('commission_reversals as cr'), 'cr.user_id', $people)
            ->selectRaw('cr.id, cr.user_id, cr.refund_id, cr.allocation_id, cr.amount, COALESCE(SUM(cr.amount) OVER (PARTITION BY cr.refund_id, cr.user_id ORDER BY cr.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS earlier');
        $taken = $this->forPeople(DB::table('journal_lines as l'), 'l.user_id', $people)->whereRaw("l.account IN {$accounts}")->groupBy('l.entry_id', 'l.user_id')->selectRaw('l.entry_id, l.user_id, SUM(l.debit - l.credit) AS total');
        $amount = 'LEAST(r.amount, GREATEST(t.total - r.earlier, 0))';

        return DB::query()->fromSub($reversals, 'r')
            ->join('commission_allocations as a', 'a.id', '=', 'r.allocation_id')
            ->join('journal_entries as j', 'j.event_key', '=', DB::raw("'commissions.reversal.' || r.refund_id::text"))
            ->joinSub($taken, 't', fn ($join) => $join->on('t.entry_id', '=', 'j.id')->on('t.user_id', '=', 'r.user_id'))
            ->leftJoin('orders as o', 'o.id', '=', 'a.order_id')->leftJoin('users as buyer', 'buyer.id', '=', 'o.user_id')
            ->whereRaw("{$amount} > 0")
            ->selectRaw("('r-' || r.id::text) AS id, j.created_at AS occurred_at, ".self::typeOfCategory('a.category')." AS type, a.category, a.rate_percentage AS percent, o.id::text AS order_id, o.number AS order_number, buyer.name AS from_name, NULL AS withdrawal_id, NULL AS waiting_until, -{$amount} AS amount");
    }

    /** SQL naming the statement type of a commission category. */
    private static function typeOfCategory(string $column): string
    {
        return "CASE WHEN {$column} = 'own_dealer_cashback' THEN 'own' WHEN {$column} = 'direct_referral' THEN 'referral' WHEN {$column} IN ('d2d_gen2', 'd2d_gen3') THEN 'team' WHEN starts_with({$column}, 'area_') THEN 'area' WHEN {$column} IN ('dealer_monthly_salary_pool', 'sub_dealer_salary') THEN 'salary' WHEN {$column} = 'rank_royalty_pool' THEN 'royalty' WHEN {$column} = 'rank_incentive_pool' THEN 'rank_bonus' ELSE 'reward' END";
    }

    private static function typeWord(string $type): string
    {
        return (string) __('my_money.type_'.$type);
    }

    private function rowWord(object $row): string
    {
        return $row->category !== null ? FundCatalog::typeLabel((string) $row->category) : self::typeWord((string) $row->type);
    }
}
