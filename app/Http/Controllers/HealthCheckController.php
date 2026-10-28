<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class HealthCheckController extends Controller
{
    /**
     * Liveness probe: returns 200 OK as long as the web runtime is responsive.
     */
    public function liveness(): JsonResponse
    {
        return response()->json([
            'status' => 'pass',
            'service' => 'madina-platform',
            'timestamp' => now()->toIso8601String(),
        ], Response::HTTP_OK);
    }

    /**
     * Readiness probe: verifies critical dependencies (PostgreSQL database, cache, storage).
     */
    public function readiness(Request $request): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // 1. Database Connectivity & Query Latency
        $dbStart = microtime(true);
        try {
            DB::select('SELECT 1');
            $dbDuration = round((microtime(true) - $dbStart) * 1000, 2);
            $checks['database'] = [
                'status' => 'pass',
                'latency_ms' => $dbDuration,
                'connection' => config('database.default'),
            ];
        } catch (\Throwable $e) {
            $healthy = false;
            $checks['database'] = [
                'status' => 'fail',
                'error' => app()->isProduction() ? 'Database connection failure' : $e->getMessage(),
            ];
        }

        // 2. Cache Readiness
        $cacheStart = microtime(true);
        try {
            $key = 'readiness_probe_ping';
            Cache::put($key, 1, 10);
            $hit = Cache::get($key) === 1;
            Cache::forget($key);
            $cacheDuration = round((microtime(true) - $cacheStart) * 1000, 2);
            $checks['cache'] = [
                'status' => $hit ? 'pass' : 'warn',
                'latency_ms' => $cacheDuration,
                'driver' => config('cache.default'),
            ];
        } catch (\Throwable $e) {
            // Non-fatal if cache fails in development, but logged
            $checks['cache'] = [
                'status' => 'fail',
                'error' => app()->isProduction() ? 'Cache store failure' : $e->getMessage(),
            ];
        }

        // 3. Storage Writability
        try {
            $storageWritable = is_writable(storage_path('framework/views'));
            $checks['storage'] = [
                'status' => $storageWritable ? 'pass' : 'fail',
            ];
            if (! $storageWritable) {
                $healthy = false;
            }
        } catch (\Throwable $e) {
            $healthy = false;
            $checks['storage'] = ['status' => 'fail'];
        }

        // 4. Financial & Environment Controls
        $checks['environment'] = [
            'app_env' => app()->environment(),
            'financial_activation' => (bool) config('madina.financial_activation'),
            'preview_mode' => (bool) config('madina.preview_mode'),
            'php_version' => PHP_VERSION,
        ];

        $statusCode = $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return response()->json([
            'status' => $healthy ? 'pass' : 'fail',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $statusCode);
    }
}
