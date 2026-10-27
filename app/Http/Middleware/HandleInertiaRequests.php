<?php

namespace App\Http\Middleware;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Security\SecuritySettings;
use App\Domain\Staff\StaffPermissions;
use App\Support\DashboardManagement;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $u = $request->user();
        $capabilities = $u?->capabilities() ?? [];
        $portal = PortalHost::current($request) ?? 'public';

        $payments = app(PaymentAccounts::class);

        return [...parent::share($request), 'auth' => ['user' => $u?->safe(), 'capabilities' => $capabilities],
            'collectionAccounts' => fn () => ($u && $payments->mayReceiveCollectionAccounts($u))
                ? $payments->listing($u, true, true)
                : [],
            'navCategories' => fn () => in_array($portal, ['dealer', 'subdealer'], true)
                ? SiteCustomization::getNavCategories()
                : [],
            'siteCustomization' => fn () => in_array($portal, ['dealer', 'subdealer'], true)
                ? collect(SiteCustomization::all())->only([
                    'header_offers_title', 'header_offers_subtitle',
                    'header_projects_title', 'header_projects_subtitle',
                ])->all()
                : [],
            'portalBase' => '',
            'portal' => $portal,
            'portalCatalog' => in_array($portal, ['dealer', 'subdealer', 'manage', 'sadmin'], true)
                ? PortalCatalog::inertiaPayload($portal)
                : null,
            'publicBase' => PortalHost::origin('public'),
            'publicPageEnabled' => fn () => SiteCustomization::enabledPagesMap(),
            'locale' => app()->getLocale(),
            'locales' => config('madina.locales', ['en', 'bn']),
            'translations' => fn () => [
                'portal' => Lang::get('portal'),
                'auth' => Lang::get('auth_ui'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'totp_setup' => fn () => $request->session()->get('totp_setup'),
                'totp_recovery_codes' => fn () => $request->session()->get('totp_recovery_codes'),
            ],
            'financeActive' => (bool) config('madina.financial_activation'),
            'withdrawalMinimum' => fn () => $u ? app(FinanceSettings::class)->withdrawalMinimum()['amount'] : config('madina.withdrawal_minimum'),
            'demoMode' => (bool) config('madina.preview_mode'),
            'securityControls' => fn () => $u ? app(SecuritySettings::class)->portalPayload($u) : null,
            'staffRoles' => fn () => $u?->role === 'super_admin' ? ['employee', ...array_keys(config('madina.roles')), ...app(StaffPermissions::class)->roleKeys()] : [],
            'staffActions' => fn () => ($u?->staff() || $u?->usingStaffWorkspace())
                ? app(StaffPermissions::class)->matrix($u)
                : [],
            'unreadNotices' => fn () => $u
                ? (int) DB::table('account_notices')->where('user_id', $u->id)->whereNull('read_at')->count()
                : 0,
            'dashboardNotice' => DashboardManagement::noticeForUser($u),
            'dashboardControls' => DashboardManagement::get()];
    }
}
