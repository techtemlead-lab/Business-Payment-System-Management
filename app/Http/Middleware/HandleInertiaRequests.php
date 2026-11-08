<?php

namespace App\Http\Middleware;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Notifications\WebPushNotices;
use App\Domain\Security\SecuritySettings;
use App\Domain\Staff\StaffPermissions;
use App\Domain\Support\ChatRouting;
use App\Domain\Support\PartnerChat;
use App\Domain\Support\TourGuide;
use App\Domain\Support\UserManual;
use App\Support\DashboardManagement;
use App\Support\PortalCatalog;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Closure;
use Illuminate\Http\JsonResponse;
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
        // Unread notices and unread chat messages come from one query per request; navAttention and
        // unreadNotices both read them. Partners count office replies; staff who may answer count
        // partner messages.
        $attentionCounts = null;
        $attention = function () use ($u, &$attentionCounts): array {
            if ($attentionCounts !== null) {
                return $attentionCounts;
            }
            $chatSql = '0';
            $bindings = [$u->id];
            if (PartnerChat::isPartner($u)) {
                $chatSql = 'COALESCE((SELECT partner_unread FROM partner_conversations WHERE partner_id = ?), 0)';
                $bindings[] = $u->id;
            } elseif (($u->staff() || $u->usingStaffWorkspace()) && PartnerChat::canAnswer($u)) {
                // Only the chats routed to this person: every chat for owners and company-wide answerers.
                $routing = app(ChatRouting::class);
                $branchIds = $routing->visibleBranchIds($u);
                if ($branchIds === null) {
                    $chatSql = '(SELECT COALESCE(SUM(office_unread), 0) FROM partner_conversations)';
                } elseif ($branchIds !== []) {
                    $chatSql = '(SELECT COALESCE(SUM(pc.office_unread), 0) FROM partner_conversations pc JOIN users pu ON pu.id = pc.partner_id WHERE '
                        .$routing->partnerBranchSql('pu').' IN ('.implode(',', array_fill(0, count($branchIds), '?')).'))';
                    array_push($bindings, ...$branchIds);
                }
            }
            $row = DB::selectOne('SELECT (SELECT COUNT(*) FROM "account_notices" WHERE user_id = ? AND read_at IS NULL) AS notices, '.$chatSql.' AS chat', $bindings);

            return $attentionCounts = ['notices' => (int) ($row->notices ?? 0), 'chat' => (int) ($row->chat ?? 0)];
        };
        $unreadNotices = fn (): int => $attention()['notices'];

        return [...parent::share($request), 'auth' => [
            'user' => $u?->safe(),
            'capabilities' => $capabilities,
            // Staff the owner nominated to answer partner chat (owners always may).
            'chatAnswerer' => $u !== null && ($u->staff() || $u->usingStaffWorkspace()) && PartnerChat::canAnswer($u),
            // Prescriptions menu: verified partners and the owner-doctor (read from the user row, no query).
            'prescriber' => $u !== null && PrescriptionAccess::canPrescribe($u),
            // May switch prescribing on or off on the accounts page.
            // Same rule as PrescriptionAccess::canVerify, reusing the capabilities read above.
            'prescriberVerifier' => $u !== null && PrescriptionAccess::enabled() && $u->status === 'active'
                && ($u->owner() || ($u->staff() && in_array(PrescriptionAccess::VerifyPermission, $capabilities, true))),
        ],
            'collectionAccounts' => fn () => ($u && $payments->mayReceiveCollectionAccounts($u))
                ? $payments->listing($u, true, true)
                : [],
            // Live rates and targets for the manual's placeholders, loaded after the page and kept.
            'manualRuleValues' => Inertia::defer(fn () => ($u && $portalShell)
                ? app(UserManual::class)->ruleValues()
                : [], 'manual')->once()->as('manualRules:'.app()->getLocale()),
            'navCategories' => fn () => in_array($portal, ['dealer', 'subdealer'], true)
                ? SiteCustomization::getNavCategories()
                : [],
            'siteCustomization' => fn () => in_array($portal, ['dealer', 'subdealer'], true)
                ? SiteCustomization::partnerPanelCopy()
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
            'navAttention' => function () use ($u, $unreadNotices, $attention) {
                if (! $u) {
                    return [
                        'notifications' => 0,
                        'enquiries' => 0,
                        'withdrawals' => 0,
                        'support-admin' => 0,
                        'messages' => 0,
                        'partner-messages' => 0,
                    ];
                }

                $staffWorkspace = $u->staff() || $u->usingStaffWorkspace();

                return [
                    'notifications' => $unreadNotices(),
                    'enquiries' => $staffWorkspace && ($u->allows('content') || $u->allows('accounts'))
                        ? (int) DB::table('enquiries')->where('status', 'new')->count()
                        : 0,
                    'withdrawals' => $staffWorkspace && $u->allows('finance')
                        ? (int) DB::table('withdrawals')->where('status', 'requested')->count()
                        : 0,
                    'support-admin' => $staffWorkspace && ($u->allows('accounts') || $u->owner())
                        ? (int) DB::table('support_tickets')->whereIn('status', ['pending', 'in_progress'])->count()
                        : 0,
                    // Unread chat: office replies for a partner, partner messages for staff who may answer.
                    'messages' => PartnerChat::isPartner($u) ? $attention()['chat'] : 0,
                    'partner-messages' => $staffWorkspace && PartnerChat::canAnswer($u) ? $attention()['chat'] : 0,
                ];
            },
            // Kept for Shell fallbacks; value mirrors navAttention.notifications and shares its single count.
            'unreadNotices' => fn () => $u ? $unreadNotices() : 0,
            // Phone/browser notifications: the public VAPID key, or null when the feature is off.
            'webPushKey' => fn () => ($u && $portal !== 'public') ? WebPushNotices::publicKey() : null,
            'tour' => fn () => ($u && $portalShell)
                ? app(TourGuide::class)->forPage($u, 'overview')
                : null,
            'dashboardNotice' => fn () => DashboardManagement::noticeForUser($u),
            'workspaceBranches' => function () use ($request, $u, $portal) {
                if (! $u || (! $u->staff() && ! $u->owner()) || ! in_array($portal, ['manage', 'sadmin'], true)) {
                    return null;
                }

                $branchOperations = app(BranchOperations::class);
                ['preferred' => $preferred, 'branches' => $branches] = $branchOperations->branchChoices($u);
                $canViewAll = $branchOperations->canViewAllBranches($u);
                $viewAll = $canViewAll && $request->session()->get(BranchOperations::SessionView) === 'all';

                return [
                    'view' => $viewAll ? 'all' : 'branch',
                    'can_view_all' => $canViewAll,
                    'active_branch_id' => $preferred?->id,
                    'active_branch' => $viewAll
                        ? ['id' => null, 'code' => 'ALL', 'name' => __('portal.branch_view_all')]
                        : $preferred?->summary(),
                    'branches' => $branches,
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
    /**
     * Send Bangla text as real characters instead of \uXXXX escapes. The page data on a
     * portal is mostly Bangla, so this makes every in-app page visit about a third the size.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = parent::handle($request, $next);
        if ($response instanceof JsonResponse && $request->header('X-Inertia')) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $response;
    }

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
                'courier' => Lang::get('courier'),
                'prescription' => Lang::get('prescription'),
                'website_admin' => Lang::get('website_admin'),
                'funds' => Lang::get('funds'),
                'productivity' => Lang::get('productivity'),
            ])->as('i18n:'.$locale),
            'portalCatalog' => Inertia::once(fn () => $portalShell
                ? PortalCatalog::inertiaPayload($portal)
                : null)->as('portalCatalog:'.$portal),
            'dashboardControls' => Inertia::once(fn () => DashboardManagement::get())->as('dashboardControls'),
            // Rate placeholders stay in the text here; the live rates follow as a deferred prop,
            // so no page has to read the commission rules just to send the manual.
            'userManualChapters' => Inertia::once(fn () => ($u && $portalShell)
                ? app(UserManual::class)->withoutRuleValues()->chaptersFor($u)
                : [])->as('userManual:'.($u?->id ?? 'guest').':'.$locale),
        ];
    }
}
