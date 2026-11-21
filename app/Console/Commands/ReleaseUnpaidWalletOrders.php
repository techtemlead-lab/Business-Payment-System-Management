<?php

namespace App\Console\Commands;

use App\Domain\Orders\WalletPartRelease;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('madina:release-unpaid-wallet-orders {--dry-run : List the orders without changing anything}')]
#[Description('Cancel "wallet + another method" orders whose rest stayed unpaid for the set days and return the wallet part')]
class ReleaseUnpaidWalletOrders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WalletPartRelease $release): int
    {
        if (! config('madina.financial_activation') || config('madina.preview_mode')) {
            $this->info('Money posting is off. No orders changed.');

            return self::SUCCESS;
        }
        $now = CarbonImmutable::now();
        $overdue = $release->overdue($now);
        if ($this->option('dry-run')) {
            $this->line(json_encode(['overdue' => $overdue, 'days' => WalletPartRelease::unpaidDays()], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $cancelled = array_values(array_filter($overdue, fn (string $orderId): bool => $release->expire($orderId, $now)));
        $this->line(json_encode(['cancelled' => $cancelled, 'days' => WalletPartRelease::unpaidDays()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
