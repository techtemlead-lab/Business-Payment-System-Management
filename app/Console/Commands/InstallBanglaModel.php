<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

#[Signature('madina:install-bangla-model')]
#[Description('Download the English-to-Bangla model (about 310 MB) and confirm each file is the expected one')]
class InstallBanglaModel extends Command
{
    /**
     * An install step on purpose, like the photo model: the files are too large for git and the image.
     * Each file is checked against its pinned SHA-256 before it is put in place, so a changed or
     * damaged download is never served to the office browsers.
     */
    public function handle(): int
    {
        /** @var array{repository: string, revision: string, directory: string, files: array<string, string>} $model */
        $model = config('madina.bangla_model');
        $disk = Storage::disk('local');

        foreach ($model['files'] as $file => $checksum) {
            $target = $disk->path($model['directory'].'/'.$file);
            if (File::exists($target) && hash_file('sha256', $target) === $checksum) {
                $this->components->twoColumnDetail($file, 'already in place');

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            $partial = $target.'.partial';
            $url = "https://huggingface.co/{$model['repository']}/resolve/{$model['revision']}/{$file}";
            $response = Http::timeout(1800)->sink($partial)->get($url);

            if (! $response->successful() || ! File::exists($partial) || hash_file('sha256', $partial) !== $checksum) {
                File::delete($partial);
                $this->components->error("{$file} did not match the expected file, so it was not installed.");

                return self::FAILURE;
            }

            File::move($partial, $target);
            $this->components->twoColumnDetail($file, 'installed');
        }

        $this->components->info('Writing Bangla from English is ready to use on the office forms.');

        return self::SUCCESS;
    }
}
