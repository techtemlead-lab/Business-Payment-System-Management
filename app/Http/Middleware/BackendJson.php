<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BackendJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $locale = $request->user()?->preferredLocale() ?? app()->getLocale();
        abort_unless($request->user()?->status === 'active', 403, __('auth_ui.account_unavailable', [], $locale));

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
