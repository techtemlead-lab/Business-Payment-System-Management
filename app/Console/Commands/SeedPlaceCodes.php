<?php

namespace App\Console\Commands;

use App\Domain\Geography\DefaultPlaceCatalog;
use Illuminate\Console\Command;

/**
 * Gives every place in the default Bangladesh list its default code and, where empty, its default base place (fill-only, safe to repeat).
 */
class SeedPlaceCodes extends Command
{
    protected $signature = 'madina:seed-place-codes {--dry-run : Show what would be added without saving anything}
        {--review-csv= : Write the upazilas left without a base place, with the headquarters found in public sources, to this CSV file}';

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
                $this->table(['Code', 'Level', 'Place', 'Reason', 'Children (candidates)'], array_map(
                    fn (array $e): array => [$e['code'], $e['level'], $e['name'], $e['reason'], implode(', ', $e['candidates'])],
                    $summary['base_empty'],
                ));
            } else {
                $this->line('Run with -v to list every place.');
            }
        }

        if ($summary['base_set_list'] !== [] && $this->getOutput()->isVerbose()) {
            $this->info('Base places set (place → chosen base, with the rule that matched):');
            $this->table(['Code', 'Level', 'Place', 'Base chosen', 'Rule'], array_map(
                fn (array $r): array => [$r['code'], $r['level'], $r['name'], $r['base'], $r['rule']],
                $summary['base_set_list'],
            ));
        }

        $csv = $this->option('review-csv');
        if (is_string($csv) && $csv !== '') {
            $this->writeReviewCsv($csv, $summary['base_empty'], $catalog->headquartersReview());
            $this->info('Review file written: '.$csv);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{code: string, level: string, name: string, reason: string, candidates: list<string>, parent: string}>  $empty
     * @param  array<string, array{hq_union: ?string, source_url: ?string, confidence: string, note: ?string}>  $found
     */
    private function writeReviewCsv(string $path, array $empty, array $found): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error('Cannot write '.$path);

            return;
        }
        fputcsv($handle, ['upazila', 'district', 'headquarters union found', 'candidates', 'source URL', 'confidence', 'reason', 'note'], ',', '"', '');
        foreach ($empty as $item) {
            if ($item['level'] !== 'upazila') {
                continue;
            }
            $hq = $found[$item['code']] ?? null;
            fputcsv($handle, [$item['name'], $item['parent'], $hq['hq_union'] ?? '', implode('; ', $item['candidates']), $hq['source_url'] ?? '', $hq['confidence'] ?? '', $item['reason'], $hq['note'] ?? ''], ',', '"', '');
        }
        fclose($handle);
    }
}
