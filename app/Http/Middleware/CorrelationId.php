<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public const HeaderName = 'X-Correlation-ID';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HeaderName) ?: $request->header('X-Request-ID');
        $correlationId = (is_string($incoming) && strlen(trim($incoming)) >= 8 && strlen(trim($incoming)) <= 128)
            ? trim($incoming)
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        $request->headers->set(self::HeaderName, $correlationId);

        // Context is added to every log line of this request and travels with queued jobs,
        // so a failed import or SMS can be traced back to the request that started it.
        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HeaderName, $correlationId);

        return $response;
    }
}
