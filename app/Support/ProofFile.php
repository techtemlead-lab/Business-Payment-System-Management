<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends a screenshot, receipt or scanned paper that somebody uploaded.
 *
 * Pictures and PDFs open in the browser (inline) so the page can show them in a pop-up or a new tab; a
 * request with ?download=1 still saves the file. Anything else (SVG, HTML, spreadsheets, unknown types)
 * is always a download, so a stored file can never run as a page of this site. The caller has already
 * checked who may see the file: this class only decides how the bytes travel.
 */
final class ProofFile
{
    /** The only types that may open in the browser, and the extension each one is named with. */
    public const InlineTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param  string  $path  path on the disk, as stored in the database
     * @param  string  $name  file name offered when the file is saved
     * @param  array<string, string>  $headers
     */
    public static function response(Request $request, string $path, string $name, array $headers = [], string $disk = 'local'): StreamedResponse
    {
        $storage = Storage::disk($disk);
        $detected = (string) ($storage->mimeType($path) ?: '');
        $inline = isset(self::InlineTypes[$detected]) && ! $request->boolean('download');

        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ];

        if (! $inline) {
            $headers['Content-Type'] ??= $detected !== '' ? $detected : 'application/octet-stream';

            return $storage->response($path, $name, $headers, 'attachment');
        }

        // The type comes from the file itself, never from a stored label that could have been set by the uploader.
        $headers['Content-Type'] = $detected;
        if (str_starts_with($detected, 'image/')) {
            $headers['Content-Security-Policy'] = "default-src 'none'";
        }

        return $storage->response($path, $name, $headers, 'inline');
    }

    /** Whether the file would open in the browser; the pages use it to choose a picture, a PDF tab or a plain download. */
    public static function opensInBrowser(?string $mime): bool
    {
        return $mime !== null && isset(self::InlineTypes[strtolower($mime)]);
    }
}
