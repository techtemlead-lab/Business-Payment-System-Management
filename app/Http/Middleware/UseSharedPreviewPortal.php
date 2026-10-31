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

        return $next($request);
    }
}
