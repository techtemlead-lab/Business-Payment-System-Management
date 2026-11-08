<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Geography\ManagementReferral;
use App\Models\Prescription;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Every company-side fund in one list: what came in, what went out and what is left.
 *
 * Funds fed by commission allocation rows draw down row by row, oldest first, through
 * reserve_expense_sources, so refunds and the older marketing flow see the same balance.
 * The company share, the clinic fund and the owners' benefits fund have no allocation rows
 * for spending to draw on, so their spends are counted directly.
 */
final class CompanyFunds
{
    /** Funds fed by commission allocation rows. */
    public const AllocationFunds = ['company_profit', 'management', 'marketing', 'tour', 'geographic_reserve', 'referral_reserve', 'car_bike', 'rank_reward', 'salary_reserve'];

    /** Funds worked out from order batches and clinic sales. */
    public const LedgerFunds = ['company_share', 'clinic', 'benefits'];

    /** Funds that only their own award or month-close flow may draw on. */
    public const AwardOnly = ['car_bike', 'rank_reward', 'salary_reserve'];

    /** Seen and spent by the owners only, on the owner panel. */
    public const OwnerOnly = ['benefits'];

    /** Older payout programs that drew on a fund before the generic spend flow. */
    public const LegacyPrograms = ['marketing' => ['marketing'], 'car_bike' => ['bike', 'car'], 'tour' => ['tour'], 'rank_reward' => ['additional_rank']];

    /** @return list<string> */
    public static function keys(): array
    {
        return [...self::AllocationFunds, ...self::LedgerFunds];
    }

    public static function isSpendable(string $fund): bool
    {
        return in_array($fund, self::keys(), true) && ! in_array($fund, self::AwardOnly, true);
    }

    /** @return list<string> */
    public static function visibleKeys(bool $showOwnerFunds): array
    {
        return $showOwnerFunds ? self::keys() : array_values(array_diff(self::keys(), self::OwnerOnly));
    }

    /** SQL naming the fund of a commission allocation row, in the same order RetainedFundLedger sorts rows. */
    public static function fundSql(): string
    {
        $companyProfit = CommissionSettlement::CompanyProfitCategory;
        $retainedProfit = CommissionPosting::CompanyProfit;
        $retainedManagement = ManagementReferral::FundRetainedBy;
        $rankReserve = CommissionSettlement::RankRoyaltyReserve;
        $dealerSalary = CommissionSettlement::DealerSalaryReserve;
        $subDealerSalary = CommissionSettlement::SubDealerSalaryReserve;

        return "CASE WHEN funds.category = '{$companyProfit}' OR funds.metadata->>'retained_by' = '{$retainedProfit}' THEN 'company_profit' "
            ."WHEN funds.category = 'area_management' OR funds.metadata->>'retained_by' = '{$retainedManagement}' THEN 'management' "
            ."WHEN funds.category LIKE '%\\_override\\_reserve' OR funds.category LIKE '%\\_breakage' THEN 'referral_reserve' "
            ."WHEN funds.status = 'unassigned_reserve' THEN 'geographic_reserve' "
            ."WHEN funds.category = 'company_marketing_fund' THEN 'marketing' "
            ."WHEN funds.category = 'car_bike_incentive_fund' THEN 'car_bike' "
            ."WHEN funds.category = 'sub_dealer_tour' THEN 'tour' "
            ."WHEN funds.category = 'additional_rank_pool' AND funds.status = 'pending' THEN 'rank_reward' "
            ."WHEN funds.category = '{$rankReserve}' THEN 'rank_reward' "
            ."WHEN funds.category IN ('{$dealerSalary}', '{$subDealerSalary}') THEN 'salary_reserve' END";
    }

    /**
     * Money in, money out and what is left for each fund, in cents. Pending spends are not out
     * yet, but they are not free to spend again either.
     *
     * @return array<string, array{money_in: int, money_out: int, pending: int, balance: int, available: int}>
     */
    public function balances(): array
    {
        $totals = array_fill_keys(self::keys(), ['money_in' => 0, 'money_out' => 0, 'pending' => 0]);
        $classified = DB::table('commission_allocations as funds')
            ->leftJoinSub($this->settlementPayouts(), 'paid', 'paid.allocation_id', '=', 'funds.id')
            ->leftJoinSub($this->expenseSources(), 'spent', 'spent.allocation_id', '=', 'funds.id')
            ->where('funds.status', '!=', 'reversed')
            ->where('funds.amount', '>', 0)
            ->selectRaw(self::fundSql().' AS fund, funds.amount, COALESCE(paid.total, 0) + COALESCE(spent.total, 0) AS used');
        foreach (DB::query()->fromSub($classified, 'classified')->whereNotNull('fund')->groupBy('fund')->selectRaw('fund, SUM(amount) AS money_in, SUM(used) AS money_out')->get() as $row) {
            $totals[$row->fund]['money_in'] = self::cents($row->money_in);
            $totals[$row->fund]['money_out'] = self::cents($row->money_out);
        }

        $batches = DB::table('commission_batches')->selectRaw(
            'COALESCE(ROUND(SUM(company_share * (cash_basis - refunded_amount) / NULLIF(cash_basis, 0)), 2), 0) AS company_share, '
            .'COALESCE(ROUND(SUM(unallocated * (cash_basis - refunded_amount) / NULLIF(cash_basis, 0)), 2), 0) AS benefits',
        )->first();
        $clinic = DB::table('prescriptions')->where('kind', Prescription::KindClinic)->where('status', Prescription::StatusDispensed)->selectRaw(
            'COALESCE(SUM(manufacturer_share), 0) AS manufacturer, COALESCE(SUM(GREATEST(clinic_fund, 0)), 0) AS clinic_in, COALESCE(SUM(GREATEST(-clinic_fund, 0)), 0) AS clinic_below_cost',
        )->first();
        $totals['company_share']['money_in'] = self::cents($batches->company_share) + self::cents($clinic->manufacturer);
        $totals['benefits']['money_in'] = self::cents($batches->benefits);
        $totals['clinic']['money_in'] = self::cents($clinic->clinic_in);
        $totals['clinic']['money_out'] = self::cents($clinic->clinic_below_cost);

        $spends = DB::table('reserve_disbursements')->where('program', FundSpending::Program)->whereIn('status', ['requested', 'paid'])
            ->groupBy('fund_key', 'status')->selectRaw('fund_key, status, SUM(amount) AS total')->get();
        foreach ($spends as $spend) {
            if (! isset($totals[$spend->fund_key])) {
                continue;
            }
            if ($spend->status === 'requested') {
                $totals[$spend->fund_key]['pending'] += self::cents($spend->total);
            } elseif (in_array($spend->fund_key, self::LedgerFunds, true)) {
                $totals[$spend->fund_key]['money_out'] += self::cents($spend->total);
            }
        }

        return array_map(function (array $fund): array {
            $balance = $fund['money_in'] - $fund['money_out'];

            return [...$fund, 'balance' => $balance, 'available' => $balance - $fund['pending']];
        }, $totals);
    }

    /**
     * Allocation rows of a fund that still hold money, oldest first.
     *
     * @return iterable<int, object{id: int, order_id: ?string, remaining: string}>
     */
    public function rowsWithMoney(string $fund): iterable
    {
        return DB::table('commission_allocations as funds')
            ->leftJoinSub($this->settlementPayouts(), 'paid', 'paid.allocation_id', '=', 'funds.id')
            ->leftJoinSub($this->expenseSources(), 'spent', 'spent.allocation_id', '=', 'funds.id')
            ->where('funds.status', '!=', 'reversed')
            ->where('funds.amount', '>', 0)
            ->whereRaw('('.self::fundSql().') = ?', [$fund])
            ->whereRaw('funds.amount - COALESCE(paid.total, 0) - COALESCE(spent.total, 0) > 0')
            ->orderBy('funds.id')
            ->selectRaw('funds.id, funds.order_id, funds.amount - COALESCE(paid.total, 0) - COALESCE(spent.total, 0) AS remaining')
            ->cursor();
    }

    /** Where a fund's money came from, newest first. */
    public function moneyIn(string $fund, int $perPage = 15): LengthAwarePaginator
    {
        if (in_array($fund, ['company_share', 'benefits'], true)) {
            $column = $fund === 'company_share' ? 'b.company_share' : 'b.unallocated';
            $query = DB::table('commission_batches as b')->join('orders as o', 'o.id', '=', 'b.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
                ->selectRaw("'order' AS kind, o.number AS reference, u.name AS from_name, NULL AS category, NULL AS status, b.created_at, ROUND({$column} * (b.cash_basis - b.refunded_amount) / NULLIF(b.cash_basis, 0), 2) AS amount");
            if ($fund === 'company_share') {
                $query->unionAll($this->clinicRows('manufacturer_share'));
            }

            return $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'in_page')->withQueryString();
        }
        if ($fund === 'clinic') {
            return $this->clinicRows('clinic_fund')->orderByDesc('created_at')->paginate($perPage, ['*'], 'in_page')->withQueryString();
        }

        return DB::table('commission_allocations as funds')->leftJoin('orders as o', 'o.id', '=', 'funds.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('funds.status', '!=', 'reversed')
            ->where('funds.amount', '>', 0)
            ->whereRaw('('.self::fundSql().') = ?', [$fund])
            ->orderByDesc('funds.id')
            ->selectRaw("'order' AS kind, o.number AS reference, u.name AS from_name, funds.category, funds.status, funds.created_at, funds.amount")
            ->paginate($perPage, ['*'], 'in_page')->withQueryString();
    }

    /** What was spent or paid from a fund, newest first, with head and payment details. */
    public function moneyOut(string $fund, int $perPage = 15): LengthAwarePaginator
    {
        return DB::table('reserve_disbursements as r')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->leftJoin('users as maker', 'maker.id', '=', 'r.created_by')
            ->leftJoin('users as checker', 'checker.id', '=', 'r.approved_by')
            ->leftJoin('users as partner', 'partner.id', '=', 'r.user_id')
            ->where(fn ($query) => $query->where('r.fund_key', $fund)->orWhereIn('r.program', self::LegacyPrograms[$fund] ?? []))
            ->whereNotIn('r.status', ['rejected', 'cancelled'])
            ->orderByDesc('r.id')
            ->select(['r.id', 'r.program', 'r.amount', 'r.status', 'r.payment_method', 'r.payment_reference', 'r.reason', 'h.name_en as head_en', 'h.name_bn as head_bn', 'maker.name as maker_name', 'checker.name as checker_name'])
            ->selectRaw('COALESCE(r.payee_name, partner.name) AS payee, COALESCE(r.spent_on, r.paid_at::date, r.created_at::date) AS spent_on, r.receipt_path IS NOT NULL AS has_receipt')
            ->paginate($perPage, ['*'], 'out_page')->withQueryString();
    }

    /**
     * Money that has left the funds in a period, by fund and by head, with the lines behind it.
     * Older marketing payouts are included and show with no head.
     *
     * @param  array{from: string, to: string, fund?: ?string, head?: ?int}  $filters
     * @return array{by_fund: list<array<string, mixed>>, by_head: list<array<string, mixed>>, total: string, count: int, lines: LengthAwarePaginator}
     */
    public function report(array $filters, bool $showOwnerFunds): array
    {
        $base = fn () => self::paidSpendRows($filters, $showOwnerFunds);
        $byFund = $base()->groupByRaw('COALESCE(r.fund_key, \'marketing\')')->selectRaw('COALESCE(r.fund_key, \'marketing\') AS fund, SUM(r.amount) AS total, COUNT(*) AS spends')->orderByDesc('total')->get();
        $byHead = $base()->groupBy('h.id', 'h.name_en', 'h.name_bn')->selectRaw('h.id AS head_id, h.name_en, h.name_bn, SUM(r.amount) AS total, COUNT(*) AS spends')->orderByDesc('total')->get();
        $total = $base()->selectRaw('COALESCE(SUM(r.amount), 0) AS total, COUNT(*) AS spends')->first();
        $lines = $base()->orderByRaw('COALESCE(r.spent_on, r.paid_at::date) DESC')->orderByDesc('r.id')
            ->select(['r.id', 'r.amount', 'r.payment_method', 'r.payment_reference', 'r.reason', 'h.name_en as head_en', 'h.name_bn as head_bn'])
            ->selectRaw("COALESCE(r.fund_key, 'marketing') AS fund, r.payee_name AS payee, COALESCE(r.spent_on, r.paid_at::date) AS spent_on, r.receipt_path IS NOT NULL AS has_receipt")
            ->paginate(25, ['*'], 'report_page')->withQueryString();

        return [
            'by_fund' => $byFund->map(fn ($row): array => ['fund' => $row->fund, 'total' => (string) $row->total, 'spends' => (int) $row->spends])->all(),
            'by_head' => $byHead->map(fn ($row): array => ['head_id' => $row->head_id, 'name_en' => $row->name_en, 'name_bn' => $row->name_bn, 'total' => (string) $row->total, 'spends' => (int) $row->spends])->all(),
            'total' => (string) $total->total,
            'count' => (int) $total->spends,
            'lines' => $lines,
        ];
    }

    /**
     * Paid spends in a period; shared by the on-screen report and its export so the two agree.
     *
     * @param  array{from: string, to: string, fund?: ?string, head?: ?int}  $filters
     */
    public static function paidSpendRows(array $filters, bool $showOwnerFunds): Builder
    {
        return DB::table('reserve_disbursements as r')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->where('r.status', 'paid')
            ->whereIn('r.program', [FundSpending::Program, 'marketing'])
            ->whereRaw('COALESCE(r.spent_on, r.paid_at::date) BETWEEN ?::date AND ?::date', [$filters['from'], $filters['to']])
            ->when(! empty($filters['fund']), fn ($query) => $query->whereRaw("COALESCE(r.fund_key, 'marketing') = ?", [$filters['fund']]))
            ->when(! empty($filters['head']), fn ($query) => $query->where('r.expense_head_id', $filters['head']))
            ->when(! $showOwnerFunds, fn ($query) => $query->whereRaw("COALESCE(r.fund_key, '') NOT IN ('".implode("','", self::OwnerOnly)."')"));
    }

    /** @return array{from: string, to: string} This calendar month in Bangladesh time. */
    public static function defaultPeriod(): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka');

        return ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()];
    }

    private function clinicRows(string $column): Builder
    {
        return DB::table('prescriptions as p')
            ->where('p.kind', Prescription::KindClinic)->where('p.status', Prescription::StatusDispensed)
            ->selectRaw("'clinic' AS kind, p.number AS reference, NULL AS from_name, NULL AS category, NULL AS status, p.dispensed_at AS created_at, p.{$column} AS amount");
    }

    private function settlementPayouts(): Builder
    {
        return DB::table('commission_settlement_payouts')->groupBy('allocation_id')
            ->selectRaw('allocation_id, SUM(amount - reversed_amount) AS total');
    }

    private function expenseSources(): Builder
    {
        return DB::table('reserve_expense_sources')->join('reserve_disbursements', 'disbursement_id', '=', 'reserve_disbursements.id')
            ->whereIn('reserve_disbursements.status', ['approved', 'paid'])->groupBy('reserve_expense_sources.allocation_id')
            ->selectRaw('reserve_expense_sources.allocation_id, SUM(reserve_expense_sources.amount - reserve_expense_sources.reversed_amount) AS total');
    }

    /** Whole-table totals can carry a sign, which Money::cents does not accept. */
    private static function cents(mixed $value): int
    {
        $text = (string) $value;

        return str_starts_with($text, '-') ? -Money::cents(substr($text, 1)) : Money::cents($text);
    }

    /**
     * Where a money-in row came from, in the reader's language. Area shares with no dealer are
     * company profit since the owner's rule; rows from before that rule stay readable as old reserve.
     */
    public static function sourceLabel(string $fund, ?string $category, ?string $status): ?string
    {
        if ($category === null) {
            return null;
        }
        if ($fund === 'company_profit' && $status === 'unassigned_reserve') {
            return __('funds.source_no_dealer_profit');
        }
        if ($fund === 'geographic_reserve') {
            return __('funds.source_old_area');
        }
        $key = 'portal.report_cat_'.$category;
        $label = __($key);

        return $label === $key ? $category : $label;
    }
}
