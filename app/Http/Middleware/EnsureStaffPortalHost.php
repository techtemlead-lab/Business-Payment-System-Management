<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffPortalHost
{
    /**
     * Staff operations mutate money and access. Keep them on manage/sadmin hosts.
     * Partner-safe read endpoints remain available on dealer/subdealer hosts.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $portal = PortalHost::current($request);
        if (in_array($portal, ['manage', 'sadmin'], true)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && $this->isPartnerSafeRead($request)) {
            return $next($request);
        }

        abort(404);
    }

    private function isPartnerSafeRead(Request $request): bool
    {
        return $request->is(
            'commands/operations/payment-accounts',
            'api/v1/operations/payment-accounts',
            'commands/operations/reports',
            'api/v1/operations/reports',
            'commands/operations/receipts/*',
            'api/v1/operations/receipts/*',
        );
    }
}
