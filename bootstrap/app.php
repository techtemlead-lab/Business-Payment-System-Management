<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\BindSessionFingerprint;
use App\Http\Middleware\CoordinateBackups;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnforceStaffActions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PortalArea;
use App\Http\Middleware\ProtectPrivateResponses;
use App\Http\Middleware\RestrictAdminIp;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UseSharedPreviewPortal;
use App\Support\DatabaseConnectionFailure;
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
            if (PortalHost::allowsSharedPreview()) {
                $hosts[] = '^127\.0\.0\.1$';
                $hosts[] = '^localhost$';
                $hosts[] = '^\[::1\]$';
            }

            return $hosts;
        });
        $middleware->encryptCookies(except: [PortalHost::PreviewCookie]);
        $middleware->prepend(UseSharedPreviewPortal::class);
        $middleware->prepend(CorrelationId::class);
        $middleware->web(append: [
            RestrictAdminIp::class,
            SetLocale::class,
            CoordinateBackups::class,
            ProtectPrivateResponses::class,
            BindSessionFingerprint::class,
            EnforceStaffActions::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias(['active' => ActiveAccount::class, 'portal' => PortalArea::class]);
        $middleware->redirectGuestsTo(fn (Request $r) => '/login');
        $middleware->redirectUsersTo(fn (Request $r) => $r->user() ? PortalHost::homeUrl($r->user()) : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Prefer the branded responsive 503 over Ignition when Postgres is unreachable so
        // phones/tablets still get a readable page instead of a broken debug layout.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! DatabaseConnectionFailure::matches($e)) {
                return null;
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => __('site.error_503_heading'),
                ], 503);
            }

            return response()->view('errors.503', status: 503);
        });
    })->create();
