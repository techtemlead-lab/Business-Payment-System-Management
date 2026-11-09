<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel counts every plain "throttle:5,1" route of one user in the same counter, so opening
 * a few pages that call a throttled route used up the limit of "Download" or "Place order",
 * and a website visitor's first message could be refused. Here each action of a signed-in
 * person and each public page or form has its own counter. Named limiters are unaffected,
 * and the portal sign-in pages and the security page keep the shared counter they always had.
 */
class ThrottleEachAction extends ThrottleRequests
{
    /**
     * @param  Request  $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $signature = parent::resolveRequestSignature($request);
        $isPortalSignIn = $request->user() === null && PortalHost::current($request) !== 'public';
        if ($isPortalSignIn || $request->is('security/*')) {
            return $signature;
        }

        return sha1($signature.'|'.$request->method().'|'.$request->route()?->uri());
    }
}
