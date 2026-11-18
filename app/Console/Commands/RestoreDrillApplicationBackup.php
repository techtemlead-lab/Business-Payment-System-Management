<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

#[Signature('app:backup-restore-drill {file? : Backup filename on the backup disk; the newest backup when left out} {--keep : Keep the scratch database afterwards for a closer look}')]
#[Description('Restore a backup into a scratch database, check it against the live books, then remove the scratch copy')]
class RestoreDrillApplicationBackup extends Command
{
    /** Where the result of the last drill is kept, beside the backups (read by app:readiness). */
    public const RecordFile = 'restore-drill-last.json';

    /** Tables whose row counts are shown side by side, so a person can see the copy is the real business. */
    private const KeyTables = ['users', 'orders', 'order_lines', 'payment_claims', 'withdrawals', 'wallets', 'journal_entries', 'journal_lines', 'money_events', 'audit_events', 'products'];

    /**
     * The live database is only read. The backup is restored into a new database on the same server
     * (the database user needs permission to create databases); the host script infra/ops/restore-drill.sh
     * does the same drill in a throwaway PostgreSQL container when it does not.
     */
    public function handle(): int
    {
        set_time_limit(0);
        $disk = Storage::disk((string) config('madina.backup_disk'));
        $name = (string) ($this->argument('file') ?: collect($disk->files())
            ->filter(fn (string $file): bool => (bool) preg_match('/^madina-\d{8}-\d{6}-[0-9a-f-]{36}\.zip$/', $file))
            ->sortDesc()->first());
        if ($name === '') {
            $this->error('There is no backup to restore. Run php artisan app:backup first.');

            return self::FAILURE;
        }
        if ($this->call('app:backup-verify', ['file' => $name]) !== self::SUCCESS) {
            return $this->finish($name, false, ['problem' => 'The backup did not pass app:backup-verify.']);
        }

        $base = config('database.connections.'.config('database.default'));
        if (($base['driver'] ?? null) !== 'pgsql') {
            $this->error('PostgreSQL is required.');

            return self::FAILURE;
        }
        $scratch = 'madina_restore_drill_'.now()->utc()->format('Ymd_His');
        config([
            'database.connections.restore_drill_admin' => $base,
            'database.connections.restore_drill' => array_merge($base, ['database' => $scratch]),
        ]);
        $dump = tempnam(sys_get_temp_dir(), 'madina-restore-drill-');
        $created = false;
        try {
            $this->extractDump($disk->path($name), $dump);
            DB::connection('restore_drill_admin')->statement('CREATE DATABASE "'.$scratch.'"');
            $created = true;
            $started = microtime(true);
            $restore = Process::env(['PGPASSWORD' => (string) $base['password']])->timeout(1800)->run([
                'pg_restore', '-h', (string) $base['host'], '-p', (string) $base['port'], '-U', (string) $base['username'],
                '-d', $scratch, '--no-owner', '--no-acl', '--exit-on-error', $dump,
            ]);
            $restoreSeconds = round(microtime(true) - $started, 1);
            if (! $restore->successful()) {
                return $this->finish($name, false, ['problem' => 'pg_restore failed.', 'pg_restore' => mb_substr(trim($restore->errorOutput()), 0, 500)]);
            }

            [$passed, $facts] = $this->compare(DB::connection('restore_drill'), DB::connection());

            return $this->finish($name, $passed, ['restore_seconds' => $restoreSeconds, ...$facts]);
        } catch (Throwable $error) {
            report($error);

            return $this->finish($name, false, ['problem' => $error->getMessage()]);
        } finally {
            DB::purge('restore_drill');
            if ($created && ! $this->option('keep')) {
                DB::connection('restore_drill_admin')->statement('DROP DATABASE IF EXISTS "'.$scratch.'"');
            } elseif ($created) {
                $this->warn("Scratch database {$scratch} was kept. Drop it when done: DROP DATABASE \"{$scratch}\";");
            }
            DB::purge('restore_drill_admin');
            @unlink($dump);
        }
    }

    private function extractDump(string $archive, string $target): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('Cannot open the backup archive.');
        }
        try {
            $zip->setPassword((string) config('madina.backup_password'));
            $source = $zip->getStream('database.dump');
            $copy = fopen($target, 'wb');
            if ($source === false || $copy === false) {
                throw new \RuntimeException('Cannot read the database dump from the backup.');
            }
            stream_copy_to_stream($source, $copy);
            fclose($source);
            fclose($copy);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function compare(Connection $copy, Connection $live): array
    {
        $tables = fn (Connection $connection): array => array_map(fn ($row): string => $row->tablename,
            $connection->select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'));
        $copyTables = $tables($copy);
        $liveTables = $tables($live);
        $problems = [];
        if (! in_array('migrations', $copyTables, true)) {
            $problems[] = 'The restored copy has no migrations table.';

            return [false, ['problems' => $problems, 'tables' => count($copyTables)]];
        }
        $copyMigrations = $copy->table('migrations')->pluck('migration')->all();
        $liveMigrations = $live->table('migrations')->pluck('migration')->all();
        if ($copyMigrations === []) {
            $problems[] = 'The restored copy has no migrations recorded.';
        }
        if (array_diff($copyMigrations, $liveMigrations) !== []) {
            $problems[] = 'The restored copy has migrations the live database does not know.';
        }
        $missing = array_values(array_diff($liveTables, $copyTables));
        // A table added by a deploy after the backup is expected to be missing; any other gap is not.
        if ($missing !== [] && count($copyMigrations) >= count($liveMigrations)) {
            $problems[] = 'Tables missing from the restored copy: '.implode(', ', $missing);
        }
        $unbalanced = null;
        if (in_array('journal_lines', $copyTables, true)) {
            $unbalanced = (string) $copy->selectOne('SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS difference FROM journal_lines')->difference;
            if ((float) $unbalanced !== 0.0) {
                $problems[] = 'The restored journal does not balance (debits minus credits = '.$unbalanced.').';
            }
        }
        $rows = [];
        foreach (self::KeyTables as $table) {
            if (in_array($table, $copyTables, true) && in_array($table, $liveTables, true)) {
                $rows[$table] = ['backup' => $copy->table($table)->count(), 'live_now' => $live->table($table)->count()];
            }
        }
        $this->table(['Table', 'In the backup', 'Live now'], collect($rows)->map(fn (array $count, string $table): array => [$table, $count['backup'], $count['live_now']])->values()->all());
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return [$problems === [], [
            'problems' => $problems,
            'tables' => count($copyTables),
            'migrations' => count($copyMigrations),
            'missing_tables_added_since' => $missing,
            'journal_difference' => $unbalanced,
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function finish(string $file, bool $passed, array $facts): int
    {
        $record = ['file' => $file, 'ran_at' => now()->utc()->toIso8601String(), 'passed' => $passed, 'by' => 'app:backup-restore-drill', ...$facts];
        try {
            Storage::disk((string) config('madina.backup_disk'))->put(self::RecordFile, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            report($error);
        }
        if ($passed) {
            Log::info('backup.restore_drill.passed', $record);
            $this->info('Restore drill passed: '.$file.' came back in '.($facts['restore_seconds'] ?? '?').' seconds and matches the live books.');

            return self::SUCCESS;
        }
        Log::critical('Backup restore drill failed. The backups may not be usable.', ['file' => $file, 'problem' => $facts['problem'] ?? ($facts['problems'] ?? null)]);
        $this->error('Restore drill failed. See the record '.self::RecordFile.' on the backup disk.');

        return self::FAILURE;
    }
}
