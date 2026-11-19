<?php

namespace App\Console\Commands;

use App\Domain\Finance\Percentage;
use App\Models\Product;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * R4 / decision D8: the sub-dealer price is exactly 75% of MRP to the paisa. Before that rule some products were
 * saved with the 75% rounded up to a whole taka (MRP 90 saved as 68, exact 67.50). This command corrects only
 * those rows. A price the owner set on purpose (any value that is not the rounded-up 75%) is never touched, and
 * past order lines are never touched. Without --apply it only lists what it would change; running it twice is safe.
 */
#[Signature('madina:fix-sub-dealer-prices {--apply : Write the corrected prices (without it, only list them)}')]
#[Description('Correct sub-dealer prices that were saved rounded up to a whole taka, to exactly 75% of MRP.')]
class FixSubDealerPrices extends Command
{
    public function handle(): int
    {
        $rows = [];
        Product::query()->whereNotNull('mrp')->whereNotNull('sub_dealer_price')->orderBy('id')->each(function (Product $product) use (&$rows): void {
            $mrp = Money::cents((string) $product->mrp);
            $stored = Money::cents((string) $product->sub_dealer_price);
            $exact = Percentage::from('75.00')->of($mrp, 'half_up');
            $roundedUp = intdiv($exact + 99, 100) * 100;
            // Only the legacy whole-taka round-up is a mistake; every other stored price is a decision.
            if ($stored !== $exact && $stored === $roundedUp) {
                $rows[] = ['id' => $product->id, 'name' => $product->name, 'mrp' => Money::decimal($mrp), 'before' => Money::decimal($stored), 'after' => Money::decimal($exact)];
            }
        });

        if ($rows === []) {
            $this->info('Every sub-dealer price is already exactly 75% of MRP, or was set on purpose. Nothing to change.');

            return self::SUCCESS;
        }
        $this->table(['Product', 'Name', 'MRP', 'Saved', 'Exact 75%'], array_map(fn (array $row): array => array_values($row), $rows));
        if (! $this->option('apply')) {
            $this->warn(count($rows).' price(s) would change. Run again with --apply to write them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                DB::table('products')->where('id', $row['id'])->where('sub_dealer_price', $row['before'])->update(['sub_dealer_price' => $row['after'], 'updated_at' => now()]);
            }
            Audit::record(null, 'pricing.sub_dealer_price_exact', 'products', ['changed' => count($rows), 'products' => array_column($rows, 'id')]);
        });
        $this->info(count($rows).' price(s) set to exactly 75% of MRP.');

        return self::SUCCESS;
    }
}
