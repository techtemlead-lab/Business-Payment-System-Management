<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

#[Signature('app:backup-verify {file : Backup filename on the configured private backup disk}')]
#[Description('Decrypt a backup and verify every archived file against its SHA256 manifest')]
class VerifyApplicationBackup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('file');
        if ($name !== basename($name) || ! str_ends_with($name, '.zip') || strlen((string) config('madina.backup_password')) < 32) {
            $this->error('Use a backup filename and configure the backup password.');

            return self::FAILURE;
        }
        $disk = Storage::disk(config('madina.backup_disk'));
        if (! $disk->exists($name)) {
            $this->forgetVerification($name);
            $this->error('Backup not found.');

            return self::FAILURE;
        }
        $zip = new ZipArchive;
        if ($zip->open($disk->path($name)) !== true) {
            $this->forgetVerification($name);
            $this->error('Cannot open backup.');

            return self::FAILURE;
        }
        try {
            $zip->setPassword((string) config('madina.backup_password'));
            $manifestText = $zip->getFromName('manifest.json');
            if ($manifestText === false) {
                throw new \RuntimeException('The backup cannot be decrypted.');
            }
            $manifest = json_decode($manifestText, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 1 || ! isset($manifest['files']['database.dump']) || count($manifest['files']) !== $zip->numFiles - 1) {
                throw new \RuntimeException('The backup manifest is incomplete.');
            }
            foreach ($manifest['files'] as $file => $expected) {
                $stream = $zip->getStream($file);
                if ($stream === false) {
                    throw new \RuntimeException('An archived file is missing.');
                }
                try {
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    if (! hash_equals($expected, hash_final($hash))) {
                        throw new \RuntimeException('An archived file failed its integrity check.');
                    }
                } finally {
                    fclose($stream);
                }
            }
            $dumpHeader = $zip->getFromName('database.dump', 5);
            if ($dumpHeader !== 'PGDMP') {
                throw new \RuntimeException('The database dump format is invalid.');
            }
            Cache::put('operations:verified-backup', ['file' => $name, 'verified_at' => now()->utc()->toIso8601String()], now()->addHours(30));
            $this->info('Backup decrypted and every file hash verified. Keep the password and APP_KEY in a separate secret store.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->forgetVerification($name);
            report($exception);
            $this->error('Backup verification failed. Check the password, archive and application log.');

            return self::FAILURE;
        } finally {
            $zip->close();
        }
    }

    private function forgetVerification(string $name): void
    {
        if ((Cache::get('operations:verified-backup')['file'] ?? null) === $name) {
            Cache::forget('operations:verified-backup');
        }
    }
}
