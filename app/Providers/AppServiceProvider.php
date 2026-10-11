<?php

namespace App\Providers;

use App\Contracts\PayoutProvider;
use App\Models\User;
use App\Services\UnconfiguredPayoutProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayoutProvider::class, UnconfiguredPayoutProvider::class);
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->role === 'admin');
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perMinute(20)->by($r->ip())]);
    }
}
