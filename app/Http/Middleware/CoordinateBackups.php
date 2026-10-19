<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CoordinateBackups
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next): Response {
            DB::select('SELECT pg_advisory_xact_lock_shared(?)', [81416004]);

            return $next($request);
        });
    }
}
