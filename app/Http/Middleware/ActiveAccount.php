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
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, 'This account is unavailable.');
            }
            if ($request->header('X-Inertia')) {
                return Inertia::location($login);
            }

            return redirect($login)->withErrors(['email' => 'This account is unavailable. Contact the company.']);
        }

        return $next($request);
    }
}
