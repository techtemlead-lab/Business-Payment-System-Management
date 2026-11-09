<?php

namespace App\Domain\Finance;

use App\Domain\Payouts\PayoutReceipts;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything waiting for a person, in one list: tiles for each kind of money work, spends to
 * approve, expense heads and their budgets. Each list is its own view so it can keep its own
 * search, filters, date view and sort in the address.
 */
final class TreasuryWork
{
    public const Tabs = ['waiting', 'heads', 'budgets'];

    public function __construct(private FundBudgets $budgets) {}

    /**
     * @return array{tab: string, tiles: list<array{key: string, label: string, href: string, count: int}>, approvals_table: array<string, mixed>|null, heads_table: array<string, mixed>|null, budgets_table: array<string, mixed>|null, heads: mixed, budget_month: string, methods: mixed}
     */
    public function for(User $viewer, string $portal, Request $request): array
    {
        $visible = CompanyFunds::visibleKeys(TreasuryAccess::showsOwnerFunds($viewer, $portal));
        $tab = $request->query('tab');
        $tab = is_string($tab) && in_array($tab, self::Tabs, true) ? $tab : 'waiting';
        $heads = DB::table('fund_expense_heads')->orderByDesc('is_active')->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'is_active', 'for_tours']);

        return [
            'tab' => $tab,
            'tiles' => $tab === 'waiting' ? $this->tiles($viewer) : [],
            'approvals_table' => $tab === 'waiting' ? $this->approvalsTable($request, $visible, $viewer, $heads) : null,
            'heads_table' => $tab === 'heads' ? $this->headsTable($request) : null,
            'budgets_table' => $tab === 'budgets' ? $this->budgetsTable($request, $heads) : null,
            'heads' => $heads,
            'budget_month' => now()->timezone('Asia/Dhaka')->format('Y-m'),
            'methods' => PayoutReceipts::Methods,
        ];
    }

    /** How many things are waiting, for the dot on the Money tasks tab. */
    public function waitingCount(User $viewer, string $portal): int
    {
        return array_sum(array_column($this->tiles($viewer), 'count')) + $this->spendsWaiting(CompanyFunds::visibleKeys(TreasuryAccess::showsOwnerFunds($viewer, $portal)));
    }

    /** @param list<string> $visible */
    private function spendsWaiting(array $visible): int
    {
        return (int) DB::table('reserve_disbursements')->where('program', FundSpending::Program)->where('status', 'requested')->whereIn('fund_key', $visible)->count();
    }

    /**
     * @return list<array{key: string, label: string, href: string, count: int}>
     */
    private function tiles(User $viewer): array
    {
        $finance = $viewer->owner() || $viewer->allows('finance');
        $tiles = [];
        if ($finance) {
            $tiles[] = $this->tile('payments', '/payments', (int) DB::table('payment_claims')->where('status', 'pending')->count());
            $tiles[] = $this->tile('payouts', '/payouts-hub', (int) DB::table('withdrawals')->where('status', 'requested')->count());
            $tiles[] = $this->tile('settlements', '/settlements', 0);
            $tiles[] = $this->tile('holds', '/operations/commission-holds', (int) DB::table('commission_allocations')->where('status', 'held')->count());
            $tiles[] = $this->tile('rewards', '/operations/reserve-payouts', (int) DB::table('reserve_disbursements')->where('status', 'requested')->where('program', '!=', FundSpending::Program)->count());
            $tiles[] = $this->tile('bank', '/operations/accounting', 0);
            $tiles[] = $this->tile('opening', '/operations/financial-readiness', 0);
        }
        if ($viewer->owner() || $viewer->allows('payroll')) {
            $tiles[] = $this->tile('payroll', '/operations/payroll', 0);
        }

        return $tiles;
    }

    /** @return array{key: string, label: string, href: string, count: int} */
    private function tile(string $key, string $href, int $count): array
    {
        return ['key' => $key, 'label' => (string) __('treasury.tile_'.$key), 'href' => $href, 'count' => $count];
    }

    /**
     * Spends waiting for approval, in the same row shape as the old approvals list.
     *
     * @param  list<string>  $visible
     * @param  Collection<int, object>  $heads
     * @return array<string, mixed>
     */
    private function approvalsTable(Request $request, array $visible, User $actor, Collection $heads): array
    {
        $overBudget = $this->budgets->overBudgetKeys();
        $query = DB::table('reserve_disbursements as r')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->leftJoin('users as maker', 'maker.id', '=', 'r.created_by')
            ->where('r.program', FundSpending::Program)->where('r.status', 'requested')->whereIn('r.fund_key', $visible)
            ->select(['r.id', 'r.fund_key', 'r.amount', 'r.spent_on', 'r.expense_head_id', 'r.payee_name', 'r.payment_method', 'r.payment_reference', 'r.reason', 'r.created_by', 'h.name_en', 'h.name_bn', 'maker.name as maker_name', DB::raw('r.receipt_path IS NOT NULL AS has_receipt')]);

        $table = MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: 'r.spent_on',
            search: ['r.payee_name', 'r.reason', 'r.payment_reference', 'h.name_en', 'h.name_bn', 'maker.name'],
            filters: [
                new MoneyTableFilter('fund', (string) __('funds.col_fund'), 'r.fund_key', array_map(fn (string $key): array => ['value' => $key, 'label' => (string) __('treasury.fund_'.$key)], $visible)),
                $this->headFilter('r.expense_head_id', $heads),
            ],
            sorts: ['date' => 'r.spent_on', 'fund' => 'r.fund_key', 'payee' => 'r.payee_name', 'amount' => 'r.amount', 'maker' => 'maker.name'],
            defaultSort: 'date',
            tieBreaker: 'r.id',
            defaultDir: 'asc',
            sums: ['amount' => 'r.amount'],
            defaultPeriod: null,
        ));
        $table['rows']->through(fn (object $row): array => [...(array) $row, 'can_review' => $row->created_by !== $actor->id, 'can_cancel' => $row->created_by === $actor->id,
            'over_budget' => ! in_array($row->fund_key, CompanyFunds::OwnerOnly, true) && in_array($row->expense_head_id.'|'.substr((string) $row->spent_on, 0, 7), $overBudget, true),
            'can_self_approve' => $row->created_by === $actor->id && app(SelfApproval::class)->isAvailable($actor, [$row->created_by], canDoStep: FundSpending::approverOf((string) $row->fund_key))]);

        return $table;
    }

    /** @return array<string, mixed> */
    private function headsTable(Request $request): array
    {
        $yesNo = fn (string $yes, string $no): array => [['value' => '1', 'label' => $yes], ['value' => '0', 'label' => $no]];

        return MoneyTable::make($request, DB::table('fund_expense_heads as h')->select(['h.id', 'h.name_en', 'h.name_bn', 'h.is_active', 'h.for_tours', 'h.created_at']), new MoneyTableSpec(
            dateColumn: 'h.created_at',
            search: ['h.name_en', 'h.name_bn'],
            filters: [
                new MoneyTableFilter('active', (string) __('funds.col_status'), '(CASE WHEN h.is_active THEN \'1\' ELSE \'0\' END)', $yesNo((string) __('funds.heads_active'), (string) __('funds.heads_inactive'))),
                new MoneyTableFilter('tours', (string) __('funds.for_tours'), '(CASE WHEN h.for_tours THEN \'1\' ELSE \'0\' END)', $yesNo((string) __('portal.yes'), (string) __('portal.no'))),
            ],
            sorts: ['name_bn' => 'h.name_bn', 'name_en' => 'h.name_en', 'active' => 'h.is_active'],
            defaultSort: 'name_en',
            tieBreaker: 'h.id',
            defaultDir: 'asc',
            defaultPeriod: null,
        ));
    }

    /**
     * Budgets with what has been used, one row per head and month. Waiting and paid spends count,
     * and the owners' own fund is left out, as in {@see FundBudgets::forMonth}.
     *
     * @param  Collection<int, object>  $heads
     * @return array<string, mixed>
     */
    private function budgetsTable(Request $request, Collection $heads): array
    {
        $used = DB::table('reserve_disbursements')
            ->where('program', FundSpending::Program)
            ->whereIn('status', ['requested', 'paid'])
            ->whereNotIn('fund_key', CompanyFunds::OwnerOnly)
            ->whereNotNull('spent_on')
            ->groupByRaw("expense_head_id, date_trunc('month', spent_on)::date")
            ->selectRaw("expense_head_id, date_trunc('month', spent_on)::date AS month, SUM(amount) AS total");
        $usedSql = 'COALESCE(used.total, 0)';
        $query = DB::table('fund_head_budgets as b')
            ->join('fund_expense_heads as h', 'h.id', '=', 'b.expense_head_id')
            ->leftJoinSub($used, 'used', fn ($join) => $join->on('used.expense_head_id', '=', 'b.expense_head_id')->on('used.month', '=', 'b.month'))
            ->selectRaw("b.expense_head_id AS head_id, h.name_en, h.name_bn, b.month, b.amount AS budget, {$usedSql} AS used, GREATEST(b.amount - {$usedSql}, 0) AS left_amount, ({$usedSql} > b.amount) AS over");

        return MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: 'b.month',
            search: ['h.name_en', 'h.name_bn'],
            filters: [
                $this->headFilter('b.expense_head_id', $heads),
                new MoneyTableFilter('state', (string) __('funds.col_status'), "(CASE WHEN {$usedSql} > b.amount THEN 'over' ELSE 'within' END)", [
                    ['value' => 'over', 'label' => (string) __('funds.budget_status_over')],
                    ['value' => 'within', 'label' => (string) __('funds.budget_status_ok')],
                ]),
            ],
            sorts: ['month' => 'b.month', 'name' => 'h.name_en', 'budget' => 'b.amount', 'used' => $usedSql, 'left' => "GREATEST(b.amount - {$usedSql}, 0)"],
            defaultSort: 'month',
            tieBreaker: 'b.expense_head_id',
            sums: ['budget' => 'b.amount', 'used' => $usedSql, 'left' => "GREATEST(b.amount - {$usedSql}, 0)"],
            defaultPeriod: 'month',
        ));
    }

    /** @param Collection<int, object> $heads */
    private function headFilter(string $column, Collection $heads): MoneyTableFilter
    {
        $bangla = app()->getLocale() === 'bn';

        return new MoneyTableFilter('head', (string) __('funds.budget_head'), 'CAST('.$column.' AS text)', $heads
            ->map(fn (object $head): array => ['value' => (string) $head->id, 'label' => (string) ($bangla ? $head->name_bn : $head->name_en)])
            ->values()->all());
    }
}
