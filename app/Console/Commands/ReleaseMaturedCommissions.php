<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\FinanceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:release-matured')]
#[Description('Pay commission whose cooling-off days are over, while the owner leaves automatic release on')]
class ReleaseMaturedCommissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CommissionSettlement $settlements, FinanceSettings $settings): int
    {
        if (! $settings->autoReleaseMatured()['enabled'] || ! config('madina.financial_activation') || config('madina.preview_mode')) {
            $this->info('Automatic maturity releases are disabled. No balances changed.');

            return self::SUCCESS;
        }
        $this->line(json_encode($settlements->release(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
