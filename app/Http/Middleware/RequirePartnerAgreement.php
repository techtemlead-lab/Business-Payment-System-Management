<?php

namespace App\Http\Middleware;

use App\Domain\Accounts\PartnerAgreement;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the owner switch "Partners must accept the agreement" is on, a dealer
 * or sub-dealer who has not accepted the latest version only reaches the
 * agreement page until they press "I have read and agree".
 *
 * Logging out, the help and support pages, and the public website are never
 * blocked. Office staff and owners pass straight through.
 */
class RequirePartnerAgreement
{
    /**
     * Partner portal paths that stay open while the agreement waits.
     *
     * @var list<string>
     */
    public const OpenPaths = [
        'agreement',
        'commands/agreement/*',
        'logout',
        'help',
        'commands/support-tickets',
        'commands/support-tickets/*',
        'commands/partner-support',
        // The office chat stays open, so a partner can ask about the agreement before accepting it.
        'messages',
        'commands/messages',
        'commands/messages/*',
        'commands/tours/*',
        'api/v1/me',
        'api/v1/health',
    ];

    public function __construct(private PartnerAgreement $agreement) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! in_array($user->role, PartnerAgreement::PartnerRoles, true)) {
            return $next($request);
        }

        $status = $this->agreement->status($user);
        $request->attributes->set(PartnerAgreement::RequestAttribute, $status);
        if (! $this->agreement->blocks($user, $status) || $request->is(...self::OpenPaths)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, __('portal.agr_required_message'));
        }

        return redirect('/agreement');
    }
}
