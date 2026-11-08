<?php

namespace App\Console\Commands;

use App\Domain\Inventory\StockLotReports;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tells inventory staff, in the portal, about batches newly inside the near-expiry
 * window and batches that have just expired. Each lot is reported once for each.
 */
#[Signature('stock:near-expiry-notices')]
#[Description('Send a portal notice to inventory staff about near-expiry and expired stock lots')]
class NotifyNearExpiryLots extends Command
{
    private const ItemsInNotice = 5;

    public function handle(StockLotReports $reports): int
    {
        $days = $reports->nearExpiryDays();
        $today = StockLot::today();
        $limit = CarbonImmutable::parse($today)->addDays($days)->toDateString();
        $stocked = fn (): Builder => StockLot::query()->with(['product:id,name', 'branch:id,code'])->where('quantity', '>', 0)->whereNotNull('expires_on')->orderBy('expires_on');

        $near = $stocked()->whereNull('near_expiry_noticed_at')->whereBetween('expires_on', [$today, $limit])->get();
        $expired = $stocked()->whereNull('expired_noticed_at')->where('expires_on', '<', $today)->get();

        if ($near->isNotEmpty()) {
            $this->notifyInventoryStaff('stock_lots.notice_near_expiry_title', 'stock_lots.notice_near_expiry_body', $near, ['days' => $days]);
            StockLot::query()->whereKey($near->modelKeys())->update(['near_expiry_noticed_at' => now()]);
        }
        if ($expired->isNotEmpty()) {
            $this->notifyInventoryStaff('stock_lots.notice_expired_title', 'stock_lots.notice_expired_body', $expired, []);
            StockLot::query()->whereKey($expired->modelKeys())->update(['expired_noticed_at' => now()]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     * @param  array<string, int>  $replace
     */
    private function notifyInventoryStaff(string $titleKey, string $bodyKey, Collection $lots, array $replace): void
    {
        $items = $lots->take(self::ItemsInNotice)->map(fn (StockLot $lot): string => sprintf('%s (%s, %s)', $lot->product?->name, $lot->batch_number ?? '-', $lot->branch?->code))->implode('; ');

        User::query()->where('status', 'active')->whereNotIn('role', ['dealer', 'sub_dealer'])->chunkById(500, function (Collection $users) use ($titleKey, $bodyKey, $lots, $items, $replace): void {
            foreach ($users as $user) {
                if ($user->allows('inventory')) {
                    $locale = $user->preferredLocale();
                    Audit::notice($user->id, __($titleKey, [], $locale), __($bodyKey, ['count' => $lots->count(), 'items' => $items, ...$replace], $locale));
                }
            }
        });
    }
}
