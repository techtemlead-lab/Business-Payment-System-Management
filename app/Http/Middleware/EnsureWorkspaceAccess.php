<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        $user = $r->user();
        if ($user->role === 'dealer' && $user->dealer?->status !== 'active') {
            abort(403, 'Your dealer account is pending or inactive. Contact your company administrator.');
        }
        if ($user->totp_secret && ! $r->session()->get('mfa_verified')) {
            return redirect('/two-factor');
        }
        if (app()->environment('production') && $user->role === 'admin' && ! $user->totp_secret) {
            return redirect('/security');
        }

        return $next($r);
    }
}
