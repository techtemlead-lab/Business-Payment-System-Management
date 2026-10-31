<?php

namespace App\Http\Middleware;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Security\SecuritySettings;
use App\Domain\Staff\StaffPermissions;
use App\Domain\Support\TourGuide;
use App\Domain\Support\UserManual;
use App\Support\DashboardManagement;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $u = $request->user();
        $capabilities = $u?->capabilities() ?? [];
        $portal = PortalHost::current($request) ?? 'public';
        $portalShell = in_array($portal, ['dealer', 'subdealer', 'manage', 'sadmin'], true);

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
            'publicBase' => PortalHost::origin('public'),
            'publicPageEnabled' => fn () => SiteCustomization::enabledPagesMap(),
            'locale' => app()->getLocale(),
            'locales' => config('madina.locales', ['en', 'bn']),
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
            'navAttention' => function () use ($u) {
                if (! $u) {
                    return [
                        'notifications' => 0,
                        'enquiries' => 0,
                        'withdrawals' => 0,
                        'support-admin' => 0,
                    ];
                }

                $staffWorkspace = $u->staff() || $u->usingStaffWorkspace();

                return [
                    'notifications' => (int) DB::table('account_notices')->where('user_id', $u->id)->whereNull('read_at')->count(),
                    'enquiries' => $staffWorkspace && ($u->allows('content') || $u->allows('accounts'))
                        ? (int) DB::table('enquiries')->where('status', 'new')->count()
                        : 0,
                    'withdrawals' => $staffWorkspace && $u->allows('finance')
                        ? (int) DB::table('withdrawals')->where('status', 'requested')->count()
                        : 0,
                    'support-admin' => $staffWorkspace && ($u->allows('accounts') || $u->owner())
                        ? (int) DB::table('support_tickets')->whereIn('status', ['pending', 'in_progress'])->count()
                        : 0,
                ];
            },
            // Kept for Shell fallbacks; value mirrors navAttention.notifications without a second query path when both resolve.
            'unreadNotices' => fn () => $u
                ? (int) DB::table('account_notices')->where('user_id', $u->id)->whereNull('read_at')->count()
                : 0,
            'tour' => fn () => ($u && $portalShell)
                ? app(TourGuide::class)->forPage($u, 'overview')
                : null,
            'dashboardNotice' => fn () => DashboardManagement::noticeForUser($u),
            'workspaceBranches' => function () use ($u, $portal) {
                if (! $u || (! $u->staff() && ! $u->owner()) || ! in_array($portal, ['manage', 'sadmin'], true)) {
                    return null;
                }

                $operations = app(BranchOperations::class);
                $preferred = $operations->preferredBranch($u);

                return [
                    'active_branch_id' => $preferred?->id,
                    'active_branch' => $preferred?->summary(),
                    'branches' => $operations->branchesFor($u),
                ];
            },
        ];
    }

    /**
     * Large / stable shared props remembered by the client across portal navigations.
     * Locale-scoped translation packs (~300KB) are the main portal latency win.
     *
     * @return array<string, mixed>
     */
    public function shareOnce(Request $request): array
    {
        $u = $request->user();
        $portal = PortalHost::current($request) ?? 'public';
        $locale = app()->getLocale();
        $portalShell = in_array($portal, ['dealer', 'subdealer', 'manage', 'sadmin'], true);

        return [
            'translations' => Inertia::once(fn () => [
                'portal' => Lang::get('portal'),
                'auth' => Lang::get('auth_ui'),
            ])->as('i18n:'.$locale),
            'portalCatalog' => Inertia::once(fn () => $portalShell
                ? PortalCatalog::inertiaPayload($portal)
                : null)->as('portalCatalog:'.$portal),
            'dashboardControls' => Inertia::once(fn () => DashboardManagement::get())->as('dashboardControls'),
            'userManualChapters' => Inertia::once(fn () => ($u && $portalShell)
                ? app(UserManual::class)->chaptersFor($u)
                : [])->as('userManual:'.($u?->id ?? 'guest').':'.$locale),
        ];
    }
}
