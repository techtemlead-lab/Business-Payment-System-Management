<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('ops:heartbeat')]
#[Description('Check the database, queue worker, disk space and backups, then ping the dead-man switch')]
class SendOperationsHeartbeat extends Command
{
    /** A nightly backup older than this means last night's run did not happen or failed. */
    public const BackupMaxAgeHours = 26;

    /**
     * Runs every five minutes from the scheduler. Each problem is logged as critical (which reaches a person
     * once alerts are set up), and the outside heartbeat check is told "fail"; when everything is fine it is
     * told "ok". If the server, the scheduler or this command stops, the pings stop and that service alerts.
     */
    public function handle(): int
    {
        $problems = [];
        try {
            DB::select('SELECT 1');
        } catch (Throwable $error) {
            $problems['database'] = ['The database does not answer.', ['error' => $error->getMessage()]];
        }
        if (! in_array(config('queue.default'), ['sync', 'null', ''], true) && ! $this->cacheHas('operations:queue-heartbeat')) {
            $problems['queue'] = ['The background job worker has not checked in for 5 minutes. Payments, emails and imports wait.', []];
        }
        foreach ($this->diskPaths() as $label => $path) {
            $used = $this->usedPercent($path);
            if ($used !== null && $used >= (int) config('madina.disk_alert_percent')) {
                $problems['disk_'.$label] = ['The server disk is almost full ('.$label.'). Free space before uploads and backups fail.', ['used_percent' => $used, 'path' => $path]];
            }
        }
        if (config('madina.backups_enabled')) {
            $ageHours = $this->newestBackupAgeHours();
            if ($ageHours === null || $ageHours > self::BackupMaxAgeHours) {
                $problems['backup'] = ['No backup from the last night. Check the 02:00 backup run.', ['newest_backup_age_hours' => $ageHours]];
            }
        }

        foreach ($problems as [$message, $context]) {
            Log::critical($message, $context);
            $this->error($message);
        }
        $this->ping($problems === [] ? '' : '/fail', implode("\n", array_column($problems, 0)));
        if ($problems === []) {
            $this->info('All checks passed.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function diskPaths(): array
    {
        $paths = ['uploads' => storage_path()];
        try {
            $paths['backups'] = Storage::disk((string) config('madina.backup_disk'))->path('');
        } catch (Throwable) {
            // No backup disk configured; the uploads disk is still checked.
        }

        return $paths;
    }

    private function usedPercent(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return null;
        }

        return (int) round(($total - $free) / $total * 100);
    }

    private function newestBackupAgeHours(): ?float
    {
        try {
            $disk = Storage::disk((string) config('madina.backup_disk'));
            $newest = collect($disk->files())
                ->filter(fn (string $file): bool => (bool) preg_match('/^madina-\d{8}-\d{6}-[0-9a-f-]{36}\.zip$/', $file))
                ->map(fn (string $file): int => $disk->lastModified($file))
                ->max();
        } catch (Throwable) {
            return null;
        }

        return $newest === null ? null : round((time() - $newest) / 3600, 1);
    }

    private function cacheHas(string $key): bool
    {
        try {
            return Cache::has($key);
        } catch (Throwable) {
            return false;
        }
    }

    private function ping(string $suffix, string $body): void
    {
        $url = rtrim((string) config('madina.heartbeat_url'), '/');
        if ($url === '') {
            return;
        }
        try {
            Http::timeout(10)->connectTimeout(5)->withBody($body, 'text/plain')->post($url.$suffix)->throw();
        } catch (Throwable $error) {
            Log::warning('Heartbeat ping could not be sent.', ['error' => $error->getMessage()]);
        }
    }
}
