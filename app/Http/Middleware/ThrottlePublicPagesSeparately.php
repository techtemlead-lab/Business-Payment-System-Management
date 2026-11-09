<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Gives every public page and form its own rate limit. The framework counts all the limited
 * pages of a site together for each visitor, so opening five ordinary pages used up a form's
 * limit of five a minute and the visitor's first message was refused. The portals and their
 * sign-in pages keep the framework's behaviour.
 */
class ThrottlePublicPagesSeparately extends ThrottleRequests
{
    protected function resolveRequestSignature($request): string
    {
        $signature = parent::resolveRequestSignature($request);
        if (PortalHost::current($request) !== 'public') {
            return $signature;
        }

        return sha1($signature.'|'.$request->method().'|'.$request->route()?->uri());
    }
}
