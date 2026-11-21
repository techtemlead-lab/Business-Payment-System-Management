<?php

namespace App\Console\Commands;

use App\Domain\Finance\MissedOrderPostings;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:post-missed-orders {--apply : Post the commission of the listed orders (without it, only list them)} {--order=* : Only these order ids}')]
#[Description('List (and with --apply, post) delivered, fully paid orders whose commission was never posted because posting was off or in preview')]
class PostMissedOrders extends Command
{
    public function handle(MissedOrderPostings $missed): int
    {
        $only = array_values(array_filter(array_map('strval', (array) $this->option('order'))));
        $rows = collect(array_values(array_filter($missed->list(), fn (array $row): bool => $only === [] || in_array($row['id'], $only, true))));
        if ($rows->isEmpty()) {
            $this->info('No delivered, paid order is missing its commission. Nothing to do.');

            return self::SUCCESS;
        }
        $this->table(['Order id', 'Number', 'Amount', 'Partner', 'Code', 'Ordered at'], $rows->map(fn (array $row): array => [
            $row['id'], $row['number'], $row['total'], $row['partner'], $row['code'] ?? '', $row['created_at'],
        ])->all());
        $total = $rows->sum(fn (array $row): int => Money::cents($row['total']));
        $this->line(sprintf('%d order(s), %s in total.', $rows->count(), Money::decimal($total)));

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing was posted. Run again with --apply to post these orders.');

            return self::SUCCESS;
        }
        if (! config('madina.financial_activation') || config('madina.preview_mode')) {
            $this->error('Money posting is off or in preview mode, so nothing can be posted. Nothing changed.');

            return self::FAILURE;
        }

        $result = $missed->post($rows->pluck('id')->all());
        foreach ($result['posted'] as $line) {
            $this->line('Posted: '.$line['number'].' ('.$line['total'].')');
        }
        foreach ($result['refused'] as $line) {
            $this->warn('Not posted: '.$line['number'].' ('.$line['total'].'): '.$line['reason']);
        }
        $this->info(sprintf('%d posted, %d not posted.', count($result['posted']), count($result['refused'])));

        return $result['refused'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
