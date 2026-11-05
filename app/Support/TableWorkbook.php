<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Writes one on-screen list to an Excel file in the same style as the
 * Reports page: a frozen bold header, one row per record, a totals row
 * added up in paisa, and a summary sheet (company, filters, record count,
 * BDT, Asia/Dhaka). Text cells go through SpreadsheetCell::text so a cell
 * can never run as a formula.
 */
final class TableWorkbook
{
    /**
     * @param  list<array{key: string, label: string, type?: 'text'|'money'|'int', total?: bool}>  $columns
     * @param  iterable<array<string, mixed>>  $rows  one array per record, keyed by column key
     * @param  list<array{0: string, 1: string|int}>  $summary  extra label/value pairs for the summary sheet
     */
    public static function download(string $filename, string $title, array $columns, iterable $rows, array $summary = []): BinaryFileResponse
    {
        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.xlsx');
        $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 22));
        try {
            $writer->openToFile($path);
            try {
                self::write($writer, $title, $columns, $rows, $summary);
            } finally {
                $writer->close();
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    /** Signed amount in paisa; null and blank count as zero. */
    public static function signedCents(mixed $value): int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-+');
        if (str_contains($absolute, '.')) {
            [$whole, $fraction] = explode('.', $absolute, 2);
            $absolute = $whole.'.'.substr($fraction, 0, 2);
        }
        $cents = Money::cents($absolute === '' ? '0' : $absolute);

        return $negative ? -$cents : $cents;
    }

    /**
     * @param  list<array{key: string, label: string, type?: 'text'|'money'|'int', total?: bool}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<array{0: string, 1: string|int}>  $summary
     */
    private static function write(Writer $writer, string $title, array $columns, iterable $rows, array $summary): void
    {
        $totals = [];
        foreach ($columns as $column) {
            if (($column['total'] ?? false) === true) {
                $totals[$column['key']] = 0;
            }
        }
        $moneyStyle = (new Style)->withFormat('#,##0.00');
        $writer->getCurrentSheet()->setName(self::sheetName($title))->setSheetView(new SheetView(freezeRow: 2));
        $writer->addRow(Row::fromValuesWithStyle(
            array_map(fn (array $column): string => $column['label'], $columns),
            (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('153D30'),
        ));
        $count = 0;
        foreach ($rows as $row) {
            $count++;
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? null;
                $type = $column['type'] ?? 'text';
                if ($type === 'money') {
                    $cents = self::signedCents($value);
                    if (array_key_exists($column['key'], $totals)) {
                        $totals[$column['key']] += $cents;
                    }
                    $cells[] = new NumericCell($cents / 100, $moneyStyle);
                } elseif ($type === 'int') {
                    if (array_key_exists($column['key'], $totals)) {
                        $totals[$column['key']] += (int) $value;
                    }
                    $cells[] = new NumericCell((int) $value);
                } else {
                    $cells[] = new StringCell(SpreadsheetCell::text($value ?? ''));
                }
            }
            $writer->addRow(new Row($cells));
        }
        if ($totals !== []) {
            $totalStyle = (new Style)->withFontBold(true)->withBackgroundColor('E8F0EC');
            $cells = [];
            foreach (array_values($columns) as $index => $column) {
                if (! array_key_exists($column['key'], $totals)) {
                    $cells[] = new StringCell($index === 0 ? __('portal.ops_export_total') : '', $totalStyle);
                } elseif (($column['type'] ?? 'text') === 'money') {
                    $cells[] = new NumericCell($totals[$column['key']] / 100, $totalStyle->withFormat('#,##0.00'));
                } else {
                    $cells[] = new NumericCell($totals[$column['key']], $totalStyle);
                }
            }
            $writer->addRow(new Row($cells));
        }
        $writer->addNewSheetAndMakeItCurrent()->setName(self::sheetName(__('portal.ops_export_summary_sheet')));
        foreach ([
            ['Madina Natural Health Care'],
            [$title],
            ...$summary,
            [__('portal.ops_export_currency'), 'BDT'],
            [__('portal.ops_export_timezone'), 'Asia/Dhaka'],
            [__('portal.ops_export_records'), $count],
            [__('portal.ops_export_generated'), now('Asia/Dhaka')->format('d M Y H:i')],
        ] as $summaryRow) {
            $writer->addRow(new Row(array_map(
                fn (string|int $value): StringCell|NumericCell => is_int($value) ? new NumericCell($value) : new StringCell(SpreadsheetCell::text($value)),
                array_values($summaryRow),
            )));
        }
        foreach ($columns as $column) {
            if (array_key_exists($column['key'], $totals)) {
                $amount = $totals[$column['key']];
                $writer->addRow(new Row([
                    new StringCell($column['label']),
                    ($column['type'] ?? 'text') === 'money' ? new NumericCell($amount / 100, $moneyStyle) : new NumericCell($amount),
                ]));
            }
        }
    }

    /** Excel sheet names allow at most 31 characters and no []:*?/\ characters. */
    private static function sheetName(string $title): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/u', ' ', $title));

        return mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
    }
}
