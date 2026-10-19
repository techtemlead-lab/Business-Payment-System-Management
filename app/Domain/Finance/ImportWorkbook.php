<?php

namespace App\Domain\Finance;

use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneIdentity;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

final class ImportWorkbook
{
    public const Columns = [
        'partners' => ['dealer_code', 'name', 'email', 'phone', 'role', 'sponsor_code', 'division', 'district'],
        'products' => ['sku', 'name', 'category', 'unit', 'mrp', 'dealer_price', 'sub_dealer_price', 'cost_price', 'stock', 'low_stock_at'],
        'opening_balances' => ['dealer_code', 'reference', 'as_of_date', 'available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'],
    ];

    /** @return list<array{row: int, data: array<string, string>}> */
    public function read(string $path, string $type): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Upload a valid, unencrypted .xlsx workbook using the template.']);
        }
        try {
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $expanded += $entry['size'];
                if ($expanded > 50 * 1024 * 1024 || $zip->numFiles > 2000 || preg_match('#vbaProject|externalLinks/#i', $entry['name'])) {
                    throw ValidationException::withMessages(['file' => 'The workbook is too large or contains macros or external links. Use a plain template workbook.']);
                }
            }
        } finally {
            $zip->close();
        }
        $reader = new Reader;
        $rows = [];
        $found = false;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Data') {
                    continue;
                }
                $found = true;
                $headers = null;
                foreach ($sheet->getRowIterator() as $number => $row) {
                    foreach ($row->cells as $cell) {
                        if ($cell instanceof FormulaCell) {
                            throw ValidationException::withMessages(['file' => 'Row '.$number.' contains a formula. Paste values only before uploading.']);
                        }
                    }
                    $values = [];
                    foreach ($row->cells as $index => $cell) {
                        $value = $cell->getValue();
                        if (! is_scalar($value) && $value !== null && ! $value instanceof DateTimeInterface) {
                            throw ValidationException::withMessages(['file' => 'Use plain text, numbers and dates in the Data worksheet.']);
                        }
                        $values[$index] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value);
                        if (mb_strlen($values[$index]) > 2000) {
                            throw ValidationException::withMessages(['file' => 'Row '.$number.' contains a cell longer than 2,000 characters. Shorten the value before uploading.']);
                        }
                    }
                    if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn (string $value): string => Str::lower(trim($value)), $values);
                        if (count($headers) !== count(array_unique($headers)) || array_diff(self::Columns[$type], $headers) || array_diff($headers, self::Columns[$type])) {
                            throw ValidationException::withMessages(['file' => 'Keep the template column names unchanged. Columns may be reordered.']);
                        }
                        $headers = array_values($headers);

                        continue;
                    }
                    if (count($rows) >= 5000 || ($values !== [] && max(array_keys($values)) >= count($headers))) {
                        throw ValidationException::withMessages(['file' => 'Use at most 5,000 data rows and only the template columns per workbook.']);
                    }
                    $data = [];
                    foreach ($headers as $index => $header) {
                        $data[$header] = $values[$index] ?? '';
                    }
                    $rows[] = ['row' => $number, 'data' => $data];
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'The workbook could not be read. Save it as a plain .xlsx file using the template.']);
        } finally {
            $reader->close();
        }
        if (! $found || $rows === []) {
            throw ValidationException::withMessages(['file' => 'Add records to the Data worksheet before uploading.']);
        }

        return $rows;
    }

    /** @param list<array{row: int, data: array<string, string>}> $rows @return array{array<mixed>, array<string, mixed>} */
    public function validate(string $type, array $rows): array
    {
        $result = [];
        $seen = [];
        $summary = ['total_rows' => count($rows), 'valid_rows' => 0, 'invalid_rows' => 0, 'available_commission' => 0, 'held_commission' => 0, 'historical_order_total' => 0, 'historical_order_paid' => 0];
        foreach ($rows as $row) {
            $data = $row['data'];
            foreach ($data as $key => $value) {
                if (in_array($key, ['dealer_code', 'sponsor_code', 'sku', 'reference'], true)) {
                    $data[$key] = Str::upper($value);
                }
                if ($key === 'email') {
                    $data[$key] = Str::lower($value);
                }
                if (in_array($key, ['mrp', 'dealer_price', 'sub_dealer_price', 'cost_price', 'available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'], true)) {
                    $value = strtr($value, array_combine(preg_split('//u', '০১২৩৪৫৬৭৮৯', -1, PREG_SPLIT_NO_EMPTY), array_map(strval(...), range(0, 9))));
                    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/D', $value)) {
                        $value = str_replace(',', '', $value);
                    }
                    $data[$key] = $value === '' ? '0.00' : $value;
                }
            }
            $money = ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'];
            $rules = match ($type) {
                'partners' => ['dealer_code' => 'required|regex:/^[A-Z0-9][A-Z0-9_-]{1,39}$/D|unique:users,dealer_code', 'name' => 'required|string|max:150',
                    'email' => 'required|email|max:255|unique:users,email', 'phone' => 'required|string|max:30', 'role' => 'required|in:dealer,sub_dealer',
                    'sponsor_code' => 'nullable|string|max:40', 'division' => 'nullable|string|max:80', 'district' => 'nullable|string|max:80'],
                'products' => ['sku' => 'required|regex:/^[A-Z0-9][A-Z0-9_-]{1,63}$/D|unique:products,sku', 'name' => 'required|string|max:255',
                    'category' => 'required|string|max:255', 'unit' => 'required|string|max:30', 'mrp' => [...$money, 'gt:0'],
                    'dealer_price' => [...$money, 'gt:0', 'lte:mrp'], 'sub_dealer_price' => [...$money, 'gte:dealer_price', 'lte:mrp'],
                    'cost_price' => [...$money, 'lte:dealer_price'], 'stock' => 'required|integer|min:0|max:1000000', 'low_stock_at' => 'required|integer|min:0|max:1000000'],
                default => ['dealer_code' => 'required|string|max:40', 'reference' => 'required|regex:/^[A-Z0-9][A-Z0-9_-]{2,79}$/D|unique:opening_balance_entries,reference',
                    'as_of_date' => 'required|date_format:Y-m-d|before_or_equal:'.now()->timezone('Asia/Dhaka')->toDateString(),
                    'available_commission' => $money, 'held_commission' => $money, 'historical_order_total' => $money, 'historical_order_paid' => [...$money, 'lte:historical_order_total']],
            };
            $errors = Validator::make($data, $rules)->errors()->all();
            foreach (match ($type) {
                'partners' => ['dealer_code', 'email', 'phone'], 'products' => ['sku'], default => ['dealer_code', 'reference']
            } as $key) {
                $value = $key === 'phone' ? PhoneIdentity::normalize($data[$key]) : $data[$key];
                if ($value !== '' && $value !== null && isset($seen[$key][$value])) {
                    $errors[] = $key.' duplicates row '.$seen[$key][$value].'.';
                }
                $seen[$key][$value ?? ''] = $row['row'];
            }
            if ($type === 'partners') {
                $phone = PhoneIdentity::normalize($data['phone']);
                if ($phone === null || User::whereRaw(PhoneIdentity::SQL.' = ?', [$phone])->exists()) {
                    $errors[] = 'Phone must be valid and must not belong to an existing account.';
                } else {
                    $data['phone'] = $phone;
                }
                if ($data['sponsor_code'] !== '' && ! User::where('dealer_code', $data['sponsor_code'])->where('role', 'dealer')->where('status', 'active')->exists()) {
                    $errors[] = 'Sponsor must be an existing active dealer. Import sponsors in an earlier workbook.';
                }
            }
            if ($type === 'opening_balances') {
                $partner = User::where('dealer_code', $data['dealer_code'])->whereIn('role', ['dealer', 'sub_dealer'])->first();
                if (! $partner) {
                    $errors[] = 'Partner code was not found. Import partner accounts first.';
                } elseif (DB::table('opening_balance_entries')->where('user_id', $partner->id)->exists()
                    || DB::table('journal_lines')->where('user_id', $partner->id)->exists() || Order::where('user_id', $partner->id)->exists()
                    || DB::table('wallets')->where('user_id', $partner->id)->where(fn ($q) => $q->where('available', '>', 0)->orWhere('held', '>', 0))->exists()) {
                    $errors[] = 'This partner already has opening entries or financial activity. Existing records cannot be overwritten.';
                }
            }
            if ($errors === []) {
                $summary['valid_rows']++;
                foreach (['available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'] as $key) {
                    $summary[$key] += Money::cents($data[$key] ?? '0.00');
                }
            } else {
                $summary['invalid_rows']++;
            }
            $result[] = ['row' => $row['row'], 'data' => $data, 'errors' => $errors];
        }
        foreach (['available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'] as $key) {
            $summary[$key] = Money::decimal($summary[$key]);
        }

        return [$result, $summary];
    }
}
