<?php

namespace App\Console\Commands;

use App\Support\AuditChain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:seal {--max=5000 : Most rows to seal in one run}')]
#[Description('Add new audit records to the hash chain, so any later change to them can be detected.')]
class SealAuditEvents extends Command
{
    public function handle(AuditChain $chain): int
    {
        $sealed = $chain->seal(max(1, (int) $this->option('max')));
        $this->info("Sealed {$sealed} audit records.");

        return self::SUCCESS;
    }
}
