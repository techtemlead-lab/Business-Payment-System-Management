<?php

namespace App\Domain\Finance;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trial balance, profit and loss and balance sheet, read straight from the journal.
 * Read-only: nothing here posts, edits or recalculates a journal line. These are
 * management reports for the owner and finance staff, not audited statements.
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

    /**
     * All three statements for one period (dates in Asia/Dhaka, both inclusive).
     *
     * @return array{
     *     period: array{from: string, to: string},
     *     trial_balance: array{rows: list<array<string, string>>, totals: array<string, string>},
     *     profit_loss: array{income: list<array{account: string, label: string, amount: string}>, expenses: list<array{account: string, label: string, amount: string}>, total_income: string, total_expenses: string, result: string},
     *     balance_sheet: array{assets: list<array{account: string, label: string, amount: string}>, liabilities: list<array{account: string, label: string, amount: string}>, equity: list<array{account: string, label: string, amount: string}>, unclassified: list<array{account: string, label: string, amount: string}>, earlier_result: string, period_result: string, total_assets: string, total_liabilities: string, total_equity: string, total_liabilities_and_equity: string, difference: string},
     *     checks: array{journal_debit: string, journal_credit: string, ties_to_journal: bool, journal_balanced: bool, statement_balances: bool, unclassified_accounts: list<string>}
     * }
     */
    public function build(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->utc();
        $accounts = $this->accountMovements($start, $end);

        $trialRows = [];
        $income = $expenses = $assets = $liabilities = $equity = $unclassified = [];
        $totals = ['opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0];
        $totalIncome = $totalExpenses = $totalAssets = $totalLiabilities = $totalEquity = $earlierResult = 0;

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
            if ($class === self::Income) {
                $earlierResult -= $opening;
                if ($movement['debit'] !== 0 || $movement['credit'] !== 0) {
                    $income[] = $this->line($account, $label, -$periodNet);
                }
                $totalIncome -= $periodNet;

                continue;
            }
            if ($class === self::Expense) {
                $earlierResult -= $opening;
                if ($movement['debit'] !== 0 || $movement['credit'] !== 0) {
                    $expenses[] = $this->line($account, $label, $periodNet);
                }
                $totalExpenses += $periodNet;

                continue;
            }
            if ($closing === 0) {
                continue;
            }
            match ($class) {
                self::Asset => $assets[] = $this->line($account, $label, $closing),
                self::Liability => $liabilities[] = $this->line($account, $label, -$closing),
                self::Equity => $equity[] = $this->line($account, $label, -$closing),
                default => $unclassified[] = $this->line($account, $label, $closing),
            };
            match ($class) {
                self::Liability => $totalLiabilities -= $closing,
                self::Equity => $totalEquity -= $closing,
                default => $totalAssets += $closing,
            };
        }

        $periodResult = $totalIncome - $totalExpenses;
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
                'expenses' => $expenses,
                'total_income' => $this->signed($totalIncome),
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
            'checks' => [
                'journal_debit' => Money::decimal($journal['debit']),
                'journal_credit' => Money::decimal($journal['credit']),
                'ties_to_journal' => $journal['debit'] === $totals['debit'] && $journal['credit'] === $totals['credit'],
                'journal_balanced' => $totals['closing'] === 0 && $totals['debit'] === $totals['credit'],
                'statement_balances' => $totalAssets === $totalLiabilitiesAndEquity,
                'unclassified_accounts' => array_keys(array_diff_key($accounts, self::AccountClasses)),
            ],
        ];
    }

    /**
     * One statement as rows for the file export, in the same shape as a ReportData report.
     *
     * @return array{columns: list<string>, money: list<string>, rows: list<array<string, string>>, totals: array<string, string>}
     */
    public function report(string $type, string $from, string $to): array
    {
        $statements = $this->build($from, $to);
        if ($type === 'trial-balance') {
            return [
                'columns' => ['account', 'opening', 'debit', 'credit', 'closing'],
                'money' => ['opening', 'debit', 'credit', 'closing'],
                'rows' => array_map(fn (array $row): array => ['account' => $row['label'], 'opening' => $row['opening'], 'debit' => $row['debit'], 'credit' => $row['credit'], 'closing' => $row['closing']], $statements['trial_balance']['rows']),
                'totals' => $statements['trial_balance']['totals'],
            ];
        }
        $section = fn (string $name, array $lines): array => array_map(fn (array $line): array => ['section' => __('accounting.section_'.$name), 'account' => $line['label'], 'amount' => $line['amount']], $lines);
        $subtotal = fn (string $label, string $amount): array => ['section' => '', 'account' => $label, 'amount' => $amount];
        if ($type === 'profit-loss') {
            $pl = $statements['profit_loss'];

            return [
                'columns' => ['section', 'account', 'amount'],
                'money' => ['amount'],
                'rows' => [
                    ...$section('income', $pl['income']),
                    $subtotal(__('accounting.total_income'), $pl['total_income']),
                    ...$section('expenses', $pl['expenses']),
                    $subtotal(__('accounting.total_expenses'), $pl['total_expenses']),
                ],
                'totals' => ['amount' => $pl['result']],
            ];
        }
        $sheet = $statements['balance_sheet'];

        return [
            'columns' => ['section', 'account', 'amount'],
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

    /** @return array{account: string, label: string, amount: string} */
    private function line(string $account, string $label, int $cents): array
    {
        return ['account' => $account, 'label' => $label, 'amount' => $this->signed($cents)];
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
