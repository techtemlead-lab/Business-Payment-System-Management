<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
        $nonce = base64_encode(random_bytes(16));
        if (class_exists(Vite::class) && method_exists(Vite::class, 'useCspNonce')) {
            Vite::useCspNonce($nonce);
        }
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);
        // L1: consistent Referrer-Policy on every response.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($request->user() || $request->is('login', 'login/*', 'forgot-password', 'reset-password*', 'exports*') || in_array(PortalHost::current($request), ['dealer', 'subdealer', 'manage', 'sadmin'], true)) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }
        // H4: tighten CSP beyond frame/base/object/form defaults.
        // Portal subdomains load Vite assets from the apex host (ASSET_URL), so
        // 'self' alone blocks JS/CSS and blanks dealer/subdealer/manage/sadmin SPAs.
        $domain = PortalHost::domain();
        $domainHosts = ($domain !== '' && $domain !== 'localhost') ? " {$domain} *.{$domain}" : '';
        // Vite HMR serves CSS/JS from :5173. Without these origins, local pages render as bare HTML.
        $viteDev = '';
        if (Vite::isRunningHot()) {
            $viteDev = ' http://127.0.0.1:5173 http://localhost:5173 ws://127.0.0.1:5173 ws://localhost:5173';
        }
        // upgrade-insecure-requests turns form-action 'self' into HTTPS. Artisan serve
        // on the loopback preview cannot answer TLS, so keep that origin on HTTP.
        $previewRoot = $request->attributes->get('shared_preview_root');
        $insecurePreview = is_string($previewRoot) && str_starts_with($previewRoot, 'http://');
        $previewSource = $insecurePreview ? ' '.$previewRoot : '';
        $upgrade = $insecurePreview ? '' : '; upgrade-insecure-requests';
        // Chrome rejects form posts to this HTTP loopback origin when form-action lists
        // 'self' or the explicit origin. The preview is local HTTP only.
        $formAction = $insecurePreview ? '*' : "'self'{$domainHosts}";

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'{$previewSource}{$domainHosts}; base-uri 'self'; object-src 'none'; "
            ."frame-ancestors 'self'{$domainHosts}; form-action {$formAction}; "
            ."frame-src 'self'{$domainHosts} https://maps.google.com https://www.google.com https://maps.googleapis.com; "
            ."script-src 'self' 'nonce-{$nonce}' 'unsafe-inline'{$domainHosts}{$viteDev}; "
            ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$domainHosts}{$viteDev}; "
            ."img-src 'self' data: blob:{$domainHosts}{$viteDev}; "
            ."font-src 'self' data: https://fonts.bunny.net{$domainHosts}{$viteDev}; "
            ."connect-src 'self'{$previewSource}{$domainHosts}{$viteDev}; media-src 'self' blob:{$domainHosts}; "
            ."worker-src 'self' blob:{$domainHosts}{$upgrade}"
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
