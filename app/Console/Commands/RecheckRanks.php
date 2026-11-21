<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionQualification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('madina:recheck-ranks {--apply : Promote the partners listed; without it nothing is written}')]
#[Description('List (or, with --apply, promote) partners whose rank is below what today\'s rank rules give them')]
class RecheckRanks extends Command
{
    public function handle(CommissionQualification $qualification): int
    {
        $apply = (bool) $this->option('apply');
        $changes = $qualification->recheckRanks($apply);
        foreach ($changes as $change) {
            $this->line(sprintf('%s user %d (%s): %s -> %s (%s)', $apply ? 'PROMOTED' : 'would promote', $change['user_id'], $change['role'],
                $change['from'], $change['to'], implode(', ', $change['steps'])));
        }
        $this->info(($apply ? 'Promoted: ' : 'Dry run, nothing written. Would promote: ').count($changes));

        return self::SUCCESS;
    }
}
