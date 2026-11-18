<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
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
            // Staff and owner panels open in Bangla when nobody has chosen a language yet; other sites follow the browser.
            $candidate = ! $request->expectsJson() && in_array(PortalHost::current($request), ['manage', 'sadmin'], true) && in_array('bn', $supported, true)
                ? 'bn'
                : ($request->getPreferredLanguage($supported) ?: config('app.locale', 'en'));
        }
        if (! in_array($candidate, $supported, true)) {
            $candidate = config('app.locale', 'en');
        }
        app()->setLocale($candidate);

        return $next($request);
    }
}
