<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bank, bKash and Nagad statements checked against pending payment claims.
 *
 * This only ever suggests. It never changes a payment claim, receipt or order: staff confirm
 * each line here, then approve the payment themselves in the payments queue.
 */
final class StatementImports
{
    public const Channels = ['Bank', 'bKash', 'Nagad'];

    public const StateSuggested = 'suggested';

    public const StateMismatch = 'amount_differs';

    public const StateNoMatch = 'no_match';

    public const StateIgnored = 'ignored';

    public const StateConfirmed = 'confirmed';

    public const StateDismissed = 'dismissed';

    public const FlagInThisStatement = 'in_this_statement';

    public const FlagEarlierStatement = 'earlier_statement';

    public const FlagSeveralClaims = 'several_claims';

    public function __construct(private StatementFileReader $reader) {}

    public function upload(User $actor, string $channel, string $sourceReference, UploadedFile $file): string
    {
        $this->authorize($actor);
        $extension = Str::lower($file->getClientOriginalExtension());
        $rows = $this->reader->read($file->getRealPath(), $extension);
        $hash = hash_file('sha256', $file->getRealPath());
        if (DB::table('statement_imports')->where('file_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => __('accounting.err_file_already_uploaded')]);
        }
        $id = (string) Str::uuid();
        $path = $file->storeAs('statement-imports', $id.'.'.$extension, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => __('portal.err_import_store_failed')]);
        }
        try {
            DB::transaction(function () use ($actor, $id, $channel, $sourceReference, $file, $path, $hash, $rows): void {
                DB::table('statement_imports')->insert([
                    'id' => $id, 'channel' => $channel, 'status' => 'awaiting_mapping',
                    'filename' => Str::limit(basename($file->getClientOriginalName()), 240, ''), 'file_path' => $path, 'file_hash' => $hash,
                    'source_reference' => $sourceReference, 'raw_rows' => json_encode($rows, JSON_THROW_ON_ERROR),
                    'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                Audit::record($actor->id, 'statement_import.uploaded', $id, ['channel' => $channel, 'file_hash' => $hash, 'rows' => count($rows)]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $id;
    }

    /**
     * Apply the column mapping and work out the suggestions.
     *
     * @param  array{first_data_row: int, reference_column: int, amount_column: int, date_column: ?int, description_column: ?int}  $mapping
     */
    public function map(User $actor, string $id, array $mapping): void
    {
        DB::transaction(function () use ($actor, $id, $mapping): void {
            $this->authorize($actor->fresh());
            $import = DB::table('statement_imports')->where('id', $id)->lockForUpdate()->first();
            abort_unless($import !== null, 404);
            if ($import->status !== 'awaiting_mapping') {
                throw ValidationException::withMessages(['mapping' => __('accounting.err_already_mapped')]);
            }
            $raw = json_decode($import->raw_rows, true, 512, JSON_THROW_ON_ERROR);
            $lines = $this->normalise($raw, $mapping);
            if ($lines === []) {
                throw ValidationException::withMessages(['first_data_row' => __('accounting.err_no_rows_after_mapping')]);
            }
            $lines = $this->suggest($id, $import->channel, $lines);
            foreach (array_chunk($lines, 500) as $chunk) {
                DB::table('statement_import_lines')->insert(array_map(fn (array $line): array => [
                    ...$line, 'statement_import_id' => $id, 'flags' => json_encode($line['flags'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            $states = array_count_values(array_column($lines, 'state'));
            $summary = [
                'total' => count($lines),
                'suggested' => $states[self::StateSuggested] ?? 0,
                'amount_differs' => $states[self::StateMismatch] ?? 0,
                'no_match' => $states[self::StateNoMatch] ?? 0,
                'ignored' => $states[self::StateIgnored] ?? 0,
                'flagged' => count(array_filter($lines, fn (array $line): bool => $line['flags'] !== [])),
            ];
            DB::table('statement_imports')->where('id', $id)->update([
                'status' => 'ready', 'raw_rows' => '[]', 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'statement_import.mapped', $id, ['mapping' => $mapping, 'summary' => $summary]);
        }, 3);
    }

    /** Staff say this line and this claim are the same payment. The claim itself stays pending. */
    public function confirm(User $actor, int $lineId, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $lineId, $reason): void {
            $this->authorize($actor->fresh());
            $line = DB::table('statement_import_lines')->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line !== null, 404);
            if ($line->state !== self::StateSuggested) {
                throw ValidationException::withMessages(['line' => __('accounting.err_line_not_open')]);
            }
            $flags = json_decode((string) $line->flags, true) ?: [];
            if ($flags !== [] && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => __('accounting.err_flagged_needs_reason')]);
            }
            $claim = DB::table('payment_claims')->where('id', $line->suggested_claim_id)->first();
            if (! $claim || $claim->status !== 'pending' || Str::upper($claim->reference) !== $line->reference || Money::cents((string) $claim->amount) !== Money::cents((string) $line->amount)) {
                throw ValidationException::withMessages(['line' => __('accounting.err_claim_changed')]);
            }
            if (DB::table('statement_import_lines')->where('suggested_claim_id', $claim->id)->where('state', self::StateConfirmed)->exists()) {
                throw ValidationException::withMessages(['line' => __('accounting.err_claim_already_confirmed')]);
            }
            DB::table('statement_import_lines')->where('id', $lineId)->update([
                'state' => self::StateConfirmed, 'note' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'statement_import.match_confirmed', (string) $lineId, [
                'claim_id' => $claim->id, 'reference' => $line->reference, 'amount' => $line->amount, 'flags' => $flags, 'reason' => $reason,
            ]);
        }, 3);
    }

    public function dismiss(User $actor, int $lineId, string $reason): void
    {
        DB::transaction(function () use ($actor, $lineId, $reason): void {
            $this->authorize($actor->fresh());
            $line = DB::table('statement_import_lines')->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line !== null, 404);
            if ($line->state !== self::StateSuggested) {
                throw ValidationException::withMessages(['line' => __('accounting.err_line_not_open')]);
            }
            DB::table('statement_import_lines')->where('id', $lineId)->update([
                'state' => self::StateDismissed, 'note' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'statement_import.match_dismissed', (string) $lineId, ['claim_id' => $line->suggested_claim_id, 'reference' => $line->reference, 'reason' => $reason]);
        }, 3);
    }

    public function history(): LengthAwarePaginator
    {
        return DB::table('statement_imports')->orderByDesc('created_at')
            ->paginate(15, ['id', 'channel', 'status', 'filename', 'source_reference', 'summary', 'created_at'])
            ->withQueryString()
            ->through(function (object $import): array {
                $row = (array) $import;
                $row['summary'] = $import->summary ? json_decode($import->summary, true, 512, JSON_THROW_ON_ERROR) : null;

                return $row;
            });
    }

    /** @return array<string, mixed>|null */
    public function detail(string $id): ?array
    {
        $import = DB::table('statement_imports')->where('id', $id)->first();
        if (! $import) {
            return null;
        }
        $detail = collect((array) $import)->except(['raw_rows', 'file_path', 'file_hash', 'mapping'])->all();
        $detail['summary'] = $import->summary ? json_decode($import->summary, true, 512, JSON_THROW_ON_ERROR) : null;
        if ($import->status === 'awaiting_mapping') {
            $raw = json_decode($import->raw_rows, true, 512, JSON_THROW_ON_ERROR);
            $detail['preview'] = array_slice($raw, 0, 12);
            $detail['column_count'] = max(array_map(fn (array $row): int => count($row['cells']), array_slice($raw, 0, 50)));
            $detail['guess'] = $this->guess($raw);

            return $detail;
        }
        $detail['lines'] = DB::table('statement_import_lines as l')
            ->leftJoin('payment_claims as c', 'c.id', '=', 'l.suggested_claim_id')
            ->leftJoin('orders as o', 'o.id', '=', 'c.order_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('l.statement_import_id', $id)
            ->orderBy('l.row_number')
            ->select(['l.id', 'l.row_number', 'l.txn_date', 'l.reference', 'l.amount', 'l.description', 'l.state', 'l.flags', 'c.amount as claim_amount', 'c.status as claim_status', 'o.number as order_number', 'u.name as partner_name', 'u.dealer_code'])
            ->paginate(50, ['*'], 'line_page')
            ->withQueryString()
            ->through(function (object $line): array {
                $row = (array) $line;
                $row['flags'] = json_decode((string) $line->flags, true) ?: [];

                return $row;
            });

        return $detail;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->status === 'active' && $actor->staff() && $actor->allows('finance'), 403);
    }

    /**
     * @param  list<array{row: int, cells: list<string>}>  $raw
     * @param  array{first_data_row: int, reference_column: int, amount_column: int, date_column: ?int, description_column: ?int}  $mapping
     * @return list<array{row_number: int, txn_date: ?string, reference: ?string, amount: ?string, description: ?string, state: string, suggested_claim_id: ?int, flags: list<string>}>
     */
    private function normalise(array $raw, array $mapping): array
    {
        $lines = [];
        foreach ($raw as $row) {
            if ($row['row'] < $mapping['first_data_row']) {
                continue;
            }
            $cell = fn (?int $column): string => $column === null ? '' : ($row['cells'][$column] ?? '');
            $reference = Str::upper(trim($cell($mapping['reference_column'])));
            $amount = $this->amount($cell($mapping['amount_column']));
            $lines[] = [
                'row_number' => $row['row'],
                'txn_date' => Str::limit($cell($mapping['date_column']), 40, '') ?: null,
                'reference' => $reference === '' ? null : Str::limit($reference, 120, ''),
                'amount' => $amount,
                'description' => Str::limit($cell($mapping['description_column']), 500, '') ?: null,
                'state' => $reference === '' || $amount === null ? self::StateIgnored : self::StateNoMatch,
                'suggested_claim_id' => null,
                'flags' => [],
            ];
        }

        return $lines;
    }

    /** Money coming in only: blank, zero and negative figures return null. */
    private function amount(string $value): ?string
    {
        $value = strtr($value, array_combine(preg_split('//u', '০১২৩৪৫৬৭৮৯', -1, PREG_SPLIT_NO_EMPTY), array_map(strval(...), range(0, 9))));
        $value = trim((string) preg_replace('/(BDT|TK\.?|৳|,|\s)/iu', '', $value));
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $value) || Money::cents($value) === 0) {
            return null;
        }

        return Money::decimal(Money::cents($value));
    }

    /**
     * @param  list<array{row_number: int, txn_date: ?string, reference: ?string, amount: ?string, description: ?string, state: string, suggested_claim_id: ?int, flags: list<string>}>  $lines
     * @return list<array{row_number: int, txn_date: ?string, reference: ?string, amount: ?string, description: ?string, state: string, suggested_claim_id: ?int, flags: list<string>}>
     */
    private function suggest(string $importId, string $channel, array $lines): array
    {
        $references = array_values(array_filter(array_column($lines, 'reference')));
        $timesInFile = array_count_values($references);
        $unique = array_keys($timesInFile);
        $pending = $claimCounts = $earlier = [];
        foreach (array_chunk($unique, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::table('payment_claims')->where('status', 'pending')->where('channel', $channel)->whereRaw("UPPER(reference) IN ($marks)", $chunk)->get(['id', 'reference', 'amount']) as $claim) {
                $pending[Str::upper($claim->reference)] = $claim;
            }
            foreach (DB::table('payment_claims')->where('status', '<>', 'rejected')->whereRaw("UPPER(reference) IN ($marks)", $chunk)->selectRaw('UPPER(reference) AS reference, COUNT(*) AS uses')->groupByRaw('UPPER(reference)')->get() as $row) {
                $claimCounts[$row->reference] = (int) $row->uses;
            }
            foreach (DB::table('statement_import_lines')->where('statement_import_id', '<>', $importId)->whereIn('reference', $chunk)->distinct()->pluck('reference') as $reference) {
                $earlier[$reference] = true;
            }
        }
        foreach ($lines as &$line) {
            if ($line['reference'] === null) {
                continue;
            }
            $reference = $line['reference'];
            if (($timesInFile[$reference] ?? 0) > 1) {
                $line['flags'][] = self::FlagInThisStatement;
            }
            if (isset($earlier[$reference])) {
                $line['flags'][] = self::FlagEarlierStatement;
            }
            if (($claimCounts[$reference] ?? 0) > 1) {
                $line['flags'][] = self::FlagSeveralClaims;
            }
            if ($line['amount'] === null || ! isset($pending[$reference])) {
                continue;
            }
            $claim = $pending[$reference];
            $line['suggested_claim_id'] = $claim->id;
            $line['state'] = Money::cents((string) $claim->amount) === Money::cents($line['amount']) ? self::StateSuggested : self::StateMismatch;
        }
        unset($line);

        return $lines;
    }

    /**
     * A best guess at the header row and columns, which staff can change.
     *
     * @param  list<array{row: int, cells: list<string>}>  $raw
     * @return array{first_data_row: int, reference_column: ?int, amount_column: ?int, date_column: ?int, description_column: ?int}
     */
    private function guess(array $raw): array
    {
        $patterns = [
            'reference_column' => '/trx|txn|transaction\s*id|reference|\bref\b|রেফারেন্স/iu',
            'amount_column' => '/credit|deposit|amount|টাকা|জমা/iu',
            'date_column' => '/date|তারিখ/iu',
            'description_column' => '/desc|narration|particular|details|বিবরণ/iu',
        ];
        foreach (array_slice($raw, 0, 15) as $row) {
            $found = [];
            foreach ($patterns as $key => $pattern) {
                foreach ($row['cells'] as $index => $cell) {
                    if (preg_match($pattern, $cell)) {
                        $found[$key] = $index;
                        break;
                    }
                }
            }
            if (isset($found['reference_column'], $found['amount_column'])) {
                return ['first_data_row' => $row['row'] + 1, 'reference_column' => $found['reference_column'], 'amount_column' => $found['amount_column'], 'date_column' => $found['date_column'] ?? null, 'description_column' => $found['description_column'] ?? null];
            }
        }

        return ['first_data_row' => ($raw[0]['row'] ?? 1) + 1, 'reference_column' => null, 'amount_column' => null, 'date_column' => null, 'description_column' => null];
    }
}
