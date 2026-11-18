<?php

namespace Database\Seeders;

use App\Domain\Geography\DefaultPlaceCatalog;
use Illuminate\Database\Seeder;

/**
 * Loads the default Bangladesh place list with its default codes (MNHC100, MNHC101…).
 *
 * Owner decision (2026-11-17): every install starts with the full list. It only fills in what
 * is missing, so places and codes the office already set are kept; admins can still change any
 * place or code afterwards in the Place codes panel.
 */
class PlaceCodeSeeder extends Seeder
{
    public function run(DefaultPlaceCatalog $catalog): void
    {
        $summary = $catalog->load();

        $this->command?->info(sprintf(
            'Default place list: %d places added, %d given their default code, %d base links set%s.',
            $summary['created'],
            $summary['coded'],
            $summary['linked'],
            $summary['codes_taken'] === [] ? '' : ', '.count($summary['codes_taken']).' default codes already in use (see the log)',
        ));
    }
}
