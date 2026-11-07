<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Error pages for addresses that match no route are rendered before the web
 * middleware runs, so SetLocale never chose the visitor's language and a Bangla
 * user saw an English "page not found". This reads the saved language cookie
 * the same way the cookie middleware would and applies it to the error page.
 */
final class ErrorPageLocale
{
    public static function apply(Request $request): void
    {
        $supported = (array) config('madina.locales', ['en', 'bn']);
        $locale = self::cookieLocale($request, $supported);
        if ($locale === null) {
            $preferred = $request->getPreferredLanguage($supported);
            $locale = is_string($preferred) && in_array($preferred, $supported, true) ? $preferred : null;
        }
        if ($locale !== null) {
            app()->setLocale($locale);
        }
    }

    /**
     * @param  array<int, string>  $supported
     */
    private static function cookieLocale(Request $request, array $supported): ?string
    {
        $raw = $request->cookies->get('locale');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        // Already decrypted by the cookie middleware when the request reached a route.
        if (in_array($raw, $supported, true)) {
            return $raw;
        }
        try {
            $value = CookieValuePrefix::validate('locale', Crypt::decryptString($raw), Crypt::getAllKeys());
        } catch (DecryptException) {
            return null;
        }

        return is_string($value) && in_array($value, $supported, true) ? $value : null;
    }
}
