<?php

use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->web(append: [HandleInertiaRequests::class, SecurityHeaders::class]);
        $m->alias(['workspace' => EnsureWorkspaceAccess::class]);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->shouldRenderJsonWhen(fn (Request $r) => $r->expectsJson());
        $e->respond(function ($response) {
            if (! app()->environment('testing') && in_array($response->getStatusCode(), [403, 404, 500, 503])) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])->toResponse(request())->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
