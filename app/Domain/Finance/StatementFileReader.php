<?php

namespace App\Domain\Finance;

use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

/**
 * Reads a bank, bKash or Nagad statement into plain rows of text. Same safety limits
 * as ImportWorkbook, but statements come in any layout, so columns are mapped afterwards.
 */
final class StatementFileReader
{
    private const MaxRows = 5000;

    private const MaxCellLength = 2000;

    /** @return list<array{row: int, cells: list<string>}> */
    public function read(string $path, string $extension): array
    {
        $rows = $extension === 'csv' ? $this->readCsv($path) : $this->readWorkbook($path);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('accounting.err_file_empty')]);
        }

        return $rows;
    }

    /** @return list<array{row: int, cells: list<string>}> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('portal.err_wb_unreadable')]);
        }
        try {
            $first = (string) fgets($handle);
            rewind($handle);
            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                fseek($handle, 3);
            }
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
            $rows = [];
            $number = 0;
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $number++;
                $cells = array_map(fn (?string $value): string => $this->text($value ?? '', $number), $values);
                $this->appendRow($rows, $number, $cells);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{row: int, cells: list<string>}> */
    private function readWorkbook(string $path): array
    {
        $this->assertSafeWorkbook($path);
        $reader = new Reader;
        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = [];
                    foreach ($row->cells as $index => $cell) {
                        if ($cell instanceof FormulaCell) {
                            throw ValidationException::withMessages(['file' => __('portal.err_wb_formula', ['row' => $number])]);
                        }
                        $value = $cell->getValue();
                        if (! is_scalar($value) && $value !== null && ! $value instanceof DateTimeInterface) {
                            throw ValidationException::withMessages(['file' => __('portal.err_wb_plain_values')]);
                        }
                        $cells[$index] = $this->text($value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value, $number);
                    }
                    $this->appendRow($rows, $number, $cells);
                }

                break;
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => __('portal.err_wb_unreadable')]);
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function assertSafeWorkbook(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => __('portal.err_wb_invalid_xlsx')]);
        }
        try {
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $expanded += $entry['size'];
                if ($expanded > 50 * 1024 * 1024 || $zip->numFiles > 2000 || preg_match('#vbaProject|externalLinks/#i', $entry['name'])) {
                    throw ValidationException::withMessages(['file' => __('portal.err_wb_macros_or_links')]);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<array{row: int, cells: list<string>}>  $rows
     * @param  array<int, string>  $cells
     */
    private function appendRow(array &$rows, int $number, array $cells): void
    {
        if (array_filter($cells, fn (string $value): bool => $value !== '') === []) {
            return;
        }
        if (count($rows) >= self::MaxRows) {
            throw ValidationException::withMessages(['file' => __('portal.err_wb_row_limit')]);
        }
        $filled = [];
        for ($index = 0, $last = max(array_keys($cells)); $index <= $last; $index++) {
            $filled[] = $cells[$index] ?? '';
        }
        $rows[] = ['row' => $number, 'cells' => $filled];
    }

    private function text(string $value, int $row): string
    {
        $value = trim(mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252'));
        if (mb_strlen($value) > self::MaxCellLength) {
            throw ValidationException::withMessages(['file' => __('portal.err_wb_cell_too_long', ['row' => $row])]);
        }

        return $value;
    }
}
