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
        $candidate = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->cookie('locale');
        if (! is_string($candidate) || ! in_array($candidate, $supported, true)) {
            $candidate = $request->getPreferredLanguage($supported) ?: config('app.locale', 'en');
        }
        if (! in_array($candidate, $supported, true)) {
            $candidate = config('app.locale', 'en');
        }
        app()->setLocale($candidate);

        return $next($request);
    }
}
