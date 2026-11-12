<?php

namespace App\Support;

use App\Domain\Finance\TreasuryAccess;
use App\Models\User;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Office pages that work as one place: the menu rows with tabs, the Settings page,
 * the commission tabs and the report file groups. Every tab keeps its own address and
 * its own server check, so this class only decides which tabs to offer. It never grants
 * access to anything.
 */
final class OfficeHubs
{
    /** The page that lists the settings pages. */
    public const SettingsSection = 'settings';

    /** The commission tab the sidebar opens. */
    public const CommissionHome = 'commissions';

    /** Commission tabs served by the portal workspace; the other tab is an operations page. */
    public const CommissionWorkspaceSections = ['commissions', 'policies', 'simulator'];

    /** The one money report. */
    public const OrderReport = '/treasury/orders';

    /** The list of report files, for field staff who cannot open the money report. */
    public const ReportFiles = '/operations/financial-reports';

    /**
     * Commission tabs in display order. `capability` repeats the check on the tab's own
     * route; `office_host` marks a route that exists only on the office portals.
     *
     * @var array<string, array{href: string, capability: string, office_host: bool}>
     */
    private const CommissionTabs = [
        'commissions' => ['href' => '/commissions', 'capability' => 'finance', 'office_host' => false],
        'policies' => ['href' => '/policies', 'capability' => 'finance', 'office_host' => false],
        'policy-schedules' => ['href' => '/operations/policy-schedules', 'capability' => 'finance', 'office_host' => true],
        'simulator' => ['href' => '/simulator', 'capability' => 'finance', 'office_host' => false],
    ];

    /**
     * Menu rows that hold several pages as tabs, keyed by the row's id in portalNav.ts.
     * `label` is the row's name in the portal lang file; `tabs` maps each section to its address.
     *
     * @var array<string, array{label: string, tabs: array<string, string>}>
     */
    private const Hubs = [
        'office-messages' => ['label' => 'nav_module_messages', 'tabs' => [
            'partner-messages' => '/partner-messages', 'support-admin' => '/support-admin', 'enquiries' => '/enquiries',
        ]],
        'office-stock' => ['label' => 'nav_module_stock', 'tabs' => [
            'catalog' => '/catalog', 'inventory' => '/inventory', 'stock-lots' => '/stock-lots',
            'purchasing' => '/purchasing', 'stock-counts' => '/stock-counts', 'branches' => '/branches',
        ]],
        'office-partners' => ['label' => 'nav_module_partners', 'tabs' => [
            'accounts' => '/accounts', 'sub-dealers' => '/sub-dealers', 'network-tree' => '/network-tree',
            'area-dealers' => '/area-dealers', 'ranks' => '/ranks',
        ]],
        'office-applications' => ['label' => 'nav_module_applications', 'tabs' => [
            'partnerships' => '/partnerships', 'sub-dealer-applications' => '/sub-dealer-applications',
        ]],
        'office-for-partners' => ['label' => 'nav_module_for_partners', 'tabs' => [
            'schemes' => '/schemes', 'contests' => '/contests', 'sales-kit' => '/sales-kit',
            'training' => '/training', 'events' => '/events', 'agreement' => '/agreement',
        ]],
        'office-staff' => ['label' => 'nav_module_people', 'tabs' => [
            'employees' => '/employees', 'payroll' => '/operations/payroll', 'staff-operations' => '/operations/staff-operations',
            'field-sales' => '/operations/field-sales', 'access-management' => '/operations/access-management',
        ]],
        'office-reports' => ['label' => 'nav_module_reports', 'tabs' => [
            'top-sellers' => '/top-sellers', 'partner-scorecard' => '/partner-scorecard',
            'branch-performance' => '/branch-performance', 'financial-reports' => self::ReportFiles,
        ]],
        'office-website' => ['label' => 'label_web', 'tabs' => [
            'website' => '/website', 'content' => '/content', 'public-trust' => '/public-trust',
            'video-marketing' => '/operations/video-marketing', 'feedback' => '/feedback', 'visitors' => '/visitors',
        ]],
    ];

    /**
     * A tab's full name in the portal lang file, when it is not the section's own key.
     * A person who can open only one tab sees this name instead of the row's.
     *
     * @var array<string, string>
     */
    private const OwnNameKeys = [
        'partner-messages' => 'chat_nav_office',
        'catalog' => 'nav_products',
        'purchasing' => 'purchasing_nav',
        'stock-counts' => 'stock_counts_nav',
        'schemes' => 'dated_schemes',
    ];

    /**
     * The Settings page: groups of links in display order, each section with its address.
     *
     * @var array<string, array<string, string>>
     */
    private const SettingsGroups = [
        'money' => [
            'finance-settings' => '/operations/finance-settings',
            'payment-accounts' => '/operations/payment-accounts',
            'financial-readiness' => '/operations/financial-readiness',
        ],
        'partners' => [
            'place-codes' => '/place-codes',
            'dashboards' => '/dashboards',
            'identity-review' => '/identity-review',
        ],
        'system' => [
            'login-rules' => '/login-rules',
            'service-integrations' => '/operations/service-integrations',
            'courier-areas' => '/operations/courier-areas',
            'data-imports' => '/operations/data-imports',
            'audit' => '/audit',
            'privacy-requests' => '/privacy-requests',
            'data-cleanup' => '/data-cleanup',
        ],
    ];

    /** Filters of the old order table that the order list understands too. */
    private const OrderListFilters = ['q', 'status', 'period', 'from', 'to', 'buyer_role', 'branch_id', 'all_branches', 'page'];

    /** Report file groups in display order. */
    private const ReportGroups = ['orders', 'money', 'partners', 'stock', 'staff'];

    /**
     * Which group each report belongs to. A report added later without a line here
     * shows under money rather than disappearing.
     *
     * @var array<string, string>
     */
    private const ReportGroupOf = [
        'orders' => 'orders',
        'district-summary' => 'orders',
        'collections' => 'money',
        'withdrawals' => 'money',
        'ledger' => 'money',
        'tds-register' => 'money',
        'order-refunds' => 'money',
        'refunds' => 'money',
        'fund-spending' => 'money',
        'commissions' => 'partners',
        'settlements' => 'partners',
        'rewards' => 'partners',
        'rank-achievements' => 'partners',
        'rank-incentives' => 'partners',
        'products' => 'stock',
        'product-summary' => 'stock',
        'category-summary' => 'stock',
        'payroll' => 'staff',
    ];

    /**
     * The commission tabs this person may open, with the one on screen marked.
     *
     * @return array{home: string, label: string, tabs: list<array{key: string, label: string, href: string, active: bool}>}
     */
    public static function commission(User $user, bool $onOfficeHost, string $active): array
    {
        $tabs = [];
        foreach (self::CommissionTabs as $key => $tab) {
            if ($user->allows($tab['capability']) && ($onOfficeHost || ! $tab['office_host'])) {
                $tabs[] = [
                    'key' => $key,
                    'label' => (string) __('office_hubs.tab_'.str_replace('-', '_', $key)),
                    'href' => $tab['href'],
                    'active' => $key === $active,
                ];
            }
        }

        return ['home' => self::CommissionHome, 'label' => (string) __('office_hubs.commission_tabs_label'), 'tabs' => $tabs];
    }

    /**
     * The tabs for a page that sits in a menu row, with the one on screen marked, or null
     * for a page in no row. `home` tells the sidebar which row is open, and with only one
     * tab the row and the page take that tab's own name.
     *
     * @return array{home: string, label: string, tabs: list<array{key: string, label: string, href: string, active: bool}>}|null
     */
    public static function forSection(User $user, string $portal, bool $onOfficeHost, string $section): ?array
    {
        foreach (self::Hubs as $key => $hub) {
            if (! isset($hub['tabs'][$section])) {
                continue;
            }
            $tabs = [];
            foreach ($hub['tabs'] as $tab => $href) {
                if (self::mayOpen($user, $portal, $onOfficeHost, $tab, $href)) {
                    $tabs[] = ['key' => $tab, 'label' => (string) __('office_menu.tab_'.str_replace('-', '_', $tab)), 'href' => $href, 'active' => $tab === $section];
                }
            }
            if (count($tabs) === 1) {
                $only = $tabs[0]['key'];

                return ['home' => $only, 'label' => (string) __('portal.'.(self::OwnNameKeys[$only] ?? str_replace('-', '_', $only))), 'tabs' => $tabs];
            }

            return ['home' => $key, 'label' => (string) __('portal.'.$hub['label']), 'tabs' => $tabs];
        }

        return null;
    }

    /**
     * The Settings page's links for the pages this person can open, without empty groups.
     *
     * @return list<array{key: string, label: string, links: list<array{key: string, label: string, href: string}>}>
     */
    public static function settingsGroups(User $user, string $portal, bool $onOfficeHost): array
    {
        $groups = [];
        foreach (self::SettingsGroups as $key => $links) {
            $open = [];
            foreach ($links as $section => $href) {
                if (self::mayOpen($user, $portal, $onOfficeHost, $section, $href)) {
                    $open[] = ['key' => $section, 'label' => (string) __('office_menu.settings_link_'.str_replace('-', '_', $section)), 'href' => $href];
                }
            }
            if ($open !== []) {
                $groups[] = ['key' => $key, 'label' => (string) __('office_menu.settings_group_'.$key), 'links' => $open];
            }
        }

        return $groups;
    }

    /** False when no Settings link is open to this person, so the Settings page has nothing for them. */
    public static function hasSettingsToOpen(User $user, string $portal, bool $onOfficeHost): bool
    {
        return self::settingsGroups($user, $portal, $onOfficeHost) !== [];
    }

    /** The same check the portal page runs before it opens, for offering a link to it. */
    public static function mayOpenSection(User $user, string $portal, string $section): bool
    {
        try {
            PortalCatalog::assertSection($user, $portal, $section);
        } catch (HttpException) {
            return false;
        }

        return true;
    }

    /**
     * Where the old order table (/reports) opens now: the money report, the report files for
     * field staff, or the order list. An address with order filters stays on the order list.
     *
     * @param  array<string, mixed>  $query
     */
    public static function oldReportsAddress(User $user, bool $onOfficeHost, array $query): string
    {
        $filters = array_filter(Arr::only($query, self::OrderListFilters), fn ($value): bool => $value !== null && $value !== '');
        $mayOpenReport = $onOfficeHost && TreasuryAccess::mayOpenOrders($user);
        if (self::mayOpenOrderList($user) && ($filters !== [] || ! $mayOpenReport)) {
            return '/orders'.($filters === [] ? '' : '?'.http_build_query($filters));
        }

        return match (true) {
            $mayOpenReport => self::OrderReport,
            $onOfficeHost && $user->allows('field_sales') => self::ReportFiles,
            default => '/',
        };
    }

    /**
     * Report file groups for the files this person is offered (ReportData::availableTo),
     * without the groups left empty. It only sorts that list and never adds to it.
     *
     * @param  list<string>  $types
     * @return list<array{key: string, label: string, reports: list<string>, links: list<array{label: string, href: string}>}>
     */
    public static function reportGroups(User $user, array $types): array
    {
        $byGroup = [];
        foreach ($types as $type) {
            $byGroup[self::ReportGroupOf[$type] ?? 'money'][] = $type;
        }

        $groups = [];
        foreach (self::ReportGroups as $key) {
            if (isset($byGroup[$key])) {
                $groups[] = ['key' => $key, 'label' => (string) __('office_hubs.report_group_'.$key), 'reports' => $byGroup[$key], 'links' => self::reportGroupLinks($user, $key)];
            }
        }

        return $groups;
    }

    /**
     * Screens that go with a group, for people whose own permissions open them.
     *
     * @return list<array{label: string, href: string}>
     */
    private static function reportGroupLinks(User $user, string $group): array
    {
        return match (true) {
            $group === 'orders' && self::mayOpenOrderList($user) => [['label' => (string) __('office_hubs.link_order_list'), 'href' => '/orders']],
            $group === 'stock' && $user->allows('inventory') => [['label' => (string) __('office_hubs.link_stock'), 'href' => '/inventory']],
            default => [],
        };
    }

    private static function mayOpenOrderList(User $user): bool
    {
        return $user->allows('orders') || $user->allows('finance');
    }

    private static function mayOpen(User $user, string $portal, bool $onOfficeHost, string $section, string $href): bool
    {
        if (str_starts_with($href, '/operations/')) {
            return $onOfficeHost && $user->staff() && self::mayOpenOperationsPage($user, $portal, $section);
        }

        return self::mayOpenSection($user, $portal, $section);
    }

    /** Repeats the check on each operations page, which lives in its own controller. */
    private static function mayOpenOperationsPage(User $user, string $portal, string $section): bool
    {
        return match ($section) {
            'payroll' => $user->allows('payroll'),
            'staff-operations' => $user->allows('staff_admin'),
            'field-sales' => $user->allows('field_sales'),
            'access-management' => $user->role === 'super_admin' && $user->allows('staff_admin'),
            'video-marketing' => $user->allows('content') || $user->allows('catalog'),
            // Anyone who can open the money report is sent there instead of this page.
            'financial-reports' => $user->allows('field_sales') && ! TreasuryAccess::mayOpenOrders($user),
            'service-integrations' => $user->canAct('finance', 'view') || $user->role === 'super_admin',
            'courier-areas' => $portal === 'sadmin' && $user->owner(),
            'data-imports' => $user->canAct('finance', 'view') || $user->canAct('accounts', 'view')
                || ($user->canAct('catalog', 'view') && $user->canAct('pricing', 'view') && $user->canAct('inventory', 'view')),
            default => $user->allows('finance'),
        };
    }
}
