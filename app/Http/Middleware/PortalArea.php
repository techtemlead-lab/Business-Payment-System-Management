<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalArea
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();
        $allowed = match ($area) {
            'dealer' => $user->role === 'dealer',
            'subdealer' => $user->role === 'sub_dealer',
            'manage' => $user->staff() && ! $user->owner(),
            'sadmin' => $user->owner(),
            default => PortalHost::forUser($user) === $area,
        };
        abort_unless($allowed, 403);

        return $next($request);
    }
}
