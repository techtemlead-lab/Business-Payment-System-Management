<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel counts every plain "throttle:5,1" route of one user in the same counter, so opening
 * a few pages that call a throttled route used up the limit of "Download" or "Place order".
 * Here each action of a signed-in person has its own counter. Named limiters are unaffected,
 * and the sign-in pages and the security page keep the shared counter they always had.
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
        if ($request->user() === null || $request->is('security/*')) {
            return $signature;
        }

        return sha1($signature.'|'.$request->method().'|'.$request->route()?->uri());
    }
}
