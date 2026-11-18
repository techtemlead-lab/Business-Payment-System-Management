<?php

namespace App\Console\Commands;

use App\Domain\Finance\JournalOpeningSnapshots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs once a day. Stores each account's running total at every month start so the trial balance
 * stays quick as the journal grows. Changes no accounting figure.
 */
#[Signature('finance:snapshot-opening-balances')]
#[Description('Store month-start running totals of the journal so the trial balance stays fast')]
class SnapshotJournalOpeningBalances extends Command
{
    public function handle(JournalOpeningSnapshots $snapshots): int
    {
        $this->info($snapshots->build().' month-start snapshot(s) stored.');

        return self::SUCCESS;
    }
}
