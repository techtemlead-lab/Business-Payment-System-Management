<?php

namespace App\Http\Middleware;

use App\Domain\Security\SecuritySettings;
use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional IP/CIDR allowlist for manage/sadmin portals (M3).
 * Empty MADINA_ADMIN_IP_ALLOWLIST means unrestricted (preview-friendly).
 */
class RestrictAdminIp
{
    public function __construct(private SecuritySettings $security) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $portal = PortalHost::current($request);
        if (! in_array($portal, ['manage', 'sadmin'], true)) {
            return $next($request);
        }
        if (! $this->security->adminIpRestricted()) {
            return $next($request);
        }
        $ip = (string) $request->ip();
        abort_unless($this->security->ipAllowed($ip), 403, __('portal.err_admin_ip_blocked'));

        return $next($request);
    }
}
