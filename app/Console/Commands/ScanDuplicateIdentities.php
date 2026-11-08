<?php

namespace App\Console\Commands;

use App\Domain\Accounts\DuplicateIdentityScan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('partners:scan-duplicates')]
#[Description('List partners who share a phone, NID or payout number, or refer themselves through another account, for the owner and finance to check (flag only)')]
class ScanDuplicateIdentities extends Command
{
    public function handle(DuplicateIdentityScan $scan): int
    {
        $result = $scan->run();
        $this->info('Matches found: '.$result['found'].'. Newly listed for review: '.$result['opened'].'.');

        return self::SUCCESS;
    }
}
