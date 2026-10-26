<?php

namespace App\Providers;

use App\Domain\Pricing\CurrentProductPricing;
use App\Domain\Pricing\ProductPricing;
use App\Models\User;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductPricing::class, CurrentProductPricing::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
