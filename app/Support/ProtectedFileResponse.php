<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a file from the private "local" disk after the controller has checked who may see it.
 *
 * In production nginx can send the bytes itself (X-Accel-Redirect to an internal location), so a phone
 * downloading a 100 MB video or the photo model does not hold a PHP worker for minutes. Elsewhere, and
 * whenever the switch is off, PHP streams the file as before.
 */
final class ProtectedFileResponse
{
    /** The nginx location that maps onto storage/app/private; see infra/nginx.production.conf. */
    public const NginxLocation = '/_protected/';

    /**
     * @param  string  $relativePath  path on the "local" disk, as stored in the database or config
     * @param  array<string, string|null>  $headers
     */
    public static function make(string $relativePath, array $headers = []): Response
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $segments = explode('/', $relativePath);
        abort_if($relativePath === '' || in_array('..', $segments, true) || in_array('.', $segments, true), 404);
        $absolutePath = Storage::disk('local')->path($relativePath);

        if (! config('madina.nginx_protected_files')) {
            return response()->file($absolutePath, $headers);
        }

        $headers['Content-Type'] ??= Storage::disk('local')->mimeType($relativePath) ?: 'application/octet-stream';

        return response('', 200, $headers + [
            'X-Accel-Redirect' => self::NginxLocation.implode('/', array_map('rawurlencode', $segments)),
        ]);
    }
}
