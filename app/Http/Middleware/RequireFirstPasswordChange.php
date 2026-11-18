<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person who got a temporary password (new staff, a newly approved partner) must choose their own
 * before using anything else. Only the Security page where the password is changed, logging out and
 * help stay open. Changing the password clears the mark (see the User model).
 */
class RequireFirstPasswordChange
{
    /** @var list<string> */
    public const OpenPaths = [
        'security',
        'security/*',
        'logout',
        'help',
        'agreement',
        'commands/tours/*',
        'api/v1/me',
        'api/v1/health',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->must_change_password || $request->is(...self::OpenPaths)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, __('joining.first_password_required'));
        }

        return redirect('/security')->with('warning', __('joining.first_password_required'));
    }
}
