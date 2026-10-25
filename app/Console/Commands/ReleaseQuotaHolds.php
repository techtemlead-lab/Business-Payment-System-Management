<?php

namespace App\Console\Commands;

use App\Domain\Finance\ReserveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:release-quota-holds')]
#[Description('Release area commission holds after the original period quota is met when explicitly enabled for deployment')]
class ReleaseQuotaHolds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReserveService $reserves): int
    {
        if (! config('madina.auto_release_quota_holds') || ! config('madina.financial_activation') || config('madina.preview_mode')) {
            $this->info('Automatic area-quota hold releases are disabled. No balances changed.');

            return self::SUCCESS;
        }
        $this->line(json_encode($reserves->releaseQualifiedQuotaHolds(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
