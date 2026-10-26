<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'active') {
            $login = $request->user()?->loginPath() ?? '/login';
            $locale = $request->user()?->preferredLocale() ?? app()->getLocale();
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, __('auth_ui.account_unavailable', [], $locale));
            }
            if ($request->header('X-Inertia')) {
                return Inertia::location($login);
            }

            return redirect($login)->withErrors(['email' => __('auth_ui.account_unavailable_contact', [], $locale)]);
        }

        return $next($request);
    }
}
