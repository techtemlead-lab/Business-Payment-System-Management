<?php

namespace App\Http\Controllers;

use App\Domain\Finance\AccountingStatements;
use App\Models\User;
use App\Support\Audit;
use App\Support\SpreadsheetCell;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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
    public const Tabs = ['statements'];

    public function index(Request $request, AccountingStatements $statements): InertiaResponse|JsonResponse
    {
        $this->authorizeAccounting($request->user());
        $request->validate(['tab' => ['nullable', Rule::in(self::Tabs)]]);
        [$from, $to] = $this->period($request);
        $data = [
            'tab' => $request->string('tab')->toString() ?: 'statements',
            'filters' => ['from' => $from, 'to' => $to],
            'statements' => $statements->build($from, $to),
            'exportBase' => '/commands/operations/accounting/export',
        ];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('Accounting', $data);
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
        $report = $statements->report($type, $from, $to);
        $name = __('accounting.statement_'.str_replace('-', '_', $type));
        $notice = __('accounting.management_notice');
        $scope = __('accounting.journal_scope_notice');
        $generatedAt = now()->timezone('Asia/Dhaka')->format('d M Y H:i');
        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.'.$format);
        try {
            $format === 'xlsx'
                ? $this->writeSpreadsheet($path, $type, $name, $report, $from, $to, $generatedAt, $notice, $scope)
                : $this->writePdf($path, $name, $report, $from, $to, $generatedAt, $notice, $scope);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        Audit::record($request->user()->id, 'report.exported', $type, ['type' => $type, 'format' => $format, 'from' => $from, 'to' => $to]);

        return response()->download($path, 'Madina-'.$type.'-'.$from.'-'.$to.'.'.$format, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
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
    private function writeSpreadsheet(string $path, string $type, string $name, array $report, string $from, string $to, string $generatedAt, string $notice, string $scope): void
    {
        $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 28));
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName(Str::headline($type));
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
                [__('portal.ops_period'), $from.' '.__('portal.ops_through').' '.$to],
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
        $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('report-exports'), 'format' => 'A4', 'default_font' => 'freeserif', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
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
