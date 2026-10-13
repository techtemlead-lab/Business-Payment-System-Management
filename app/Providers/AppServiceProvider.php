<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(fn($user,$token)=>route($user->staff()?'company.password.reset':'password.reset',['token'=>$token,'email'=>$user->email]));

        \Illuminate\Support\Facades\View::composer('*', function ($view) {
            try {
                $view->with('siteCustomization', \App\Support\SiteCustomization::all());
            } catch (\Throwable $e) {
                $view->with('siteCustomization', \App\Support\SiteCustomization::defaults());
            }
        });
    }
}
