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
     * Language order: a link's ?lang=, the shared "locale" cookie (the last choice made in this
     * browser on ANY host), this host's session choice, the signed-in person's saved language, then
     * (for someone who never chose) the company's guest language, Bangla, because most partners read
     * Bangla only. With the guest language switched off the browser's own preference decides.
     *
     * The saved language comes last on purpose: older accounts carry the former column default "en"
     * although nobody chose it, and it must never override what this browser was just showing.
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
        $candidate = collect([
            $linked,
            $request->cookie('locale'),
            $request->session()->get('locale'),
            $request->user()?->locale,
        ])->first(fn (mixed $value): bool => is_string($value) && in_array($value, $supported, true));
        if (! is_string($candidate)) {
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

        $response = $next($request);
        if ($linked !== null) {
            $response->headers->setCookie(self::legacyCookieExpiry());
        }

        return $response;
    }

    /**
     * Until 18 Nov 2026 the cookie had no domain. A browser holding both that old cookie and the shared
     * one sends both, and the older one (an earlier choice) is the one the server reads, so every
     * new choice also clears the old copy.
     */
    public static function legacyCookieExpiry(): Cookie
    {
        return cookie()->forget('locale', '/', null);
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
