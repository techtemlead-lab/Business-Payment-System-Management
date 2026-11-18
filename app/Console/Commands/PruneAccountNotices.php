<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs every night. Notices are messages for people, not accounting records (the ledger and the audit
 * log keep the money truth), so old ones are removed to keep the table and the backups small.
 * Notices the person has read go after 180 days; unread ones are kept much longer (400 days) so a
 * partner who was away does not lose something they never saw. Rows go in small batches so the
 * delete never holds a long lock.
 */
#[Signature('notices:prune {--read-days=180 : Days to keep notices that were read} {--unread-days=400 : Days to keep notices never read} {--chunk=5000 : Rows deleted per batch}')]
#[Description('Delete old account notices (read ones after 180 days, unread after 400 days)')]
class PruneAccountNotices extends Command
{
    public function handle(): int
    {
        $readDays = max(30, (int) $this->option('read-days'));
        $unreadDays = max($readDays, (int) $this->option('unread-days'));
        $chunk = max(100, (int) $this->option('chunk'));

        $deleted = $this->prune(fn ($query) => $query->whereNotNull('read_at')->where('created_at', '<', now()->subDays($readDays)), $chunk)
            + $this->prune(fn ($query) => $query->whereNull('read_at')->where('created_at', '<', now()->subDays($unreadDays)), $chunk);
        $this->info($deleted.' old notice(s) deleted.');

        return self::SUCCESS;
    }

    /** @param  callable(Builder): mixed  $scope */
    private function prune(callable $scope, int $chunk): int
    {
        $total = 0;
        do {
            $ids = DB::table('account_notices');
            $scope($ids);
            $ids = $ids->orderBy('id')->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += DB::table('account_notices')->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $total;
    }
}
