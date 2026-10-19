<?php

namespace App\Http\Middleware;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Staff\StaffPermissions;
use App\Support\DashboardManagement;
use App\Support\PortalHost;
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
            'portalBase' => '',
            'portal' => PortalHost::current($request) ?? 'public',
            'publicBase' => PortalHost::origin('public'),
            'flash' => ['success' => fn () => $request->session()->get('success')], 'financeActive' => (bool) config('madina.financial_activation'), 'withdrawalMinimum' => fn () => $u ? app(FinanceSettings::class)->withdrawalMinimum()['amount'] : config('madina.withdrawal_minimum'), 'demoMode' => (bool) config('madina.preview_mode'),
            'staffRoles' => fn () => $u?->role === 'super_admin' ? ['employee', ...array_keys(config('madina.roles')), ...app(StaffPermissions::class)->roleKeys()] : [],
            'staffActions' => fn () => $u?->staff() ? app(StaffPermissions::class)->matrix($u) : [],
            'dashboardNotice' => DashboardManagement::noticeForUser($u),
            'dashboardControls' => DashboardManagement::get()];
    }
}
