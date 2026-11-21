<?php

namespace App\Console\Commands;

use App\Domain\Geography\PlaceDataFixes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a place table built from the older default list. Shows what it would change and changes
 * nothing unless --apply is given; safe to repeat. madina:seed-place-codes does the same corrections
 * before it fills the list, so this command is for looking first.
 */
class FixPlaceData extends Command
{
    protected $signature = 'madina:fix-place-data {--apply : Make the changes (without it nothing is saved)}';

    protected $description = 'Move unions under the right upazila, switch off duplicate union copies and clear base places of metropolitan areas. Dry run unless --apply is given.';

    public function handle(PlaceDataFixes $fixes): int
    {
        $apply = (bool) $this->option('apply');

        DB::beginTransaction();
        try {
            $rows = $fixes->apply();
            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->info($apply ? 'Corrections made.' : 'DRY RUN: nothing was saved. Run again with --apply to make these changes.');
        $counts = array_count_values(array_column($rows, 'status'));
        ksort($counts);
        $this->line('Status: '.implode(', ', array_map(fn (string $status, int $count): string => $count.' '.$status, array_keys($counts), $counts)).'.');
        $shown = array_values(array_filter($rows, fn (array $r): bool => in_array($r['status'], ['done', 'referenced', 'skipped'], true)));
        if ($shown !== []) {
            $this->table(['Step', 'Code', 'Place', 'Status', 'Detail'], array_map(fn (array $r): array => [$r['step'], $r['code'], $r['name'], $r['status'], $r['detail']], $shown));
        }

        return self::SUCCESS;
    }
}
