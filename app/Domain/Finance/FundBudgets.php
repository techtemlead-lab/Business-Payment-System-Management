<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Optional monthly budgets on expense heads. A budget only ever warns: nothing here can stop,
 * hold or change a spend. Waiting and paid spends both count, so a warning appears when the
 * spend is recorded and not only after the money has gone. Spends from the owners' own fund
 * are left out because finance staff use this page too.
 */
final class FundBudgets
{
    /**
     * Sets or clears the budget for one head and month. A blank amount removes it.
     */
    public function save(User $actor, int $headId, string $month, ?string $amount): void
    {
        abort_unless(FundSpending::canManage($actor), 403);
        $firstDay = $month.'-01';
        DB::transaction(function () use ($actor, $headId, $firstDay, $month, $amount): void {
            $before = DB::table('fund_head_budgets')->where('expense_head_id', $headId)->where('month', $firstDay)->lockForUpdate()->first();
            if ($amount === null || trim($amount) === '') {
                DB::table('fund_head_budgets')->where('expense_head_id', $headId)->where('month', $firstDay)->delete();
            } else {
                DB::table('fund_head_budgets')->updateOrInsert(
                    ['expense_head_id' => $headId, 'month' => $firstDay],
                    ['amount' => $amount, 'updated_by' => $actor->id, 'created_at' => $before->created_at ?? now(), 'updated_at' => now()],
                );
            }
            Audit::record($actor->id, 'fund.budget_saved', $headId.':'.$month, ['month' => $month, 'before' => $before->amount ?? null, 'after' => $amount]);
        });
    }

    /**
     * The warning for one spend, or null when its head and month are within budget or have none.
     */
    public function warningFor(int $spendId): ?string
    {
        $spend = DB::table('reserve_disbursements')->where('id', $spendId)->where('program', FundSpending::Program)->first();
        if ($spend === null || $spend->expense_head_id === null || $spend->spent_on === null || in_array($spend->fund_key, CompanyFunds::OwnerOnly, true)) {
            return null;
        }
        $month = substr((string) $spend->spent_on, 0, 7);
        $budget = DB::table('fund_head_budgets')->where('expense_head_id', $spend->expense_head_id)->where('month', $month.'-01')->value('amount');
        if ($budget === null) {
            return null;
        }
        $used = $this->usedInMonth((int) $spend->expense_head_id, $month);
        $limit = Money::cents((string) $budget);
        if ($used <= $limit) {
            return null;
        }
        $head = DB::table('fund_expense_heads')->where('id', $spend->expense_head_id)->first();

        return __('funds.budget_over_warning', [
            'head' => (string) (app()->getLocale() === 'bn' ? $head->name_bn : $head->name_en),
            'month' => $month,
            'budget' => Money::grouped(Money::decimal($limit)),
            'used' => Money::grouped(Money::decimal($used)),
            'over' => Money::grouped(Money::decimal($used - $limit)),
        ]);
    }

    /**
     * Budgets for one month with what has been used so far, one row per head that has a budget.
     *
     * @return list<array{head_id: int, name_en: string, name_bn: string, budget: string, used: string, left: string, over: bool}>
     */
    public function forMonth(string $month): array
    {
        $firstDay = $month.'-01';
        $used = DB::table('reserve_disbursements')
            ->where('program', FundSpending::Program)
            ->whereIn('status', ['requested', 'paid'])
            ->whereNotIn('fund_key', CompanyFunds::OwnerOnly)
            ->whereBetween('spent_on', [$firstDay, CarbonImmutable::parse($firstDay)->endOfMonth()->toDateString()])
            ->groupBy('expense_head_id')
            ->selectRaw('expense_head_id, SUM(amount) AS total');

        return DB::table('fund_head_budgets as b')
            ->join('fund_expense_heads as h', 'h.id', '=', 'b.expense_head_id')
            ->leftJoinSub($used, 'used', 'used.expense_head_id', '=', 'b.expense_head_id')
            ->where('b.month', $firstDay)
            ->orderBy('h.name_en')
            ->get(['b.expense_head_id', 'h.name_en', 'h.name_bn', 'b.amount', 'used.total'])
            ->map(function (object $row): array {
                $budget = Money::cents((string) $row->amount);
                $spent = $row->total === null ? 0 : Money::cents((string) $row->total);

                return [
                    'head_id' => (int) $row->expense_head_id,
                    'name_en' => (string) $row->name_en,
                    'name_bn' => (string) $row->name_bn,
                    'budget' => Money::decimal($budget),
                    'used' => Money::decimal($spent),
                    'left' => Money::decimal(max(0, $budget - $spent)),
                    'over' => $spent > $budget,
                ];
            })
            ->all();
    }

    /**
     * Which "head|YYYY-MM" pairs are over budget, for flagging spends waiting for approval.
     *
     * @return list<string>
     */
    public function overBudgetKeys(): array
    {
        $used = DB::table('reserve_disbursements')
            ->where('program', FundSpending::Program)
            ->whereIn('status', ['requested', 'paid'])
            ->whereNotIn('fund_key', CompanyFunds::OwnerOnly)
            ->whereNotNull('spent_on')
            ->groupByRaw("expense_head_id, date_trunc('month', spent_on)::date")
            ->selectRaw("expense_head_id, date_trunc('month', spent_on)::date AS month, SUM(amount) AS total");

        return DB::table('fund_head_budgets as b')
            ->joinSub($used, 'used', fn ($join) => $join->on('used.expense_head_id', '=', 'b.expense_head_id')->on('used.month', '=', 'b.month'))
            ->whereColumn('used.total', '>', 'b.amount')
            ->selectRaw("b.expense_head_id::text || '|' || to_char(b.month, 'YYYY-MM') AS budget_key")
            ->pluck('budget_key')
            ->all();
    }

    private function usedInMonth(int $headId, string $month): int
    {
        $firstDay = $month.'-01';
        $total = DB::table('reserve_disbursements')
            ->where('program', FundSpending::Program)
            ->where('expense_head_id', $headId)
            ->whereIn('status', ['requested', 'paid'])
            ->whereNotIn('fund_key', CompanyFunds::OwnerOnly)
            ->whereBetween('spent_on', [$firstDay, CarbonImmutable::parse($firstDay)->endOfMonth()->toDateString()])
            ->sum('amount');

        return Money::cents((string) $total);
    }
}
