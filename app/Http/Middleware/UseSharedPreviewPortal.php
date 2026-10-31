<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep a loopback preview on the browser's origin while portal routes stay host-scoped.
 *
 * Runs before routing and before cookie decryption, so the portal cookie is excluded
 * from encryption. Real portal hostnames are left unchanged.
 */
class UseSharedPreviewPortal
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! PortalHost::isSharedPreviewHost($request)) {
            return $next($request);
        }

        $root = rtrim($request->getSchemeAndHttpHost(), '/');
        $request->attributes->set('shared_preview_root', $root);
        URL::forceRootUrl($root);

        $portal = $request->cookies->get(PortalHost::PreviewCookie);
        $path = $request->path();
        $switching = $path === 'portals' || $path === 'preview-portal' || str_starts_with($path, 'preview-portal/');
        if ($switching || ! is_string($portal) || ! in_array($portal, PortalHost::StaffPortals, true)) {
            return $next($request);
        }

        $portalHost = PortalHost::host($portal);
        $request->headers->set('HOST', $portalHost);
        $request->server->set('HTTP_HOST', $portalHost);
        $request->server->set('SERVER_NAME', $portalHost);
        $request->attributes->set('shared_preview_portal', $portal);

        return $this->retainPreviewOrigin($next($request), $root);
    }

    /**
     * back() remembers the rewritten portal host. Keep the browser on the preview origin.
     */
    private function retainPreviewOrigin(Response $response, string $root): Response
    {
        if (! $response->isRedirect()) {
            return $response;
        }

        $location = $response->headers->get('Location');
        if (! is_string($location) || $location === '') {
            return $response;
        }

        $parts = parse_url($location);
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($host === '') {
            return $response;
        }

        $portalHosts = array_map(
            fn (string $portal): string => strtolower(PortalHost::host($portal)),
            PortalHost::Portals,
        );
        if (! in_array($host, $portalHosts, true)) {
            return $response;
        }

        $path = is_array($parts) && isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== ''
            ? $parts['path']
            : '/';
        $query = is_array($parts) && isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = is_array($parts) && isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        $response->headers->set('Location', $root.$path.$query.$fragment);

        return $response;
    }
}
