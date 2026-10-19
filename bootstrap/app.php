<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\CoordinateBackups;
use App\Http\Middleware\EnforceStaffActions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PortalArea;
use App\Http\Middleware\ProtectPrivateResponses;
use App\Http\Middleware\SetLocale;
use App\Support\PortalHost;
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
        $middleware->trustHosts(at: function () {
            $hosts = ['^(.+\.)?'.preg_quote(PortalHost::domain(), '/').'$'];
            if (PortalHost::domain() === 'localhost') {
                $hosts[] = '^127\.0\.0\.1$';
            }

            return $hosts;
        });
        $middleware->web(append: [SetLocale::class, CoordinateBackups::class, ProtectPrivateResponses::class, EnforceStaffActions::class, HandleInertiaRequests::class]);
        $middleware->alias(['active' => ActiveAccount::class, 'portal' => PortalArea::class]);
        $middleware->redirectGuestsTo(fn (Request $r) => '/login');
        $middleware->redirectUsersTo(fn (Request $r) => $r->user() ? PortalHost::homeUrl($r->user()) : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
