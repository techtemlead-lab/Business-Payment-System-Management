<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FundBudgets;
use App\Domain\Finance\FundSpending;
use App\Domain\Payouts\PayoutReceipts;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Company funds: balance and history of each fund, spends waiting for approval, expense heads
 * and the spending report. Owners and finance staff only.
 */
final class CompanyFundsSection implements WorkspaceSection
{
    public const Tabs = ['funds', 'approvals', 'heads', 'report'];

    public function __construct(private CompanyFunds $funds, private FundBudgets $budgets) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'company-funds') {
            return [];
        }
        $user = $context->user;
        abort_unless(FundSpending::canManage($user), 403);
        $showOwnerFunds = $user->owner() && $context->portal === 'sadmin';
        $visible = CompanyFunds::visibleKeys($showOwnerFunds);
        $filters = $context->request->validate([
            'tab' => ['nullable', Rule::in(self::Tabs)],
            'fund' => ['nullable', Rule::in($visible)],
            'head' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'budget_month' => 'nullable|date_format:Y-m',
        ]);
        $tab = $filters['tab'] ?? 'funds';
        $budgetMonth = $filters['budget_month'] ?? now()->timezone('Asia/Dhaka')->format('Y-m');
        $balances = $this->funds->balances();
        $selected = $tab === 'funds' ? ($filters['fund'] ?? null) : null;
        $period = CompanyFunds::defaultPeriod();
        $reportFilters = ['from' => $filters['from'] ?? $period['from'], 'to' => $filters['to'] ?? $period['to'], 'fund' => $filters['fund'] ?? null, 'head' => $filters['head'] ?? null];

        return ['company_funds' => [
            'tab' => $tab,
            'funds' => array_map(fn (string $key): array => $this->fundRow($key, $balances[$key]), $visible),
            'selected' => $selected,
            'money_in' => $selected ? $this->funds->moneyIn($selected)->through(fn ($row): array => [...(array) $row, 'source_label' => CompanyFunds::sourceLabel($selected, $row->category, $row->status)]) : null,
            'money_out' => $selected ? $this->funds->moneyOut($selected) : null,
            'approvals' => $tab === 'approvals' ? $this->approvals($visible, $user) : [],
            'approvals_waiting' => (int) DB::table('reserve_disbursements')->where('program', FundSpending::Program)->where('status', 'requested')->whereIn('fund_key', $visible)->count(),
            'heads' => DB::table('fund_expense_heads')->orderByDesc('is_active')->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'is_active', 'for_tours']),
            'methods' => PayoutReceipts::Methods,
            'filters' => $reportFilters,
            'report' => $tab === 'report' ? $this->funds->report($reportFilters, $showOwnerFunds) : null,
            'budget_month' => $budgetMonth,
            'budgets' => $tab === 'heads' ? $this->budgets->forMonth($budgetMonth) : [],
        ]];
    }

    /**
     * @param  array{money_in: int, money_out: int, pending: int, balance: int, available: int}  $balance
     * @return array<string, mixed>
     */
    private function fundRow(string $key, array $balance): array
    {
        return [
            'key' => $key,
            'money_in' => $this->signed($balance['money_in']),
            'money_out' => $this->signed($balance['money_out']),
            'pending' => $this->signed($balance['pending']),
            'balance' => $this->signed($balance['balance']),
            'available' => $this->signed(max(0, $balance['available'])),
            'spendable' => CompanyFunds::isSpendable($key),
            'award_only' => in_array($key, CompanyFunds::AwardOnly, true),
        ];
    }

    /**
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
            ->map(fn ($row): array => [...(array) $row, 'can_review' => $row->created_by !== $actor->id, 'can_cancel' => $row->created_by === $actor->id,
                'over_budget' => ! in_array($row->fund_key, CompanyFunds::OwnerOnly, true) && in_array($row->expense_head_id.'|'.substr((string) $row->spent_on, 0, 7), $overBudget, true),
                'can_self_approve' => $row->created_by === $actor->id && app(SelfApproval::class)->isAvailable($actor, [$row->created_by], canDoStep: FundSpending::approverOf($row->fund_key))])
            ->all();
    }

    /** Taka amount as text; the clinic fund and its history can be below zero. */
    private function signed(int $cents): string
    {
        return $cents < 0 ? '-'.Money::decimal(-$cents) : Money::decimal($cents);
    }
}
