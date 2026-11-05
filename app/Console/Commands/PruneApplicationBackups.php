<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('app:backup-prune {--dry-run : List what would be deleted without deleting}')]
#[Description('Delete local backups older than the retention period, always keeping the newest ones')]
class PruneApplicationBackups extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = max(1, (int) config('madina.backup_retention_days'));
        $keepMinimum = max(1, (int) config('madina.backup_keep_minimum'));
        $disk = Storage::disk(config('madina.backup_disk'));
        $verified = Cache::get('operations:verified-backup')['file'] ?? null;

        $backups = collect($disk->files())
            ->filter(fn (string $file): bool => (bool) preg_match('/^madina-\d{8}-\d{6}-[0-9a-f-]{36}\.zip$/', $file))
            ->sortDesc()
            ->values();
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $expired = $backups->slice($keepMinimum)
            ->filter(fn (string $file): bool => $file !== $verified && $disk->lastModified($file) < $cutoff)
            ->values();

        foreach ($expired as $file) {
            if ($this->option('dry-run')) {
                $this->line('Would delete '.$file);

                continue;
            }
            $disk->delete($file);
            $this->line('Deleted '.$file);
        }

        $kept = $backups->count() - ($this->option('dry-run') ? 0 : $expired->count());
        if (! $this->option('dry-run')) {
            Log::info('backup.pruned', ['deleted' => $expired->count(), 'kept' => $kept, 'retention_days' => $retentionDays]);
        }
        $this->info("Kept {$kept} backup(s); retention {$retentionDays} days, never fewer than {$keepMinimum}.");

        return self::SUCCESS;
    }
}
