<?php

namespace App\Providers;

use App\Database\Connectors\PostgresConnector;
use App\Domain\Integrations\SmtpMailConfig;
use App\Domain\Notifications\WebPushNotices;
use App\Domain\Pricing\CurrentProductPricing;
use App\Domain\Pricing\ProductPricing;
use App\Http\Middleware\ThrottleEachAction;
use App\Listeners\NoticeNewStaffDevice;
use App\Models\User;
use App\Support\PhoneIdentity;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductPricing::class, CurrentProductPricing::class);
        $this->app->bind(ThrottleRequests::class, ThrottleEachAction::class);
        // Fail unreachable Postgres hosts quickly instead of blocking php artisan serve
        // (single-threaded) until max_execution_time, which freezes every device viewport.
        $this->app->bind('db.connector.pgsql', PostgresConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nobody using the portal should be asked for "Y-m-d"; the date boxes are filled in as day/month/year.
        Validator::replacer('date_format', static fn (string $message, string $attribute, string $rule, array $parameters): string => str_replace(':format', match ($parameters[0] ?? '') {
            'Y-m-d' => __('portal.date_format_day_month_year'),
            'H:i' => __('portal.date_format_hour_minute'),
            default => (string) ($parameters[0] ?? ''),
        }, $message));
        $trustedProxies = trim((string) config('madina.trusted_proxies'));
        if ($trustedProxies === '*') {
            TrustProxies::at('*');
        } elseif ($trustedProxies !== '') {
            TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))));
        }

        // Production portals terminate TLS at the host reverse proxy. Keep generated
        // redirects/assets on https so CSP form-action 'self' does not block login.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Root-relative build URLs stay same-origin for localhost, 127.0.0.1, and portal
        // subdomains. Absolute ASSET_URL hosts break CSS/JS when the browse host differs.
        Vite::createAssetPathsUsing(fn (string $path, ?bool $secure = null): string => '/'.ltrim($path, '/'));

        // Ignore public/hot so @vite keeps serving /build assets (Cloud Agent / CSP-safe).
        if (config('madina.force_built_assets')) {
            Vite::useHotFile(storage_path('framework/vite-hot-disabled'));
        }

        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('SELECT 1');
        });
        Event::listen(Looping::class, function (): void {
            static $lastRecorded = 0;
            if (time() - $lastRecorded >= 60) {
                Cache::put('operations:queue-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5));
                $lastRecorded = time();
            }
        });
        Event::listen(Login::class, NoticeNewStaffDevice::class);
        Event::listen(Logout::class, function (Logout $event): void {
            $endpoint = request()->hasSession() ? request()->session()->get(WebPushNotices::SessionEndpoint) : null;
            app(WebPushNotices::class)->forgetOnLogout($event->user instanceof User ? $event->user : null, is_string($endpoint) ? $endpoint : null);
        });
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Log::critical('Background job failed. Review the failed-jobs register.', ['job' => $event->job->resolveName()]);
        });
        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::critical('Queue backlog exceeded its threshold.', ['connection' => $event->connectionName, 'queue' => $event->queue, 'size' => $event->size]);
        });
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $portal = $user instanceof User ? PortalHost::forUser($user) : 'dealer';

            return PortalHost::url($portal, '/reset-password/'.$token.'?email='.urlencode((string) $user->email));
        });

        View::composer('*', function ($view) {
            try {
                $customization = SiteCustomization::all();
            } catch (\Throwable $e) {
                $customization = SiteCustomization::defaults();
            }
            $view->with('siteCustomization', $customization);
            $view->with('publicPageEnabled', SiteCustomization::enabledPagesMap($customization));
        });

        try {
            $this->app->make(SmtpMailConfig::class)->apply();
        } catch (\Throwable) {
            // Integrations table may be unavailable during early migrate/install.
        }

        $this->configureSignInLimits();
    }

    /**
     * Sign-in limits are kept per account, not per internet address (owner decision, 2026-11-05):
     * mobile networks put many dealers behind one address, and busy times must never let one
     * person's sign-in block another's. The per-address figures are only a backstop against
     * scripted abuse and sit far above what a crowded office or phone network sends.
     */
    private function configureSignInLimits(): void
    {
        RateLimiter::for('sign-in', function (Request $request): array {
            $typed = trim((string) $request->input('email'));
            $identity = PhoneIdentity::normalize($typed) ?? Str::lower($typed);

            return [
                Limit::perMinute(30)->by('account|'.$identity),
                Limit::perMinute(600)->by('address|'.$request->ip()),
            ];
        });
        RateLimiter::for('sign-in-code', function (Request $request): array {
            $session = $request->hasSession() ? $request->session() : null;
            $account = $session?->get('pending_login_id') ?? $session?->get('pending_totp_id');
            $key = $account !== null ? 'account|'.$account : 'session|'.($session?->getId() ?? $request->ip());

            return [
                Limit::perMinute(10)->by($key),
                Limit::perMinute(600)->by('address|'.$request->ip()),
            ];
        });
        RateLimiter::for('sign-in-page', function (Request $request): array {
            $session = $request->hasSession() ? $request->session()->getId() : $request->ip();

            return [
                Limit::perMinute(60)->by('session|'.$session),
                Limit::perMinute(1200)->by('address|'.$request->ip()),
            ];
        });
    }
}
