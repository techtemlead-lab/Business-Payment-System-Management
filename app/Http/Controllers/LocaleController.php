<?php

namespace App\Http\Controllers;

use App\Support\PortalHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(config('madina.locales', ['en', 'bn']))],
            'return_url' => ['nullable', 'string', 'max:2000'],
        ]);
        $request->session()->put('locale', $data['locale']);
        cookie()->queue(cookie('locale', $data['locale'], 60 * 24 * 365, '/'));
        if ($request->user()) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        app()->setLocale($data['locale']);

        $returnUrl = $this->returnUrl($request);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'locale' => $data['locale'],
                'return_url' => $returnUrl,
            ]);
        }

        return redirect()->to($returnUrl);
    }

    /**
     * Keep locale switches on the active portal host (and port). Session/referer
     * URLs can point at APP_URL's bare host and drop subdomain routing.
     * Never redirect to images, videos, or static asset endpoints.
     */
    private function returnUrl(Request $request): string
    {
        $portal = PortalHost::current($request) ?? 'public';
        $fallback = PortalHost::url($portal, '/');

        // Prefer explicit return_url from form input, fallback to referer/session previous
        $target = (string) ($request->input('return_url') ?: url()->previous());
        $current = $request->fullUrl();

        if ($target === '' || $target === $current) {
            return $fallback;
        }

        $parts = parse_url($target);
        if (! is_array($parts)) {
            return $fallback;
        }

        // Root URLs often omit path (`http://host` vs `http://host/`).
        $path = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        // Discard image, video, media or non-page paths so user is never redirected to product assets
        if ($this->isMediaOrAssetPath($path)) {
            return $fallback;
        }

        // Rebuild on the active request root so subdomain portals keep host/port.
        $origin = rtrim($request->getSchemeAndHttpHost(), '/');
        if ($path === '/' && $query === '') {
            return $origin.'/';
        }

        return $origin.$path.$query;
    }

    /**
     * Check if a path points to an image, video, asset, or non-page route.
     */
    private function isMediaOrAssetPath(string $path): bool
    {
        $cleaned = strtolower(trim($path));

        if ($cleaned === '/locale' || $cleaned === '') {
            return true;
        }

        // Product image and video dynamic routes: /products/{id}/image or /products/{id}/video
        if (preg_match('#^/products/[^/]+/(image|video)$#i', $cleaned)) {
            return true;
        }

        // Media and asset file extensions
        if (preg_match('/\.(png|jpe?g|webp|gif|svg|ico|mp4|webm|avi|css|js|map|json|woff2?|ttf|eot)$/i', $cleaned)) {
            return true;
        }

        // Known static/media asset directories
        if (str_starts_with($cleaned, '/branding/') ||
            str_starts_with($cleaned, '/storage/') ||
            str_starts_with($cleaned, '/media/') ||
            str_starts_with($cleaned, '/build/') ||
            str_starts_with($cleaned, '/reference/icons/')) {
            return true;
        }

        return false;
    }
}
