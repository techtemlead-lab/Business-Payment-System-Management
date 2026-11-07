<?php

namespace App\Http\Middleware;

use App\Domain\Security\SecuritySettings;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owners (Super Admin, MD, Chairman) and finance staff must use an authenticator app
 * (owner decision Q14). When one of them signs in without the app, the sign-in marks the
 * session, and this middleware keeps them on the Security page until the app is set up.
 *
 * Logging out, the Security page and its forms, and help stay open. Dealers, sub-dealers
 * and other office staff pass straight through, and nothing is blocked in preview mode.
 */
class RequireAuthenticatorSetup
{
    /** Session flag set by the sign-in when the authenticator app still has to be set up. */
    public const SessionKey = 'totp_setup_required';

    /** The portal page that holds the authenticator app setup forms. */
    public const SetupPath = '/security';

    /**
     * Portal paths that stay open while the setup waits.
     *
     * @var list<string>
     */
    public const OpenPaths = [
        'security',
        'security/*',
        'logout',
        'help',
        'commands/tours/*',
        'api/v1/me',
        'api/v1/health',
    ];

    public function __construct(private SecuritySettings $security) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->get(self::SessionKey)) {
            return $next($request);
        }
        $user = $request->user();
        if (! $user instanceof User || ! $this->security->totpSetupPending($user)) {
            $request->session()->forget(self::SessionKey);

            return $next($request);
        }
        if ($request->is(...self::OpenPaths)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, __('portal.flash_totp_setup_required'));
        }

        return redirect(self::SetupPath)->with('success', __('portal.flash_totp_setup_required'));
    }
}
