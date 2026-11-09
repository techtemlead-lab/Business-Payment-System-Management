<?php

namespace App\Http\Controllers;

use App\Domain\Finance\AccountingStatements;
use App\Domain\Finance\StatementImports;
use App\Domain\Finance\WithdrawalApprovalLimit;
use App\Domain\Payouts\PayoutBatch;
use App\Models\User;
use App\Support\Audit;
use App\Support\PdfFonts;
use App\Support\PortalHost;
use App\Support\SpreadsheetCell;
use App\Support\TableWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The accounting page: owner and finance staff only.
 */
class AccountingController extends Controller
{
    /** Old tabs of this page, moved to the places that do this work now. */
    private const MovedTabs = ['statements' => '/treasury/work', 'limit' => '/operations/finance-settings', 'payouts' => '/payouts-hub'];

    /** The bank statement check, the one screen left of the old accounting page. */
    public function index(Request $request, StatementImports $imports): InertiaResponse|RedirectResponse
    {
        $this->authorizeAccounting($request->user());
        $moved = self::MovedTabs[$request->query('tab')] ?? null;
        if ($moved !== null) {
            return redirect($moved, 301);
        }
        $request->validate(['statement' => 'nullable|uuid']);
        $statement = $request->filled('statement') ? $imports->detail($request->string('statement')->toString()) : null;
        abort_if($request->filled('statement') && $statement === null, 404);

        return Inertia::render('Accounting', [
            'channels' => StatementImports::Channels,
            'history' => $imports->history(),
            'statement' => $statement,
        ]);
    }

    /** Owner-only: the withdrawal size flagged as a large payout. */
    public function saveApprovalLimit(Request $request, WithdrawalApprovalLimit $limit): Response
    {
        $this->authorizeAccounting($request->user());
        abort_unless($request->user()->owner(), 403);
        $data = $request->validate([
            'amount' => [...FinanceOperationsController::Amount, 'gt:0'],
            'version' => 'required|integer|min:0',
            'reason' => FinanceOperationsController::Reason,
        ]);
        $limit->save($request->user(), $data['amount'], (int) $data['version'], $data['reason']);

        return $request->expectsJson() ? response()->json(['data' => $limit->current()]) : back()->with('success', __('accounting.flash_limit_saved'));
    }

    /** Download one statement as a spreadsheet or PDF, the same way the Report Centre does. */
    public function export(Request $request, AccountingStatements $statements): Response
    {
        $this->authorizeAccounting($request->user());
        $request->validate([
            'type' => ['required', Rule::in(AccountingStatements::Types)],
            'format' => 'required|in:xlsx,pdf',
        ]);
        [$from, $to] = $this->period($request);
        $type = $request->string('type')->toString();
        $format = $request->string('format')->toString();
        $report = $statements->report($type, $from, $to, $this->showOwnerHeads($request->user()));
        $name = __('accounting.statement_'.str_replace('-', '_', $type));
        $notice = __('accounting.management_notice');
        $scope = implode(' ', [__('accounting.journal_scope_notice'), __('accounting.accountant_title'), ...AccountingStatements::accountantChecks()]);
        $generatedAt = now()->timezone('Asia/Dhaka')->format('d M Y H:i');
        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.'.$format);
        try {
            $format === 'xlsx'
                ? $this->writeSpreadsheet($path, $name, $report, $from, $to, $generatedAt, $notice, $scope)
                : $this->writePdf($path, $name, $report, $from, $to, $generatedAt, $notice, $scope);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        Audit::record($request->user()->id, 'report.exported', $type, ['type' => $type, 'format' => $format, 'from' => $from, 'to' => $to]);

        return response()->download($path, 'Madina-'.$type.'-'.$from.'-'.$to.'.'.$format, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    /** The approved withdrawals as a CSV for the bank or wallet. Changes nothing. */
    public function exportPayoutBatch(Request $request, PayoutBatch $payouts): Response
    {
        $this->authorizeAccounting($request->user());
        [$month, $channel] = $this->payoutFilters($request);
        $file = $payouts->fileRows($month, $channel);
        Audit::record($request->user()->id, 'report.exported', 'payout-batch', [
            'month' => $month, 'channel' => $channel, 'count' => count($file['rows']), 'total_net' => $file['total_net'], 'withdrawal_ids' => $file['ids'],
        ]);

        return response()->streamDownload(function () use ($file): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, PayoutBatch::FileColumns, ',', '"', '');
            foreach ($file['rows'] as $row) {
                fputcsv($output, $row, ',', '"', '');
            }
            fclose($output);
        }, 'Madina-payout-batch-'.$month.'-'.($channel ?? 'all').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function uploadStatement(Request $request, StatementImports $imports): Response
    {
        $this->authorizeAccounting($request->user());
        $data = $request->validate([
            'channel' => ['required', Rule::in(StatementImports::Channels)],
            'source_reference' => 'required|string|min:3|max:150',
            'file' => 'required|file|mimes:csv,txt,xlsx|extensions:csv,xlsx|max:10240',
        ]);
        $id = $imports->upload($request->user(), $data['channel'], $data['source_reference'], $request->file('file'));

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id]], 201)
            : redirect('/operations/accounting?tab=import&statement='.$id)->with('success', __('accounting.flash_statement_uploaded'));
    }

    public function mapStatement(Request $request, string $id, StatementImports $imports): Response
    {
        $this->authorizeAccounting($request->user());
        $data = $request->validate([
            'first_data_row' => 'required|integer|min:1|max:1000000',
            'reference_column' => 'required|integer|min:0|max:200',
            'amount_column' => 'required|integer|min:0|max:200|different:reference_column',
            'date_column' => 'nullable|integer|min:0|max:200',
            'description_column' => 'nullable|integer|min:0|max:200',
        ], ['amount_column.different' => __('accounting.err_same_column')]);
        $imports->map($request->user(), $id, [
            'first_data_row' => (int) $data['first_data_row'],
            'reference_column' => (int) $data['reference_column'],
            'amount_column' => (int) $data['amount_column'],
            'date_column' => isset($data['date_column']) ? (int) $data['date_column'] : null,
            'description_column' => isset($data['description_column']) ? (int) $data['description_column'] : null,
        ]);

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id]])
            : redirect('/operations/accounting?tab=import&statement='.$id)->with('success', __('accounting.flash_statement_mapped'));
    }

    /** Records that staff checked this line. It does not approve the payment. */
    public function confirmStatementLine(Request $request, int $id, StatementImports $imports): Response
    {
        $this->authorizeAccounting($request->user());
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $imports->confirm($request->user(), $id, $data['reason'] ?? null);

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id]]) : back()->with('success', __('accounting.flash_line_confirmed'));
    }

    public function dismissStatementLine(Request $request, int $id, StatementImports $imports): Response
    {
        $this->authorizeAccounting($request->user());
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $imports->dismiss($request->user(), $id, $data['reason'] ?? __('accounting.dismiss_reason'));

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id]]) : back()->with('success', __('accounting.flash_line_dismissed'));
    }

    /** @return array{string, ?string} */
    private function payoutFilters(Request $request): array
    {
        $data = $request->validate(['month' => 'nullable|date_format:Y-m', 'channel' => ['nullable', Rule::in(PayoutBatch::Channels)]]);

        return [$data['month'] ?? now()->timezone('Asia/Dhaka')->format('Y-m'), $data['channel'] ?? null];
    }

    /** Spends from the owners' own fund are named by head only on the owner panel. */
    private function showOwnerHeads(User $actor): bool
    {
        return $actor->owner() && PortalHost::current() === 'sadmin';
    }

    /** Owner and finance staff only; the money figures here are company-wide. */
    private function authorizeAccounting(User $actor): void
    {
        abort_unless($actor->staff() && $actor->allows('finance'), 403);
    }

    /**
     * The chosen period, this month so far when none is given. At most a year, as in the Report Centre.
     *
     * @return array{string, string}
     */
    private function period(Request $request): array
    {
        $today = now()->timezone('Asia/Dhaka');
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = $data['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today->toDateString();
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => __('accounting.err_period_order')]);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw ValidationException::withMessages(['to' => __('portal.ops_val_date_range')]);
        }

        return [$from, $to];
    }

    /** @param array{columns: list<string>, money: list<string>, rows: list<array<string, string>>, totals: array<string, string>} $report */
    private function writeSpreadsheet(string $path, string $name, array $report, string $from, string $to, string $generatedAt, string $notice, string $scope): void
    {
        $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 28));
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName(TableWorkbook::sheetName($name));
            $writer->addRow(new Row([new StringCell($notice, (new Style)->withFontBold(true))]));
            $writer->addRow(Row::fromValuesWithStyle(array_map($this->columnLabel(...), $report['columns']), (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('153D30')));
            foreach ($report['rows'] as $row) {
                $cells = [];
                foreach ($report['columns'] as $column) {
                    $cells[] = in_array($column, $report['money'], true)
                        ? new NumericCell((float) $row[$column], (new Style)->withFormat('#,##0.00'))
                        : new StringCell(SpreadsheetCell::text($row[$column]));
                }
                $writer->addRow(new Row($cells));
            }
            $totalStyle = (new Style)->withFontBold(true)->withBackgroundColor('E8F0EC');
            $writer->addRow(new Row(array_map(fn (string $column) => isset($report['totals'][$column])
                ? new NumericCell((float) $report['totals'][$column], $totalStyle->withFormat('#,##0.00'))
                : new StringCell($column === $report['columns'][0] ? __('portal.ops_export_total') : '', $totalStyle), $report['columns'])));
            $writer->addNewSheetAndMakeItCurrent()->setName(__('portal.ops_export_summary_sheet'));
            foreach ([
                ['Madina Natural Health Care'],
                [$name],
                [$notice],
                [$scope],
                [__('portal.ops_period'), __('portal.ops_period_range', ['from' => $from, 'to' => $to])],
                [__('portal.ops_export_currency'), 'BDT'],
                [__('portal.ops_export_timezone'), 'Asia/Dhaka'],
                [__('portal.ops_export_generated'), $generatedAt],
            ] as $summaryRow) {
                $writer->addRow(Row::fromValues($summaryRow));
            }
        } finally {
            $writer->close();
        }
    }

    /** @param array{columns: list<string>, money: list<string>, rows: list<array<string, string>>, totals: array<string, string>} $report */
    private function writePdf(string $path, string $name, array $report, string $from, string $to, string $generatedAt, string $notice, string $scope): void
    {
        $html = '<style>body{font-family:freeserif;font-size:10pt;color:#153d30}table{border-collapse:collapse;width:100%}th{background:#153d30;color:white}td,th{padding:5px;border:1px solid #cddbd4}td.num,th.num{text-align:right;white-space:nowrap}tfoot th{background:#e8f0ec;color:#153d30}h1{font-size:18pt}small{color:#52665d}.notice{border:1px solid #b45309;padding:8px;color:#7c2d12}</style>'
            .'<h1>Madina Natural Health Care</h1><h2>'.e($name).'</h2><p>'.e($from).' — '.e($to).' · BDT · Asia/Dhaka</p>'
            .'<p class="notice"><b>'.e($notice).'</b><br>'.e($scope).'</p><table><thead><tr>';
        foreach ($report['columns'] as $column) {
            $html .= '<th'.(in_array($column, $report['money'], true) ? ' class="num"' : '').'>'.e($this->columnLabel($column)).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($report['rows'] as $row) {
            $html .= '<tr>';
            foreach ($report['columns'] as $column) {
                $numeric = in_array($column, $report['money'], true);
                $html .= '<td'.($numeric ? ' class="num"' : '').'>'.e($numeric ? number_format((float) $row[$column], 2) : $row[$column]).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody><tfoot><tr>';
        foreach ($report['columns'] as $column) {
            $html .= isset($report['totals'][$column])
                ? '<th class="num">'.e(number_format((float) $report['totals'][$column], 2)).'</th>'
                : '<th>'.e($column === $report['columns'][0] ? __('portal.ops_export_total') : '').'</th>';
        }
        $html .= '</tr></tfoot></table><p><small>'.e(__('portal.ops_export_generated_footer', ['when' => $generatedAt])).'</small></p>';
        $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('report-exports'), 'format' => 'A4', ...PdfFonts::options()]);
        $pdf->SetTitle($name);
        $pdf->SetHTMLFooter('<div style="text-align:right;font-size:8pt">'.e($notice).'</div>');
        $pdf->WriteHTML($html);
        $pdf->Output($path, Destination::FILE);
    }

    private function columnLabel(string $column): string
    {
        $key = 'accounting.column_'.$column;
        $label = __($key);

        return $label === $key ? Str::headline($column) : $label;
    }
}
