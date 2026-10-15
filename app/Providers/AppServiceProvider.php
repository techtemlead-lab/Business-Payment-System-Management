<?php

namespace App\Providers;

use App\Domain\Pricing\CurrentProductPricing;
use App\Domain\Pricing\ProductPricing;
use App\Support\SiteCustomization;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(fn ($user, $token) => route($user->staff() ? 'company.password.reset' : 'password.reset', ['token' => $token, 'email' => $user->email]));

        View::composer('*', function ($view) {
            try {
                $view->with('siteCustomization', SiteCustomization::all());
            } catch (\Throwable $e) {
                $view->with('siteCustomization', SiteCustomization::defaults());
            }
        });
    }
}
