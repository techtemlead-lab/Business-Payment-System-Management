<?php

namespace App\Http\Controllers;

use App\Support\TranslationBundle;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the translation groups as one cacheable file per language (see TranslationBundle).
 * The words are the same for everyone, so the file is public and kept by the browser for a year.
 */
class TranslationBundleController extends Controller
{
    public function show(Request $request, string $locale, string $version): Response
    {
        abort_unless(in_array($locale, config('madina.locales', ['en', 'bn']), true), 404);
        $current = TranslationBundle::version($locale);
        $body = json_encode(TranslationBundle::for($locale), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = response($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);
        // A link made for an older release still gets the current words, but is not kept for a year.
        $response->headers->set('Cache-Control', $version === $current ? 'public, max-age=31536000, immutable' : 'no-cache');
        $response->setEtag($current);
        $response->isNotModified($request);

        return $response;
    }
}
