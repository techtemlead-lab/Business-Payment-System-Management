<?php

namespace App\Providers;

use App\Database\Connectors\PostgresConnector;
use App\Domain\Auth\SignInGuard;
use App\Domain\Integrations\SmtpMailConfig;
use App\Domain\Notifications\WebPushNotices;
use App\Domain\Pricing\CurrentProductPricing;
use App\Domain\Pricing\ProductPricing;
use App\Domain\Staff\LeadershipAccess;
use App\Http\Middleware\ThrottleEachAction;
use App\Listeners\NoticeNewStaffDevice;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\PasswordVault;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as LaravelValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Route parameters that always name a record by its number. RouteRecordNumbersTest checks that every
     * record looked up from an address is on this list, so a new route cannot forget it.
     *
     * @var list<string>
     */
    public const NumberedRouteRecords = [
        'appointment', 'article', 'attachment', 'branch', 'consultation', 'datedScheme', 'dietTemplate', 'message',
        'partner', 'partnerContest', 'partnerEvent', 'patient', 'placeCode', 'prescription', 'privacyRequest',
        'product', 'productCategory', 'productConcern', 'productSubcategory', 'productUnit', 'purchaseOrder',
        'salesKitItem', 'stockCountSession', 'stockLot', 'stockTransfer', 'supplier', 'template', 'trainingItem', 'user',
    ];

    /**
     * Route parameters that name a record by its UUID (orders).
     *
     * @var list<string>
     */
    public const UuidRouteRecords = ['order'];

    public const UuidPattern = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

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
        $this->app->singleton(PasswordVault::class);
        $this->app->scoped(LeadershipAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardQueries();
        // Nobody using the portal should be asked for "Y-m-d"; the date boxes are filled in as day/month/year.
        Validator::replacer('date_format', static fn (string $message, string $attribute, string $rule, array $parameters): string => str_replace(':format', match ($parameters[0] ?? '') {
            'Y-m-d' => __('portal.date_format_day_month_year'),
            'H:i' => __('portal.date_format_hour_minute'),
            default => (string) ($parameters[0] ?? ''),
        }, $message));
        // File limits are written in kilobytes in the rules; people read them in MB.
        Validator::replacer('max', static function (string $message, string $attribute, string $rule, array $parameters, LaravelValidator $validator): string {
            $limit = (int) ($parameters[0] ?? 0);
            if (! str_contains($message, ':max') || ! data_get($validator->getData(), $attribute) instanceof UploadedFile) {
                return str_replace(':max', (string) $limit, $message);
            }
            $size = $limit >= 1024
                ? __('portal.file_size_mb', ['size' => LocalDigits::text(round($limit / 1024, 1))])
                : __('portal.file_size_kb', ['size' => LocalDigits::text($limit)]);

            return __('portal.err_file_too_large', ['field' => $validator->getDisplayableAttribute($attribute), 'size' => $size]);
        });
        // Records are found by their number. Without this, a scanner asking for /products/madinanhc.com reaches
        // the database with text where a number belongs, and the visitor gets a server error instead of "not found".
        Route::patterns(array_fill_keys(self::NumberedRouteRecords, '[0-9]+') + array_fill_keys(self::UuidRouteRecords, self::UuidPattern));

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
                $customization = SiteCustomization::forPublicPages();
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
            // Every spelling of one code or phone ("MNHC 1001", "1001", "+880…") shares one counter.
            $identity = app(SignInGuard::class)->normalizedIdentity((string) $request->input('email'));

            return [
                Limit::perMinute(30)->by('account|'.$identity),
                Limit::perMinute(600)->by('address|'.$request->ip()),
            ];
        });
        // Forgot / reset password: counted per account, so many partners on one office or mobile
        // connection never lock each other out. The address limit is only a backstop. When a limit
        // is reached the form says how many minutes to wait instead of showing an error page.
        foreach (['password-forgot' => [3, 10], 'password-reset' => [5, 20]] as $name => [$perMinute, $perHour]) {
            RateLimiter::for($name, function (Request $request) use ($perMinute, $perHour): array {
                $account = 'account|'.Str::lower(trim((string) $request->input('email')));
                $wait = function (Request $request, array $headers) {
                    $minutes = max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60));

                    return back()
                        ->withInput($request->only('email'))
                        ->withErrors(['email' => __('security.reset_wait_minutes', ['minutes' => $minutes])]);
                };

                return [
                    Limit::perMinute($perMinute)->by($account.'|minute')->response($wait),
                    Limit::perHour($perHour)->by($account.'|hour')->response($wait),
                    Limit::perMinute(120)->by('address|'.$request->ip())->response($wait),
                ];
            });
        }
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

    /**
     * Makes slow and one-row-at-a-time queries visible: a slow-query log in every environment, and a
     * lazy-loading (N+1) guard outside production (see config/performance.php).
     */
    private function guardQueries(): void
    {
        $mode = (string) config('performance.lazy_loading', 'log');
        if (! $this->app->isProduction() && $mode !== 'off') {
            Model::preventLazyLoading();
            if ($mode !== 'throw') {
                Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                    Log::warning('Lazy-loaded relationship (N+1): '.$model::class.'::'.$relation.' - eager load it.');
                });
            }
        }

        $thresholdMs = (int) config('performance.slow_query_ms', 500);
        if ($thresholdMs > 0) {
            DB::listen(function (QueryExecuted $query) use ($thresholdMs): void {
                if ($query->time >= $thresholdMs) {
                    Log::warning('Slow database query.', ['ms' => round($query->time, 1), 'sql' => Str::limit($query->sql, 1000), 'connection' => $query->connectionName]);
                }
            });
        }
    }
}
