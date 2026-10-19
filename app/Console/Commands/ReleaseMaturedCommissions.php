<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionSettlement;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:release-matured')]
#[Description('Release already earned time-based holds when explicitly enabled for deployment')]
class ReleaseMaturedCommissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CommissionSettlement $settlements): int
    {
        if (! config('madina.auto_release_matured') || ! config('madina.financial_activation') || config('madina.preview_mode')) {
            $this->info('Automatic maturity releases are disabled. No balances changed.');

            return self::SUCCESS;
        }
        $this->line(json_encode($settlements->release(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
