<?php

namespace App\Console\Commands;

use App\Domain\Geography\DefaultPlaceCatalog;
use Illuminate\Console\Command;

/**
 * Gives every place in the default Bangladesh list its default code (fill-only, safe to repeat).
 */
class SeedPlaceCodes extends Command
{
    protected $signature = 'madina:seed-place-codes {--dry-run : Show what would be added without saving anything}';

    protected $description = 'Add the missing places and default place codes (100, 101…, 201…, 1001…, 100001…). Never changes an existing code.';

    public function handle(DefaultPlaceCatalog $catalog): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $catalog->load($dryRun);

        $this->info($dryRun ? 'DRY RUN: nothing was saved.' : 'Default place codes added.');
        $rows = [];
        foreach ($summary['by_level'] as $level => $counts) {
            $rows[] = [$level, $counts['created'], $counts['coded'], $counts['taken'], $counts['other_code']];
        }
        $this->table(['Level', 'Places added', 'Existing places given a code', 'Skipped (code taken)', 'Kept own code'], $rows);
        $this->line(sprintf(
            'Total: %d added, %d given a code, %d names/parents filled, %d base links set, %d skipped.',
            $summary['created'],
            $summary['coded'],
            $summary['filled'],
            $summary['linked'],
            count($summary['conflicts']),
        ));

        if ($summary['conflicts'] !== []) {
            $this->warn('Default codes already held by another place (those places were left without a code):');
            $this->table(['Default code', 'Level', 'Place', 'Held by'], array_map(
                fn (array $c): array => [$c['code'], $c['level'], $c['name'], $c['held_by']],
                $summary['conflicts'],
            ));
        }

        return self::SUCCESS;
    }
}
