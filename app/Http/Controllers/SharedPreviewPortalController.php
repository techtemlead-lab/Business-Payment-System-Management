<?php

namespace App\Http\Controllers;

use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class SharedPreviewPortalController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(PortalHost::isSharedPreviewHost($request), 404);

        return view('reference.partner-login');
    }

    public function enter(Request $request, string $portal, ?string $path = null): RedirectResponse
    {
        abort_unless(PortalHost::isSharedPreviewHost($request), 404);
        abort_unless(in_array($portal, ['public', ...PortalHost::StaffPortals], true), 404);

        $fallback = $portal === 'public' ? '/' : '/login';
        $carried = is_string($path) && $path !== '' ? '/'.ltrim($path, '/') : null;
        $requested = $request->query('next', $carried ?? $fallback);
        $current = $request->cookies->get(PortalHost::PreviewCookie);
        if ($portal === 'public' || $current !== $portal) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $redirect = redirect($this->safeNextValue(is_string($requested) ? $requested : $fallback, $fallback));
        if ($portal === 'public') {
            return $redirect->withCookie(Cookie::forget(PortalHost::PreviewCookie));
        }

        return $redirect->withCookie(cookie(
            PortalHost::PreviewCookie,
            $portal,
            60 * 12,
            '/',
            null,
            false,
            true,
            false,
            'lax',
        ));
    }

    private function safeNextValue(string $next, string $fallback): string
    {
        $next = str_replace('\\', '/', $next);
        if ($this->isUnsafePath($next) || $this->isUnsafePath(rawurldecode($next))) {
            return $fallback;
        }

        return $next;
    }

    private function isUnsafePath(string $path): bool
    {
        return ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/preview-portal')
            || str_contains($path, '://')
            || str_contains($path, "\n")
            || str_contains($path, "\r");
    }
}
