<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('madina.locales', ['en', 'bn']);
        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->cookie('locale')
            ?? $request->getPreferredLanguage($supported)
            ?? config('app.locale', 'en');
        if (! in_array($locale, $supported, true)) {
            $locale = config('app.locale', 'en');
        }
        app()->setLocale($locale);

        return $next($request);
    }
}
