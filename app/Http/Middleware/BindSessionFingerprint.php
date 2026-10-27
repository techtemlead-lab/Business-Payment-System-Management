<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind authenticated sessions to a user-agent fingerprint (L3).
 * Stolen cookies used from a different UA are rejected and the session invalidated.
 */
class BindSessionFingerprint
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $current = $this->fingerprint($request);
        $bound = $request->session()->get('session_fingerprint');
        if (! is_string($bound) || $bound === '') {
            $request->session()->put('session_fingerprint', $current);

            return $next($request);
        }
        if (! hash_equals($bound, $current)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors([
                'email' => __('portal.err_session_fingerprint_mismatch'),
            ]);
        }

        return $next($request);
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
