<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductPhotoModelController extends Controller
{
    /**
     * Hands the background-removal model to office staff who can edit the catalog.
     * It stays behind sign-in because each download is over 100 MB.
     */
    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        abort_unless($request->user()?->staff() && $request->user()->allows('catalog'), 403);
        /** @var array{directory: string, files: array<string, string>, full_precision_files?: array<string, string>} $model */
        $model = config('madina.product_photo_model');
        abort_unless(array_key_exists($file, $model['files'] + ($model['full_precision_files'] ?? [])), 404);

        $path = $model['directory'].'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
