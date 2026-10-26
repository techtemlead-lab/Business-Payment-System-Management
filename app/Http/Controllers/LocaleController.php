<?php

namespace App\Http\Controllers;

use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(config('madina.locales', ['en', 'bn']))],
        ]);
        $request->session()->put('locale', $data['locale']);
        cookie()->queue(cookie('locale', $data['locale'], 60 * 24 * 365, '/'));
        if ($request->user()) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        app()->setLocale($data['locale']);

        return redirect()->to($this->returnUrl($request));
    }

    /**
     * Keep locale switches on the active portal host (and port). Session/referer
     * URLs can point at APP_URL's bare host and drop subdomain routing.
     */
    private function returnUrl(Request $request): string
    {
        $portal = PortalHost::current($request) ?? 'public';
        $fallback = PortalHost::url($portal, '/');
        $previous = (string) url()->previous();
        $current = $request->fullUrl();

        if ($previous === '' || $previous === $current) {
            return $fallback;
        }

        $parts = parse_url($previous);
        if (! is_array($parts)) {
            return $fallback;
        }

        // Root URLs often omit path (`http://host` vs `http://host/`).
        $path = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        // Rebuild on the active request root so subdomain portals keep host/port.
        $origin = rtrim($request->getSchemeAndHttpHost(), '/');
        if ($path === '/' && $query === '') {
            return $origin;
        }

        return $origin.$path.$query;
    }
}
