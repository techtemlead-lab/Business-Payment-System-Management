<?php

namespace App\Console\Commands;

use App\Domain\Audience\SiteVisitCounter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every night. The page-by-page visit rows are only needed for a year of detail;
 * the daily totals behind the owner's visitor count are never deleted.
 */
#[Signature('visits:prune')]
#[Description('Delete website visit rows older than a year (the daily totals are kept)')]
class PruneSiteVisits extends Command
{
    public function handle(SiteVisitCounter $counter): int
    {
        $this->info($counter->prune().' old visit row(s) deleted.');

        return self::SUCCESS;
    }
}
