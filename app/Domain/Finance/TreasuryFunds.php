<?php

namespace App\Domain\Finance;

use App\Models\Prescription;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Treasury's view of every company fund: how much came in, how much went out, how much is there
 * now, and the rows behind each number. {@see CompanyFunds::balances()} keeps deciding what can be
 * spent; this reads it and adds the month pools.
 *
 * @phpstan-type FundCard array{key: string, name: string, money_in: string, money_out: string, balance: string, spendable: bool, owner_only: bool, href: string}
 */
final class TreasuryFunds
{
    public function __construct(private CompanyFunds $funds) {}

    /** @return list<FundCard> */
    public function cards(bool $showOwnerFunds): array
    {
        $figures = [...$this->funds->balances(), ...$this->monthRowTotals()];
        $cards = [];
        foreach (FundCatalog::visibleKeys($showOwnerFunds) as $key) {
            if ($this->isShown($key, $figures[$key])) {
                $cards[] = $this->cardOf($key, $figures[$key]);
            }
        }

        return $cards;
    }

    /** @return FundCard */
    public function card(string $fund, bool $showOwnerFunds): array
    {
        abort_unless(in_array($fund, FundCatalog::visibleKeys($showOwnerFunds), true), 404);
        foreach ($this->cards($showOwnerFunds) as $card) {
            if ($card['key'] === $fund) {
                return $card;
            }
        }
        abort(404);
    }

    /** @return array{available: string, waiting: string, asked: string, total: string} */
    public function partnerTotals(): array
    {
        $row = DB::table('wallets')->selectRaw('COALESCE(SUM(available), 0) AS available, COALESCE(SUM(commission_held), 0) AS waiting, COALESCE(SUM(held - commission_held), 0) AS asked, COALESCE(SUM(available + held), 0) AS total')->first();

        return ['available' => (string) $row->available, 'waiting' => (string) $row->waiting, 'asked' => (string) $row->asked, 'total' => (string) $row->total];
    }

    /**
     * One line for every time money came into this fund. With no date view chosen the rows add up
     * to the card.
     *
     * @return array<string, mixed> MoneyTable payload
     */
    public function moneyIn(Request $request, string $fund): array
    {
        $table = MoneyTable::make($request, DB::query()->fromSub($this->inRows($fund), 'x'), new MoneyTableSpec(
            dateColumn: 'x.created_at',
            search: ['x.order_number', 'x.partner_name', 'x.partner_code'],
            filters: $this->typeFilter($fund),
            sorts: ['date' => 'x.created_at', 'amount' => 'x.amount', 'order' => 'x.order_number', 'partner' => 'x.partner_name', 'mrp_total' => 'x.mrp_total', 'paid' => 'x.paid'],
            defaultSort: 'date',
            tieBreaker: 'x.id',
            sums: ['amount' => 'x.amount'],
        ));
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => $row->id,
            'created_at' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->toIso8601String(),
            'order_id' => $row->order_id,
            'order_number' => $row->order_number,
            'partner_name' => $row->partner_name,
            'partner_code' => $row->partner_code,
            'partner_role' => $row->partner_role,
            'amount' => (string) $row->amount,
            'type_label' => $this->inTypeLabel($row),
            'percent' => $row->percent === null ? null : (string) $row->percent,
            'percent_of' => $row->percent_of,
            'mrp_total' => $row->mrp_total === null ? null : (string) $row->mrp_total,
            'paid' => $row->paid === null ? null : (string) $row->paid,
            'reason' => $row->reason_key === ReserveService::AreaTargetMissedReason ? (string) __('portal.'.$row->reason_key) : $row->reason,
        ]);

        return $table;
    }

    /**
     * One line for every time money left this fund. A closed month shows what partners were paid
     * and what moved to company profit, which add up to everything that row held.
     *
     * @return array<string, mixed> MoneyTable payload
     */
    public function moneyOut(Request $request, string $fund): array
    {
        $table = MoneyTable::make($request, DB::query()->fromSub($this->outRows($fund), 'x'), new MoneyTableSpec(
            dateColumn: 'x.date',
            search: ['x.what_text', 'x.payee', 'x.reference'],
            filters: [new MoneyTableFilter('what', (string) __('treasury.filter_what'), 'x.line_kind', array_map(
                fn (string $kind): array => ['value' => $kind, 'label' => (string) __('treasury.out_kind_'.$kind)],
                ['spend', 'partners', 'moved'],
            ))],
            sorts: ['date' => 'x.date', 'amount' => 'x.amount'],
            defaultSort: 'date',
            tieBreaker: 'x.id',
            sums: ['amount' => 'x.amount'],
        ));
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => $row->id,
            'date' => CarbonImmutable::parse((string) $row->date, 'UTC')->toIso8601String(),
            'what' => $this->outWhat($row),
            'to' => $this->outTo($row),
            'amount' => (string) $row->amount,
            'reference' => $row->reference,
            'receipt_url' => $row->receipt_id !== null ? '/commands/operations/receipts/reserve/'.$row->receipt_id : null,
        ]);

        return $table;
    }

    /**
     * Money in and out of the funds that close each month. Money in counts a row whatever its
     * status except reversed. A closed row has lost everything it held, so in, out and now always
     * add up and a closed row is exactly zero.
     *
     * @return array<string, array{money_in: int, money_out: int, pending: int, balance: int, available: int}>
     */
    private function monthRowTotals(): array
    {
        $keys = [...FundCatalog::MonthPools, 'rank_reward'];
        $classified = $this->liveRows()->selectRaw(FundCatalog::rowFundSql().' AS fund, funds.status, funds.amount, COALESCE(paid.total, 0) + COALESCE(spent.total, 0) AS used');
        $closedFunds = "'".implode("','", FundCatalog::ClosedRowFunds)."'";
        $rows = DB::query()->fromSub($classified, 'classified')->whereIn('fund', $keys)->groupBy('fund')
            ->selectRaw("fund, SUM(amount) AS money_in, SUM(CASE WHEN status = 'settled' AND fund IN ({$closedFunds}) THEN amount ELSE used END) AS money_out")
            ->get()->keyBy('fund');

        $totals = [];
        foreach ($keys as $key) {
            $moneyIn = FundCatalog::centsOf($rows->get($key)?->money_in);
            $moneyOut = FundCatalog::centsOf($rows->get($key)?->money_out);
            $totals[$key] = ['money_in' => $moneyIn, 'money_out' => $moneyOut, 'pending' => 0, 'balance' => $moneyIn - $moneyOut, 'available' => $moneyIn - $moneyOut];
        }

        return $totals;
    }

    /** Commission rows that count for a fund, with what has been paid and spent from each. */
    private function liveRows(): Builder
    {
        return DB::table('commission_allocations as funds')
            ->leftJoinSub($this->funds->settlementPayouts(), 'paid', 'paid.allocation_id', '=', 'funds.id')
            ->leftJoinSub($this->funds->expenseSources(), 'spent', 'spent.allocation_id', '=', 'funds.id')
            ->where('funds.status', '!=', 'reversed')
            ->where('funds.amount', '>', 0);
    }

    /** @param array{money_in: int, money_out: int, pending: int, balance: int, available: int} $figures */
    private function isShown(string $key, array $figures): bool
    {
        return ! in_array($key, FundCatalog::HiddenWhileEmpty, true) || $figures['money_in'] !== 0;
    }

    /**
     * @param  array{money_in: int, money_out: int, pending: int, balance: int, available: int}  $figures
     * @return FundCard
     */
    private function cardOf(string $key, array $figures): array
    {
        return [
            'key' => $key,
            'name' => FundCatalog::name($key),
            'money_in' => FundCatalog::decimalOf($figures['money_in']),
            'money_out' => FundCatalog::decimalOf($figures['money_out']),
            'balance' => FundCatalog::decimalOf($figures['balance']),
            'spendable' => CompanyFunds::isSpendable($key),
            'owner_only' => in_array($key, CompanyFunds::OwnerOnly, true),
            'href' => '/treasury/funds/'.$key,
        ];
    }

    /** @return list<MoneyTableFilter> */
    private function typeFilter(string $fund): array
    {
        if (in_array($fund, CompanyFunds::LedgerFunds, true)) {
            return [];
        }
        $categories = DB::table('commission_allocations as funds')->whereRaw('('.FundCatalog::rowFundSql().') = ?', [$fund])
            ->where('funds.status', '!=', 'reversed')->where('funds.amount', '>', 0)->distinct()->orderBy('funds.category')->pluck('funds.category');

        return [new MoneyTableFilter('type', (string) __('treasury.filter_type'), 'x.category', $categories
            ->map(fn (string $category): array => ['value' => $category, 'label' => FundCatalog::typeLabel($category)])->values()->all())];
    }

    private function inTypeLabel(object $row): string
    {
        if ($row->category === 'company_share') {
            return (string) __('treasury.fund_company_share');
        }
        if ($row->category === 'owners_share') {
            return (string) __('treasury.fund_benefits');
        }
        if ($row->category === 'clinic_sale') {
            return (string) __('treasury.type_clinic_sale');
        }

        return FundCatalog::typeLabel((string) $row->category);
    }

    private function inRows(string $fund): Builder
    {
        if (in_array($fund, ['company_share', 'benefits'], true)) {
            $rows = $this->batchRows($fund);

            return $fund === 'company_share' ? $rows->unionAll($this->clinicRows('p.manufacturer_share', 'p.manufacturer_share > 0')) : $rows;
        }
        if ($fund === 'clinic') {
            return $this->clinicRows('p.clinic_fund', 'p.clinic_fund > 0');
        }

        return DB::table('commission_allocations as funds')
            ->leftJoin('orders as o', 'o.id', '=', 'funds.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('commission_batches as b', 'b.order_id', '=', 'funds.order_id')
            ->where('funds.status', '!=', 'reversed')->where('funds.amount', '>', 0)
            ->whereRaw('('.FundCatalog::rowFundSql().') = ?', [$fund])
            ->selectRaw("funds.id::text AS id, funds.created_at, o.id AS order_id, o.number AS order_number, u.name AS partner_name, u.dealer_code AS partner_code, u.role AS partner_role, funds.amount, funds.category, funds.rate_percentage AS percent, 'paid' AS percent_of, b.mrp_basis AS mrp_total, b.cash_basis AS paid, CASE WHEN funds.category = '".CompanyFunds::NoCommissionCategory."' THEN funds.metadata->>'reason' END AS reason, funds.metadata->>'reason_key' AS reason_key");
    }

    /** The company share and the owners' share of each order, scaled down by what was refunded. */
    private function batchRows(string $fund): Builder
    {
        $company = $fund === 'company_share';
        $column = $company ? 'b.company_share' : 'b.unallocated';
        $percent = $company ? "(b.policy->>'company_mrp_percent')::numeric" : 'ROUND(b.unallocated * 100 / NULLIF(b.cash_basis, 0), 2)';

        return DB::table('commission_batches as b')->join('orders as o', 'o.id', '=', 'b.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where($column, '>', 0)->whereColumn('b.refunded_amount', '<', 'b.cash_basis')
            ->selectRaw("('b-' || b.id::text) AS id, b.created_at, o.id AS order_id, o.number AS order_number, u.name AS partner_name, u.dealer_code AS partner_code, u.role AS partner_role, ROUND({$column} * (b.cash_basis - b.refunded_amount) / NULLIF(b.cash_basis, 0), 2) AS amount, '".($company ? 'company_share' : 'owners_share')."' AS category, {$percent} AS percent, '".($company ? 'mrp' : 'paid')."' AS percent_of, b.mrp_basis AS mrp_total, b.cash_basis AS paid, NULL AS reason, NULL AS reason_key");
    }

    private function clinicRows(string $amount, string $condition): Builder
    {
        return DB::table('prescriptions as p')->where('p.kind', Prescription::KindClinic)->where('p.status', Prescription::StatusDispensed)->whereRaw($condition)
            ->selectRaw("('p-' || p.id::text) AS id, p.dispensed_at AS created_at, NULL::uuid AS order_id, p.number AS order_number, NULL AS partner_name, NULL AS partner_code, NULL AS partner_role, {$amount} AS amount, 'clinic_sale' AS category, NULL::numeric AS percent, NULL AS percent_of, NULL::numeric AS mrp_total, NULL::numeric AS paid, NULL AS reason, NULL AS reason_key");
    }

    private function outRows(string $fund): Builder
    {
        if (in_array($fund, CompanyFunds::LedgerFunds, true)) {
            $paid = $this->ledgerSpends($fund);

            return $fund === 'clinic' ? $paid->unionAll($this->clinicBelowCost()) : $paid;
        }
        $query = $this->spendLines($fund)->unionAll($this->payoutLines($fund));
        if (in_array($fund, FundCatalog::ClosedRowFunds, true)) {
            $query->unionAll($this->closedMonthLines($fund, true))->unionAll($this->closedMonthLines($fund, false));
        }

        return $query;
    }

    private function ledgerSpends(string $fund): Builder
    {
        return DB::table('reserve_disbursements as r')->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->where('r.program', FundSpending::Program)->where('r.status', 'paid')->where('r.fund_key', $fund)
            ->selectRaw("('s-' || r.id::text) AS id, COALESCE(r.spent_on::timestamp, r.paid_at, r.created_at) AS date, 'spend' AS line_kind, COALESCE(h.name_bn, h.name_en, r.program) AS what_text, h.name_en AS what_en, r.payee_name AS payee, NULL::bigint AS partners, r.amount, r.payment_reference AS reference, CASE WHEN r.receipt_path IS NOT NULL THEN r.id END AS receipt_id, NULL AS kind");
    }

    private function clinicBelowCost(): Builder
    {
        return DB::table('prescriptions as p')->where('p.kind', Prescription::KindClinic)->where('p.status', Prescription::StatusDispensed)->where('p.clinic_fund', '<', 0)
            ->selectRaw("('p-' || p.id::text) AS id, p.dispensed_at AS date, 'spend' AS line_kind, 'clinic_below_cost' AS what_text, NULL AS what_en, NULL AS payee, NULL::bigint AS partners, -p.clinic_fund AS amount, p.number AS reference, NULL::bigint AS receipt_id, 'clinic_below_cost' AS kind");
    }

    /** Spends drawn on this fund's rows, net of what was taken back. */
    private function spendLines(string $fund): Builder
    {
        return $this->openRowsOf($fund, DB::table('reserve_expense_sources as s')
            ->join('reserve_disbursements as r', 'r.id', '=', 's.disbursement_id')->join('commission_allocations as funds', 'funds.id', '=', 's.allocation_id')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')->leftJoin('users as partner', 'partner.id', '=', 'r.user_id')
            ->whereIn('r.status', ['approved', 'paid']))
            ->groupBy('r.id', 'h.name_bn', 'h.name_en', 'partner.name')
            ->havingRaw('SUM(s.amount - s.reversed_amount) <> 0')
            ->selectRaw("('s-' || r.id::text) AS id, COALESCE(r.spent_on::timestamp, r.paid_at, r.created_at) AS date, 'spend' AS line_kind, COALESCE(h.name_bn, h.name_en, r.program) AS what_text, h.name_en AS what_en, COALESCE(r.payee_name, partner.name) AS payee, NULL::bigint AS partners, SUM(s.amount - s.reversed_amount) AS amount, r.payment_reference AS reference, CASE WHEN r.receipt_path IS NOT NULL THEN r.id END AS receipt_id, r.program AS kind");
    }

    /** Payouts to partners from rows still open, one line per kind and month. */
    private function payoutLines(string $fund): Builder
    {
        return $this->openRowsOf($fund, DB::table('commission_settlement_payouts as p')
            ->join('commission_allocations as funds', 'funds.id', '=', 'p.allocation_id')->join('users as u', 'u.id', '=', 'p.user_id'))
            ->groupBy('p.kind', 'p.period_month')
            ->havingRaw('SUM(p.amount - p.reversed_amount) <> 0')
            ->selectRaw("('p-' || p.kind || '-' || p.period_month) AS id, MAX(p.created_at) AS date, 'partners' AS line_kind, MIN(p.kind) AS what_text, NULL AS what_en, CASE WHEN COUNT(DISTINCT p.user_id) = 1 THEN MIN(u.name) END AS payee, COUNT(DISTINCT p.user_id) AS partners, SUM(p.amount - p.reversed_amount) AS amount, NULL AS reference, NULL::bigint AS receipt_id, p.kind AS kind");
    }

    /**
     * A closed month's two lines: what partners were paid and what moved to company profit. The
     * second is the row's whole amount less the first, so the two add up by construction.
     */
    private function closedMonthLines(string $fund, bool $paidToPartners): Builder
    {
        $paidOut = 'COALESCE(paid.total, 0) + COALESCE(spent.total, 0)';
        $amount = $paidToPartners ? "SUM({$paidOut})" : "SUM(funds.amount - ({$paidOut}))";
        $period = "COALESCE(funds.metadata->>'settled_period', funds.metadata->>'period_month')";
        $lineKind = $paidToPartners ? 'partners' : 'moved';

        return $this->liveRows()->where('funds.status', 'settled')->whereRaw('('.FundCatalog::rowFundSql().') = ?', [$fund])
            ->groupByRaw($period)->havingRaw("{$amount} <> 0")
            ->selectRaw("('c-{$lineKind}-' || {$period}) AS id, MAX((funds.metadata->>'settled_at')::timestamptz AT TIME ZONE 'UTC') AS date, '{$lineKind}' AS line_kind, {$period} AS what_text, NULL AS what_en, NULL AS payee, NULL::bigint AS partners, {$amount} AS amount, NULL AS reference, NULL::bigint AS receipt_id, 'closed_month' AS kind");
    }

    /** Keeps the rows of this fund a closed month has not already counted in full. */
    private function openRowsOf(string $fund, Builder $query): Builder
    {
        $query->where('funds.status', '!=', 'reversed')->where('funds.amount', '>', 0)->whereRaw('('.FundCatalog::rowFundSql().') = ?', [$fund]);
        if (in_array($fund, FundCatalog::ClosedRowFunds, true)) {
            $query->where('funds.status', '!=', 'settled');
        }

        return $query;
    }

    private function outWhat(object $row): string
    {
        if ($row->line_kind === 'moved') {
            return (string) __('treasury.out_moved_to_profit');
        }
        if ($row->kind === 'closed_month') {
            return (string) __('treasury.out_month_paid', ['month' => (string) $row->what_text]);
        }
        if ($row->kind === 'clinic_below_cost') {
            return (string) __('treasury.out_clinic_below_cost');
        }
        if ($row->line_kind === 'partners') {
            return $this->payoutWord((string) $row->kind);
        }
        $name = app()->getLocale() === 'en' && $row->what_en ? (string) $row->what_en : (string) $row->what_text;

        return $name === '' ? (string) __('treasury.out_kind_spend') : $name;
    }

    private function payoutWord(string $kind): string
    {
        if (str_starts_with($kind, 'award_')) {
            return (string) __('treasury.out_reward');
        }
        if (str_starts_with($kind, 'rank_')) {
            return (string) __('treasury.type_rank_incentive_pool');
        }
        $key = 'treasury.out_payout_'.$kind;
        $word = (string) __($key);

        return $word === $key ? (string) __('treasury.out_kind_partners') : $word;
    }

    private function outTo(object $row): ?string
    {
        if ($row->payee !== null) {
            return (string) $row->payee;
        }
        if ($row->partners !== null && (int) $row->partners > 0) {
            return (string) __('treasury.to_partners', ['count' => (string) $row->partners]);
        }

        return $row->line_kind === 'moved' ? (string) __('treasury.fund_company_profit') : null;
    }

    /** What can still be spent, for the spend form. */
    public function spendable(string $fund): string
    {
        $balances = $this->funds->balances();

        return Money::decimal(max(0, $balances[$fund]['available'] ?? 0));
    }
}
