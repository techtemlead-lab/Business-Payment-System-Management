<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $snapshot = Storage::disk('local')->path($work.'/files');
        $archive = Storage::disk('local')->path($work.'/backup.zip');
        $name = 'madina-'.now()->utc()->format('Ymd-His').'-'.Str::uuid().'.zip';
        $startedAt = microtime(true);
        try {
            // Requests that write wait on the shared side of this lock, so hold it only
            // while the database is dumped and uploads are copied locally. Encrypting
            // and storing the archive happens after writes are allowed again.
            $files = DB::transaction(function () use ($connection, $dump, $snapshot): array {
                DB::select('SELECT pg_advisory_xact_lock(?)', [81416004]);
                $result = Process::env(['PGPASSWORD' => (string) $connection['password']])->timeout(600)->run([
                    'pg_dump', '-h', $connection['host'], '-p', (string) $connection['port'], '-U', $connection['username'],
                    '-d', $connection['database'], '--format=custom', '--no-owner', '--no-acl', '--file='.$dump,
                ]);
                if (! $result->successful() || ! is_file($dump) || filesize($dump) === 0) {
                    throw new \RuntimeException('PostgreSQL backup failed. Check pg_dump availability, version and database access.');
                }

                return ['database.dump' => $dump, ...$this->copyUploads($snapshot)];
            });
            $lockedSeconds = round(microtime(true) - $startedAt, 1);

            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create the private backup archive.');
            }
            $manifest = ['format' => 1, 'created_at' => now()->utc()->toIso8601String(), 'files' => []];
            try {
                $zip->setPassword($password);
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
            $this->store((string) config('madina.backup_disk'), $name, $archive, 'Cannot persist the encrypted backup.');
            $checksum = hash_file('sha256', $archive);
            $this->line('Encrypted backup: '.$name.' | SHA256 '.$checksum.' | files '.count($manifest['files']));

            $verified = $this->call('app:backup-verify', ['file' => $name]);
            if ($verified !== self::SUCCESS) {
                return $verified;
            }

            $offsite = (string) config('madina.backup_offsite_disk');
            if ($offsite !== '') {
                // Only a copy on another machine survives losing this server.
                $this->store($offsite, $name, $archive, 'Cannot copy the encrypted backup off-site.');
                $this->line("Off-site copy stored on disk {$offsite}.");
            }
            Log::info('backup.completed', [
                'file' => $name, 'sha256' => $checksum, 'files' => count($manifest['files']),
                'bytes' => filesize($archive), 'writes_paused_seconds' => $lockedSeconds, 'offsite' => $offsite !== '',
            ]);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Backup failed. See the private application log; no backup was marked verified.');

            return self::FAILURE;
        } finally {
            Storage::disk('local')->deleteDirectory($work);
        }
    }

    /**
     * Copies every uploaded file into the work folder, so the archive matches the
     * database dump even if uploads change while the archive is being encrypted.
     *
     * @return array<string, string> archive entry => local copy
     */
    private function copyUploads(string $snapshot): array
    {
        $backupRoot = rtrim(Storage::disk(config('madina.backup_disk'))->path(''), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $files = [];
        foreach (['local' => 'private', 'public' => 'public'] as $disk => $prefix) {
            foreach (Storage::disk($disk)->allFiles() as $file) {
                if (in_array(explode('/', $file)[0], ['backup-work', 'backups', 'backups-offsite', 'deployment-backups', 'exports', 'readiness'], true) || basename($file) === '.gitignore') {
                    continue;
                }
                $source = Storage::disk($disk)->path($file);
                if (str_starts_with($source, $backupRoot)) {
                    continue;
                }
                $entry = $prefix.'/'.$file;
                $copy = $snapshot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry);
                if (! is_dir(dirname($copy)) && ! mkdir(dirname($copy), 0700, true) && ! is_dir(dirname($copy))) {
                    throw new \RuntimeException('Cannot prepare the backup work folder.');
                }
                if (! copy($source, $copy)) {
                    throw new \RuntimeException('Cannot copy an uploaded file for the backup.');
                }
                $files[$entry] = $copy;
            }
        }

        return $files;
    }

    private function store(string $disk, string $name, string $archive, string $failure): void
    {
        $stream = fopen($archive, 'rb');
        try {
            if (! Storage::disk($disk)->put($name, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException($failure);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
