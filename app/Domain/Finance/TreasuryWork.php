<?php

namespace App\Domain\Finance;

use App\Domain\Payouts\PayoutReceipts;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everything waiting for a person, in one list: tiles for each kind of money work, spends to
 * approve, and expense heads with their budgets.
 */
final class TreasuryWork
{
    public function __construct(private FundBudgets $budgets) {}

    /**
     * @return array{tiles: list<array{key: string, label: string, href: string, count: int}>, approvals: list<array<string, mixed>>, heads: mixed, budgets: mixed, budget_month: string}
     */
    public function for(User $viewer, string $portal, Request $request): array
    {
        $visible = CompanyFunds::visibleKeys(TreasuryAccess::showsOwnerFunds($viewer, $portal));
        $month = $request->query('budget_month');
        $budgetMonth = is_string($month) && preg_match('/^\d{4}-\d{2}$/D', $month) === 1 ? $month : now()->timezone('Asia/Dhaka')->format('Y-m');

        return [
            'tiles' => $this->tiles($viewer),
            'approvals' => $this->approvals($visible, $viewer),
            'heads' => DB::table('fund_expense_heads')->orderByDesc('is_active')->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'is_active', 'for_tours']),
            'budgets' => $this->budgets->forMonth($budgetMonth),
            'budget_month' => $budgetMonth,
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
     * Spends waiting for approval, in the same shape as today's approvals list.
     *
     * @param  list<string>  $visible
     * @return list<array<string, mixed>>
     */
    private function approvals(array $visible, User $actor): array
    {
        $overBudget = $this->budgets->overBudgetKeys();

        return DB::table('reserve_disbursements as r')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->leftJoin('users as maker', 'maker.id', '=', 'r.created_by')
            ->where('r.program', FundSpending::Program)->where('r.status', 'requested')->whereIn('r.fund_key', $visible)
            ->orderBy('r.id')->limit(100)
            ->get(['r.id', 'r.fund_key', 'r.amount', 'r.spent_on', 'r.expense_head_id', 'r.payee_name', 'r.payment_method', 'r.payment_reference', 'r.reason', 'r.created_by', 'h.name_en', 'h.name_bn', 'maker.name as maker_name', DB::raw('r.receipt_path IS NOT NULL AS has_receipt')])
            ->map(fn (object $row): array => [...(array) $row, 'can_review' => $row->created_by !== $actor->id, 'can_cancel' => $row->created_by === $actor->id,
                'over_budget' => ! in_array($row->fund_key, CompanyFunds::OwnerOnly, true) && in_array($row->expense_head_id.'|'.substr((string) $row->spent_on, 0, 7), $overBudget, true),
                'can_self_approve' => $row->created_by === $actor->id && app(SelfApproval::class)->isAvailable($actor, [$row->created_by], canDoStep: FundSpending::approverOf((string) $row->fund_key))])
            ->all();
    }
}
