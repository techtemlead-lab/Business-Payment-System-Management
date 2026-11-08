<?php

namespace App\Domain\Finance;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trial balance, profit and loss and balance sheet.
 *
 * The trial balance is the journal and nothing else. The other two start from the journal
 * and add what it does not hold (product sales, cost of goods, purchases, stock) from
 * {@see ManagementFigures}, marking where every line comes from. Read-only: nothing here
 * posts, edits or recalculates a journal line. These are management reports for the owner
 * and finance staff, not audited statements.
 */
final class AccountingStatements
{
    public const Types = ['trial-balance', 'profit-loss', 'balance-sheet'];

    public const Asset = 'asset';

    public const Liability = 'liability';

    public const Equity = 'equity';

    public const Income = 'income';

    public const Expense = 'expense';

    public const Unclassified = 'unclassified';

    /** How far a line can be trusted. */
    public const Firm = 'firm';

    public const Estimate = 'estimate';

    public const NeedsCheck = 'needs_check';

    /** The account the company 30% share and the clinic's manufacturer share post to. */
    private const ManufacturerAccount = 'manufacturer_fund';

    /**
     * Where each journal account belongs. An account missing from this list still appears
     * (as "not sorted yet"), so the statements keep adding up to the whole journal.
     *
     * @var array<string, string>
     */
    public const AccountClasses = [
        'manual_collection_clearing' => self::Asset,
        'opening_collection_clearing' => self::Asset,
        'manual_payout_clearing' => self::Asset,
        'opening_receivables' => self::Asset,
        'commission_clawback_receivable' => self::Asset,
        'company_reserve_clawback_receivable' => self::Asset,
        'clinic_cash' => self::Asset,
        'wallet_liability' => self::Liability,
        'commission_held_liability' => self::Liability,
        'payroll_payable' => self::Liability,
        'payroll_deductions_payable' => self::Liability,
        'payout_withholding_payable' => self::Liability,
        'manufacturer_fund' => self::Liability,
        'opening_balance_equity' => self::Equity,
        'clinic_fund' => self::Income,
        'commission_expense' => self::Expense,
        'commission_clawback_recovery' => self::Expense,
        'payroll_expense' => self::Expense,
        'company_marketing_expense' => self::Expense,
        'company_fund_expense' => self::Expense,
    ];

    public function __construct(private ManagementFigures $figures) {}

    /**
     * All three statements for one period (dates in Asia/Dhaka, both inclusive).
     *
     * @param  bool  $showOwnerHeads  False groups spends from the owners' own fund into one line.
     * @return array{
     *     period: array{from: string, to: string},
     *     trial_balance: array{rows: list<array<string, string>>, totals: array<string, string>},
     *     profit_loss: array{income: list<array<string, string>>, cost_of_sales: list<array<string, string>>, expenses: list<array<string, string>>, total_income: string, total_cost_of_sales: string, gross_profit: string, total_expenses: string, result: string},
     *     balance_sheet: array{assets: list<array<string, string>>, liabilities: list<array<string, string>>, equity: list<array<string, string>>, unclassified: list<array<string, string>>, earlier_result: string, period_result: string, total_assets: string, total_liabilities: string, total_equity: string, total_liabilities_and_equity: string, difference: string},
     *     purchases: array{in_period: string, supplier_due: string},
     *     checks: array{journal_debit: string, journal_credit: string, ties_to_journal: bool, journal_balanced: bool, statement_balances: bool, unclassified_accounts: list<string>, journal_result: string, other_records_result: string, result_reconciled: bool},
     *     notes: list<string>,
     *     accountant_checks: list<string>
     * }
     */
    public function build(string $from, string $to, bool $showOwnerHeads = true): array
    {
        $start = CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->utc();
        $accounts = $this->accountMovements($start, $end);
        $figures = $this->figures->build($start, $end, $showOwnerHeads);
        $covered = $figures['spends']['covered']['in_period'];
        $clinicCoveredIncome = $figures['clinic']['clinic_fund'] - ($covered['clinic_fund'] ?? 0);
        $manufacturerAdjustment = $figures['manufacturer_adjustment'];

        $trialRows = [];
        $income = $expenses = $assets = $liabilities = $equity = $unclassified = [];
        $totals = ['opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0];
        $totalIncome = $totalExpenses = $totalAssets = $totalLiabilities = $totalEquity = $earlierResult = $journalResult = 0;
        $manufacturerShown = false;

        foreach ($accounts as $account => $movement) {
            $class = self::AccountClasses[$account] ?? self::Unclassified;
            $label = $this->label($account);
            $opening = $movement['opening_debit'] - $movement['opening_credit'];
            $closing = $opening + $movement['debit'] - $movement['credit'];
            $trialRows[] = [
                'account' => $account, 'label' => $label, 'class' => $class,
                'opening' => $this->signed($opening),
                'debit' => Money::decimal($movement['debit']),
                'credit' => Money::decimal($movement['credit']),
                'closing' => $this->signed($closing),
            ];
            $totals['opening'] += $opening;
            $totals['debit'] += $movement['debit'];
            $totals['credit'] += $movement['credit'];
            $totals['closing'] += $closing;

            $periodNet = $movement['debit'] - $movement['credit'];
            $moved = $movement['debit'] !== 0 || $movement['credit'] !== 0;
            if ($class === self::Income) {
                $earlierResult -= $opening;
                $journalResult -= $periodNet;
                $coveredHere = $account === 'clinic_fund' ? $clinicCoveredIncome : ($covered[$account] ?? 0);
                $remaining = -$periodNet - $coveredHere;
                if ($remaining !== 0 || ($coveredHere === 0 && $moved)) {
                    $income[] = $this->line($account, $label, $remaining);
                }
                $totalIncome += $remaining;

                continue;
            }
            if ($class === self::Expense) {
                $earlierResult -= $opening;
                $journalResult -= $periodNet;
                $coveredHere = $covered[$account] ?? 0;
                $remaining = $periodNet - $coveredHere;
                if ($remaining !== 0 || ($coveredHere === 0 && $moved)) {
                    $expenses[] = $this->line($account, $label, $remaining);
                }
                $totalExpenses += $remaining;

                continue;
            }
            if ($account === self::ManufacturerAccount) {
                $manufacturerShown = true;
                $closing -= $manufacturerAdjustment;
            }
            if ($closing === 0) {
                continue;
            }
            match ($class) {
                self::Asset => $assets[] = $this->line($account, $label, $closing),
                self::Liability => $liabilities[] = $account === self::ManufacturerAccount && $manufacturerAdjustment !== 0
                    ? $this->line($account, $label, -$closing, 'journal_adjusted', self::NeedsCheck)
                    : $this->line($account, $label, -$closing),
                self::Equity => $equity[] = $this->line($account, $label, -$closing),
                default => $unclassified[] = $this->line($account, $label, $closing),
            };
            match ($class) {
                self::Liability => $totalLiabilities -= $closing,
                self::Equity => $totalEquity -= $closing,
                default => $totalAssets += $closing,
            };
        }
        if (! $manufacturerShown && $manufacturerAdjustment !== 0) {
            $liabilities[] = $this->line(self::ManufacturerAccount, $this->label(self::ManufacturerAccount), $manufacturerAdjustment, 'journal_adjusted', self::NeedsCheck);
            $totalLiabilities += $manufacturerAdjustment;
        }

        $sales = $figures['sales'];
        $clinic = $figures['clinic'];
        $cost = $figures['cost_of_goods'];
        $stock = $figures['stock'];
        $spends = $figures['spends'];
        $orderMoney = $figures['order_money'];

        $income = [...$this->nonZero([
            $this->line('product_sales', __('accounting.line_product_sales'), $sales['in_period'], 'delivered_orders'),
            $this->line('clinic_sales', __('accounting.line_clinic_sales'), $clinic['sales'], 'clinic_dispenses'),
        ]), ...$income];
        $totalIncome += $sales['in_period'] + $clinic['sales'];

        $costOfSales = $this->nonZero([
            $this->line('cost_of_goods', __('accounting.line_cost_of_goods'), $cost['actual']['in_period'], 'stock_lots'),
            $this->line('cost_of_goods_estimate', __('accounting.line_cost_of_goods_estimate'), $cost['estimate']['in_period'], 'product_cost_price', self::Estimate),
            $this->line('clinic_manufacturer_share', __('accounting.line_clinic_manufacturer_share'), $clinic['manufacturer'], 'journal'),
        ]);
        $totalCostOfSales = $cost['actual']['in_period'] + $cost['estimate']['in_period'] + $clinic['manufacturer'];
        $grossProfit = $totalIncome - $totalCostOfSales;

        foreach ($spends['heads'] as $head) {
            $expenses[] = $this->line('head_'.$head['key'], $head['label'], $head['amount'], 'fund_spends');
            $totalExpenses += $head['amount'];
        }
        $expenses = [...$expenses, ...$this->nonZero([
            $this->line('stock_written_off', __('accounting.line_stock_written_off'), $stock['loss']['in_period'], 'stock_lots'),
        ])];
        $totalExpenses += $stock['loss']['in_period'];

        $periodResult = $grossProfit - $totalExpenses;
        $otherRecordsResult = $figures['effect']['in_period'];
        $earlierResult += $figures['effect']['earlier'];

        $assets = [...$assets, ...$this->nonZero([
            $this->line('order_money_received', __('accounting.line_order_money_received'), $orderMoney['received'], 'order_receipts', self::NeedsCheck),
            $this->line('delivered_not_paid', __('accounting.line_delivered_not_paid'), $orderMoney['delivered_not_paid'], 'delivered_orders', self::NeedsCheck),
            $this->line('stock_at_cost', __('accounting.line_stock_at_cost'), $stock['actual'], 'stock_lots'),
            $this->line('stock_estimated', __('accounting.line_stock_estimated'), $stock['estimate'], 'product_cost_price', self::Estimate),
            $this->line('goods_in_transit', __('accounting.line_goods_in_transit'), $stock['in_transit'], 'stock_lots', $stock['in_transit_estimated'] > 0 ? self::Estimate : self::Firm),
        ])];
        $totalAssets += $orderMoney['received'] + $orderMoney['delivered_not_paid'] + $stock['actual'] + $stock['estimate'] + $stock['in_transit'];

        $supplierDue = $figures['purchases']['earlier'] + $figures['purchases']['in_period'] - $spends['supplier_paid'];
        $liabilities = [...$liabilities, ...$this->nonZero([
            $this->line('supplier_due', __('accounting.line_supplier_due'), $supplierDue, 'goods_receipts', self::NeedsCheck),
            $this->line('paid_not_delivered', __('accounting.line_paid_not_delivered'), $orderMoney['paid_not_delivered'], 'order_receipts', self::NeedsCheck),
        ])];
        $totalLiabilities += $supplierDue + $orderMoney['paid_not_delivered'];

        $openingStock = $stock['opening'] + $cost['without_lots'];
        $equity = [...$equity, ...$this->nonZero([
            $this->line('opening_stock', __('accounting.line_opening_stock'), $openingStock, 'stock_lots', self::NeedsCheck),
            $this->line('stock_without_receipt', __('accounting.line_stock_without_receipt'), $stock['other_in'], 'stock_lots', self::NeedsCheck),
            $this->line('owner_withdrawals', __('accounting.line_owner_withdrawals'), -$spends['owner_drawn'], 'fund_spends'),
        ])];
        $totalEquity += $openingStock + $stock['other_in'] - $spends['owner_drawn'];

        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity + $earlierResult + $periodResult;
        $journal = $this->journalTotals($start, $end);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'trial_balance' => [
                'rows' => $trialRows,
                'totals' => [
                    'opening' => $this->signed($totals['opening']),
                    'debit' => Money::decimal($totals['debit']),
                    'credit' => Money::decimal($totals['credit']),
                    'closing' => $this->signed($totals['closing']),
                ],
            ],
            'profit_loss' => [
                'income' => $income,
                'cost_of_sales' => $costOfSales,
                'expenses' => $expenses,
                'total_income' => $this->signed($totalIncome),
                'total_cost_of_sales' => $this->signed($totalCostOfSales),
                'gross_profit' => $this->signed($grossProfit),
                'total_expenses' => $this->signed($totalExpenses),
                'result' => $this->signed($periodResult),
            ],
            'balance_sheet' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'unclassified' => $unclassified,
                'earlier_result' => $this->signed($earlierResult),
                'period_result' => $this->signed($periodResult),
                'total_assets' => $this->signed($totalAssets),
                'total_liabilities' => $this->signed($totalLiabilities),
                'total_equity' => $this->signed($totalEquity),
                'total_liabilities_and_equity' => $this->signed($totalLiabilitiesAndEquity),
                'difference' => $this->signed($totalAssets - $totalLiabilitiesAndEquity),
            ],
            'purchases' => [
                'in_period' => $this->signed($figures['purchases']['in_period']),
                'supplier_due' => $this->signed($supplierDue),
            ],
            'checks' => [
                'journal_debit' => Money::decimal($journal['debit']),
                'journal_credit' => Money::decimal($journal['credit']),
                'ties_to_journal' => $journal['debit'] === $totals['debit'] && $journal['credit'] === $totals['credit'],
                'journal_balanced' => $totals['closing'] === 0 && $totals['debit'] === $totals['credit'],
                'statement_balances' => $totalAssets === $totalLiabilitiesAndEquity,
                'unclassified_accounts' => array_keys(array_diff_key($accounts, self::AccountClasses)),
                'journal_result' => $this->signed($journalResult),
                'other_records_result' => $this->signed($otherRecordsResult),
                'result_reconciled' => $periodResult === $journalResult + $otherRecordsResult,
            ],
            'notes' => $this->notes($figures['notes'], $orderMoney),
            'accountant_checks' => self::accountantChecks(),
        ];
    }

    /**
     * One statement as rows for the file export, in the same shape as a ReportData report.
     *
     * @return array{columns: list<string>, money: list<string>, rows: list<array<string, string>>, totals: array<string, string>}
     */
    public function report(string $type, string $from, string $to, bool $showOwnerHeads = true): array
    {
        $statements = $this->build($from, $to, $showOwnerHeads);
        if ($type === 'trial-balance') {
            return [
                'columns' => ['account', 'opening', 'debit', 'credit', 'closing'],
                'money' => ['opening', 'debit', 'credit', 'closing'],
                'rows' => array_map(fn (array $row): array => ['account' => $row['label'], 'opening' => $row['opening'], 'debit' => $row['debit'], 'credit' => $row['credit'], 'closing' => $row['closing']], $statements['trial_balance']['rows']),
                'totals' => $statements['trial_balance']['totals'],
            ];
        }
        $section = fn (string $name, array $lines): array => array_map(fn (array $line): array => ['section' => __('accounting.section_'.$name), 'account' => $line['label'], 'source' => $this->sourceText($line), 'amount' => $line['amount']], $lines);
        $subtotal = fn (string $label, string $amount): array => ['section' => '', 'account' => $label, 'source' => '', 'amount' => $amount];
        if ($type === 'profit-loss') {
            $pl = $statements['profit_loss'];

            return [
                'columns' => ['section', 'account', 'source', 'amount'],
                'money' => ['amount'],
                'rows' => [
                    ...$section('income', $pl['income']),
                    $subtotal(__('accounting.total_income'), $pl['total_income']),
                    ...$section('cost_of_sales', $pl['cost_of_sales']),
                    $subtotal(__('accounting.total_cost_of_sales'), $pl['total_cost_of_sales']),
                    $subtotal(__('accounting.gross_profit'), $pl['gross_profit']),
                    ...$section('expenses', $pl['expenses']),
                    $subtotal(__('accounting.total_expenses'), $pl['total_expenses']),
                ],
                'totals' => ['amount' => $pl['result']],
            ];
        }
        $sheet = $statements['balance_sheet'];

        return [
            'columns' => ['section', 'account', 'source', 'amount'],
            'money' => ['amount'],
            'rows' => [
                ...$section('assets', $sheet['assets']),
                ...$section('unclassified', $sheet['unclassified']),
                $subtotal(__('accounting.total_assets'), $sheet['total_assets']),
                ...$section('liabilities', $sheet['liabilities']),
                $subtotal(__('accounting.total_liabilities'), $sheet['total_liabilities']),
                ...$section('equity', $sheet['equity']),
                $subtotal(__('accounting.earlier_result'), $sheet['earlier_result']),
                $subtotal(__('accounting.period_result'), $sheet['period_result']),
            ],
            'totals' => ['amount' => $sheet['total_liabilities_and_equity']],
        ];
    }

    /**
     * The fixed list of what an accountant must check, in the reader's language.
     *
     * @return list<string>
     */
    public static function accountantChecks(): array
    {
        return array_map(fn (int $number): string => __('accounting.accountant_check_'.$number), range(1, 7));
    }

    /** @return array<string, array{opening_debit: int, opening_credit: int, debit: int, credit: int}> */
    private function accountMovements(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('j.created_at', '<', $end)
            ->groupBy('l.account')
            ->orderBy('l.account')
            ->selectRaw(
                'l.account, '
                .'COALESCE(SUM(l.debit) FILTER (WHERE j.created_at < ?), 0) AS opening_debit, '
                .'COALESCE(SUM(l.credit) FILTER (WHERE j.created_at < ?), 0) AS opening_credit, '
                .'COALESCE(SUM(l.debit) FILTER (WHERE j.created_at >= ?), 0) AS debit, '
                .'COALESCE(SUM(l.credit) FILTER (WHERE j.created_at >= ?), 0) AS credit',
                [$start, $start, $start, $start],
            )
            ->get();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[$row->account] = [
                'opening_debit' => Money::cents((string) $row->opening_debit),
                'opening_credit' => Money::cents((string) $row->opening_credit),
                'debit' => Money::cents((string) $row->debit),
                'credit' => Money::cents((string) $row->credit),
            ];
        }

        return $accounts;
    }

    /**
     * The period's journal totals, counted separately from the per-account figures so
     * "ties to the journal" is a real comparison and not the same sum twice.
     *
     * @return array{debit: int, credit: int}
     */
    private function journalTotals(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('j.created_at', '>=', $start)
            ->where('j.created_at', '<', $end)
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
            ->first();

        return ['debit' => Money::cents((string) $totals->debit), 'credit' => Money::cents((string) $totals->credit)];
    }

    /**
     * @param  list<string>  $notes
     * @param  array<string, int>  $orderMoney
     * @return list<string>
     */
    private function notes(array $notes, array $orderMoney): array
    {
        if ($orderMoney['closed_orders'] > 0) {
            $notes[] = __('accounting.note_money_on_closed_orders', ['count' => (string) $orderMoney['closed_orders'], 'amount' => Money::grouped(Money::decimal($orderMoney['money_on_closed_orders']))]);
        }
        if ($orderMoney['part_delivered'] > 0) {
            $notes[] = __('accounting.note_part_delivered', ['count' => (string) $orderMoney['part_delivered'], 'amount' => Money::grouped(Money::decimal($orderMoney['part_delivered_amount']))]);
        }

        return $notes;
    }

    /**
     * @param  list<array<string, string>>  $lines
     * @return list<array<string, string>>
     */
    private function nonZero(array $lines): array
    {
        return array_values(array_filter($lines, fn (array $line): bool => $line['amount'] !== '0.00'));
    }

    /** @return array{account: string, label: string, amount: string, source: string, source_label: string, reliability: string} */
    private function line(string $account, string $label, int $cents, string $source = 'journal', string $reliability = self::Firm): array
    {
        return ['account' => $account, 'label' => $label, 'amount' => $this->signed($cents), 'source' => $source, 'source_label' => __('accounting.source_'.$source), 'reliability' => $reliability];
    }

    /** @param array<string, string> $line */
    private function sourceText(array $line): string
    {
        return $line['reliability'] === self::Firm ? $line['source_label'] : $line['source_label'].' ('.__('accounting.reliability_'.$line['reliability']).')';
    }

    private function label(string $account): string
    {
        $key = 'accounting.account_'.$account;
        $label = __($key);

        return $label === $key ? Str::headline($account) : $label;
    }

    private function signed(int $cents): string
    {
        return ($cents < 0 ? '-' : '').Money::decimal(abs($cents));
    }
}
