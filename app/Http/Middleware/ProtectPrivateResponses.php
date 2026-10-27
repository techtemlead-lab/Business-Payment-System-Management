<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectPrivateResponses
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // L1: consistent Referrer-Policy on every response.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($request->user() || $request->is('login', 'login/*', 'forgot-password', 'reset-password*', 'exports*') || in_array(PortalHost::current($request), ['dealer', 'subdealer', 'manage', 'sadmin'], true)) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }
        // H4: tighten CSP beyond frame/base/object/form defaults.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; "
            ."script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
            ."img-src 'self' data: blob:; font-src 'self' data: https://fonts.bunny.net; "
            ."connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:; upgrade-insecure-requests"
        );
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        // M4: HSTS with includeSubDomains on production HTTPS.
        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
