<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs every night. A notice nobody opened in 30 days is marked read, so the bell shows what is
 * new instead of a count that never goes down. Nothing is deleted; the notice stays in the list.
 */
#[Signature('notices:read-old {--days=30 : Notices older than this many days are marked read}')]
#[Description('Mark notices older than 30 days as read so the bell count stays meaningful')]
class ReadOldNotices extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $marked = DB::table('account_notices')
            ->whereNull('read_at')
            ->where('created_at', '<', now()->subDays($days))
            ->update(['read_at' => now(), 'updated_at' => now()]);
        $this->info($marked.' old notice(s) marked read.');

        return self::SUCCESS;
    }
}
