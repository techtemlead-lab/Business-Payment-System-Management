<?php

namespace App\Http\Middleware;

use App\Domain\Finance\PaymentAccounts;
use App\Support\DashboardManagement;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $u = $request->user();
        $capabilities = $u?->capabilities() ?? [];

        return [...parent::share($request), 'auth' => ['user' => $u?->safe(), 'capabilities' => $capabilities],
            'collectionAccounts' => fn () => $u ? app(PaymentAccounts::class)->listing($u, true) : [],
            'portalBase' => $u?->staff() ? '/company' : '/workspace',
            'flash' => ['success' => fn () => $request->session()->get('success')], 'financeActive' => (bool) config('madina.financial_activation'), 'withdrawalMinimum' => config('madina.withdrawal_minimum'), 'demoMode' => app()->environment('local'),
            'staffRoles' => $u?->role === 'super_admin' ? ['employee', ...array_keys(config('madina.roles'))] : [],
            'dashboardNotice' => DashboardManagement::noticeForUser($u),
            'dashboardControls' => DashboardManagement::get()];
    }
}
