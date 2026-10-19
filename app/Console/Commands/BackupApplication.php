<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

#[Signature('app:backup')]
#[Description('Create an encrypted PostgreSQL and uploaded-files backup with integrity metadata')]
class BackupApplication extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        set_time_limit(0);
        $deadline = microtime(true) + 900;
        $password = (string) config('madina.backup_password');
        if (strlen($password) < 32) {
            $this->error('Configure MADINA_BACKUP_PASSWORD with at least 32 characters in the deployment secret store.');

            return self::FAILURE;
        }
        $connection = DB::connection()->getConfig();
        if ($connection['driver'] !== 'pgsql') {
            $this->error('PostgreSQL is required.');

            return self::FAILURE;
        }
        $work = 'backup-work/'.Str::uuid();
        Storage::disk('local')->makeDirectory($work);
        $dump = Storage::disk('local')->path($work.'/database.dump');
        $archive = Storage::disk('local')->path($work.'/backup.zip');
        $name = 'madina-'.now()->utc()->format('Ymd-His').'-'.Str::uuid().'.zip';
        try {
            DB::transaction(function () use ($connection, $password, $dump, $archive, $name, $deadline): void {
                DB::select('SELECT pg_advisory_xact_lock(?)', [81416004]);
                $result = Process::env(['PGPASSWORD' => (string) $connection['password']])->timeout(600)->run([
                    'pg_dump', '-h', $connection['host'], '-p', (string) $connection['port'], '-U', $connection['username'],
                    '-d', $connection['database'], '--format=custom', '--no-owner', '--no-acl', '--file='.$dump,
                ]);
                if (! $result->successful() || ! is_file($dump) || filesize($dump) === 0) {
                    throw new \RuntimeException('PostgreSQL backup failed. Check pg_dump availability, version and database access.');
                }
                $zip = new ZipArchive;
                if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Cannot create the private backup archive.');
                }
                $manifest = ['format' => 1, 'created_at' => now()->utc()->toIso8601String(), 'files' => []];
                try {
                    $zip->setPassword($password);
                    $files = ['database.dump' => $dump];
                    foreach (['local' => 'private', 'public' => 'public'] as $disk => $prefix) {
                        foreach (Storage::disk($disk)->allFiles() as $file) {
                            if (in_array(explode('/', $file)[0], ['backup-work', 'backups', 'deployment-backups', 'exports', 'readiness'], true) || basename($file) === '.gitignore') {
                                continue;
                            }
                            $source = Storage::disk($disk)->path($file);
                            $backupRoot = rtrim(Storage::disk(config('madina.backup_disk'))->path(''), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                            if (str_starts_with($source, $backupRoot)) {
                                continue;
                            }
                            $files[$prefix.'/'.$file] = $source;
                        }
                    }
                    foreach ($files as $entry => $path) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Backup exceeded its fifteen-minute time budget.');
                        }
                        if (! $zip->addFile($path, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password)) {
                            throw new \RuntimeException('Cannot encrypt a backup file.');
                        }
                        $manifest['files'][$entry] = hash_file('sha256', $path);
                    }
                    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);
                } finally {
                    if (! $zip->close()) {
                        throw new \RuntimeException('Cannot finalize the encrypted backup.');
                    }
                }
                $stream = fopen($archive, 'rb');
                try {
                    if (! Storage::disk(config('madina.backup_disk'))->put($name, $stream, ['visibility' => 'private'])) {
                        throw new \RuntimeException('Cannot persist the encrypted backup.');
                    }
                } finally {
                    fclose($stream);
                }
                $this->line('Encrypted backup: '.$name.' | SHA256 '.hash_file('sha256', $archive).' | files '.count($manifest['files']));
            });

            return $this->call('app:backup-verify', ['file' => $name]);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Backup failed. See the private application log; no backup was marked verified.');

            return self::FAILURE;
        } finally {
            Storage::disk('local')->deleteDirectory($work);
        }
    }
}
