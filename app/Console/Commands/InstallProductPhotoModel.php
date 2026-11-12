<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class InstallProductPhotoModel extends Command
{
    protected $signature = 'madina:install-product-photo-model
        {--full-precision : Also fetch the 245 MB file for computers whose graphics cannot run the smaller one}';

    protected $description = 'Download the model that removes product photo backgrounds and confirm each file is the expected one';

    public function handle(): int
    {
        /** @var array{repository: string, revision: string, directory: string, files: array<string, string>, full_precision_files?: array<string, string>} $model */
        $model = config('madina.product_photo_model');
        $disk = Storage::disk('local');
        $wanted = $model['files'] + ($this->option('full-precision') ? ($model['full_precision_files'] ?? []) : []);

        foreach ($wanted as $file => $checksum) {
            $target = $disk->path($model['directory'].'/'.$file);
            if (File::exists($target) && hash_file('sha256', $target) === $checksum) {
                $this->components->twoColumnDetail($file, 'already in place');

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            $partial = $target.'.partial';
            $url = "https://huggingface.co/{$model['repository']}/resolve/{$model['revision']}/{$file}";
            $response = Http::timeout(1800)->sink($partial)->get($url);

            if (! $response->successful() || hash_file('sha256', $partial) !== $checksum) {
                File::delete($partial);
                $this->components->error("{$file} did not match the expected file, so it was not installed.");

                return self::FAILURE;
            }

            File::move($partial, $target);
            $this->components->twoColumnDetail($file, 'installed');
        }

        $this->components->info('Removing product photo backgrounds is ready to use.');

        return self::SUCCESS;
    }
}
