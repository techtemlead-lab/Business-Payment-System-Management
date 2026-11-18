<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Language order: a link's ?lang=, the signed-in person's choice, this session's choice, the
     * shared "locale" cookie, then (for someone who never chose) the company's guest language,
     * Bangla, because most partners read Bangla only. With the guest language switched off the
     * browser's own preference decides.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('madina.locales', ['en', 'bn']);
        $linked = $request->query('lang');
        $linked = is_string($linked) && in_array($linked, $supported, true) ? $linked : null;
        if ($linked !== null) {
            // A link from another host (public site to a sign-in page) carries the choice with it.
            $request->session()->put('locale', $linked);
            cookie()->queue(self::cookieFor($linked));
        }
        $candidate = $linked
            ?? $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->cookie('locale');
        if (! is_string($candidate) || ! in_array($candidate, $supported, true)) {
            // The company's guest language first; with it switched off, staff and owner panels still
            // open in Bangla (not for JSON calls) and other sites follow the browser.
            $guest = config('madina.guest_locale');
            $panelBangla = ! $request->expectsJson() && in_array(PortalHost::current($request), ['manage', 'sadmin'], true) && in_array('bn', $supported, true);
            $candidate = match (true) {
                is_string($guest) && in_array($guest, $supported, true) => $guest,
                $panelBangla => 'bn',
                default => $request->getPreferredLanguage($supported) ?: config('app.locale', 'en'),
            };
        }
        if (! in_array($candidate, $supported, true)) {
            $candidate = config('app.locale', 'en');
        }
        app()->setLocale($candidate);

        return $next($request);
    }

    /**
     * The language cookie, shared by the public site and every portal host so a choice made on one
     * is kept on the others.
     */
    public static function cookieFor(string $locale): Cookie
    {
        return cookie('locale', $locale, 60 * 24 * 365, '/', PortalHost::sharedCookieDomain());
    }
}
