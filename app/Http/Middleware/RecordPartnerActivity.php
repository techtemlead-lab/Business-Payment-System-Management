<?php

namespace App\Http\Middleware;

use App\Domain\Audience\PartnerActivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * CR-17: notes that a signed-in dealer or sub-dealer is active. The write itself is throttled in PartnerActivity.
 */
class RecordPartnerActivity
{
    public function __construct(private readonly PartnerActivity $activity) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user !== null) {
            $this->activity->touch($request, $user);
        }

        return $next($request);
    }
}
