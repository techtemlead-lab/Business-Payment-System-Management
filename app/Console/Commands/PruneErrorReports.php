<?php

namespace App\Console\Commands;

use App\Domain\Diagnostics\ErrorTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every night. Problems not seen for the days the owner chose (90 unless changed) are deleted.
 */
#[Signature('errors:prune')]
#[Description('Delete error log entries not seen for the retention days set by the owner')]
class PruneErrorReports extends Command
{
    public function handle(ErrorTracker $tracker): int
    {
        $this->info($tracker->prune().' old error log entr(ies) deleted.');

        return self::SUCCESS;
    }
}
