<?php

namespace App\Console\Commands;

use App\Domain\Geography\DefaultPlaceCatalog;
use Illuminate\Console\Command;

/**
 * Gives every place in the default Bangladesh list its default code and, where empty, its default base place (fill-only, safe to repeat).
 */
class SeedPlaceCodes extends Command
{
    protected $signature = 'madina:seed-place-codes {--dry-run : Show what would be added without saving anything}';

    protected $description = 'Add the missing places, default place codes (100, 101…, 201…, 1001…, 100001…) and empty base places. Never changes an existing code or base place.';

    public function handle(DefaultPlaceCatalog $catalog): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $catalog->load($dryRun);

        $this->info($dryRun ? 'DRY RUN: nothing was saved.' : 'Default place codes added.');
        $rows = [];
        foreach ($summary['by_level'] as $level => $counts) {
            $rows[] = [$level, $counts['created'], $counts['coded'], $counts['taken'], $counts['other_code'], $counts['base_set']];
        }
        $this->table(['Level', 'Places added', 'Existing places given a code', 'Skipped (code taken)', 'Kept own code', 'Base places set'], $rows);
        $this->line(sprintf(
            'Total: %d added, %d given a code, %d names/parents filled, %d base places set, %d skipped.',
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

        if ($summary['base_empty'] !== []) {
            $reasons = array_count_values(array_column($summary['base_empty'], 'reason'));
            ksort($reasons);
            $this->warn(sprintf('%d places have no base place and none could be chosen safely (the office can choose one in the Place codes panel):', count($summary['base_empty'])));
            $this->table(['Reason', 'Places'], array_map(fn (string $reason, int $count): array => [$reason, $count], array_keys($reasons), $reasons));
            if ($this->getOutput()->isVerbose()) {
                $this->table(['Code', 'Level', 'Place', 'Reason'], array_map(
                    fn (array $e): array => [$e['code'], $e['level'], $e['name'], $e['reason']],
                    $summary['base_empty'],
                ));
            } else {
                $this->line('Run with -v to list every place.');
            }
        }

        return self::SUCCESS;
    }
}
