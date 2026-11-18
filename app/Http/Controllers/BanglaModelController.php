<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BanglaModelController extends Controller
{
    /**
     * Hands the English-to-Bangla model files to signed-in office staff, whose forms fill the Bangla boxes
     * from the English ones. Only the pinned files are served, and only once installed on this server.
     */
    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        abort_unless($request->user()?->staff(), 403);
        /** @var array{directory: string, files: array<string, string>} $model */
        $model = config('madina.bangla_model');
        abort_unless(array_key_exists($file, $model['files']), 404);

        $path = $model['directory'].'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => str_ends_with($file, '.json') ? 'application/json' : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            // The files never change for a pinned revision; the browser also keeps its own copy.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
