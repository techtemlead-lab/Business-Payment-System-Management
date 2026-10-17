<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\EnforceStaffActions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PortalArea;
use App\Http\Middleware\ProtectPrivateResponses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ProtectPrivateResponses::class, EnforceStaffActions::class, HandleInertiaRequests::class]);
        $middleware->alias(['active' => ActiveAccount::class, 'portal' => PortalArea::class]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('company', 'company/*') ? '/company/login' : '/login');
        $middleware->redirectUsersTo(fn (Request $r) => $r->user()?->staff() ? '/company' : '/workspace');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
