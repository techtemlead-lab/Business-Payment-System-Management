<?php

namespace App\Providers;

use App\Domain\Pricing\CurrentProductPricing;
use App\Domain\Pricing\ProductPricing;
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
        TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', (string) config('madina.trusted_proxies'))))));
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
        ResetPassword::createUrlUsing(fn ($user, $token) => route($user->owner() ? 'owner.password.reset' : ($user->staff() ? 'company.password.reset' : 'password.reset'), ['token' => $token, 'email' => $user->email]));

        View::composer('*', function ($view) {
            try {
                $view->with('siteCustomization', SiteCustomization::all());
            } catch (\Throwable $e) {
                $view->with('siteCustomization', SiteCustomization::defaults());
            }
        });
    }
}
