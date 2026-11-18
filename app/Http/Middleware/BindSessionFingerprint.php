<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind authenticated sessions to a user-agent fingerprint (L3).
 * Stolen cookies used from a different UA are rejected and the session invalidated.
 *
 * SA-10: a session also belongs to the portal it signed in on (a partner's cookie pasted onto the
 * office host is refused), and an office session (manage, sadmin) ends after a fixed number of
 * hours however active it is, on top of the idle timeout.
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
        } elseif (! hash_equals($bound, $current)) {
            return $this->endSession($request, __('portal.err_session_fingerprint_mismatch'));
        }

        $portal = PortalHost::current($request);
        if (is_string($portal) && $portal !== 'public') {
            // Both marks are written at sign-in (AuthController::completeLogin); older sessions simply end on their idle timeout.
            $boundPortal = $request->session()->get('session_portal');
            if (is_string($boundPortal) && $boundPortal !== '' && ! hash_equals($boundPortal, $portal)) {
                return $this->endSession($request, __('security.session_wrong_portal'));
            }
            $startedAt = (int) $request->session()->get('session_started_at', 0);
            $hours = max(1, (int) config('madina.office_session_hours', 12));
            if (in_array($portal, ['manage', 'sadmin'], true) && $startedAt > 0 && $startedAt < now()->subHours($hours)->getTimestamp()) {
                return $this->endSession($request, __('security.session_too_old', ['hours' => $hours]));
            }
        }

        return $next($request);
    }

    private function endSession(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->withErrors(['email' => $message]);
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
