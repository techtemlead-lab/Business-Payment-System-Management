<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Office pages that work as one place: the commission tabs and the Report Centre's groups.
 * Every tab keeps its own address and its own server check, so this class only decides
 * which tabs to offer. It never grants access to anything.
 */
final class OfficeHubs
{
    /** The commission tab the sidebar opens. */
    public const CommissionHome = 'commissions';

    /** Commission tabs served by the portal workspace; the other tab is an operations page. */
    public const CommissionWorkspaceSections = ['commissions', 'policies', 'simulator'];

    /** The Report Centre. */
    public const ReportCentre = '/operations/financial-reports';

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

    /** Filters of the old order table that the order list understands too. */
    private const OrderListFilters = ['q', 'status', 'period', 'from', 'to', 'buyer_role', 'branch_id', 'all_branches', 'page'];

    /** Report Centre groups in display order. */
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
     * Where the old order table (/reports) opens now: the Report Centre, or the order list for
     * someone who cannot open the centre. An address with order filters stays on the order list.
     *
     * @param  array<string, mixed>  $query
     */
    public static function oldReportsAddress(User $user, bool $onOfficeHost, array $query): string
    {
        $filters = array_filter(Arr::only($query, self::OrderListFilters), fn ($value): bool => $value !== null && $value !== '');
        $mayOpenCentre = $onOfficeHost && ($user->allows('finance') || $user->allows('field_sales') || $user->allows('reports'));
        if (self::mayOpenOrderList($user) && ($filters !== [] || ! $mayOpenCentre)) {
            return '/orders'.($filters === [] ? '' : '?'.http_build_query($filters));
        }

        return $mayOpenCentre ? self::ReportCentre.'?group=orders' : '/';
    }

    /**
     * Report Centre groups for the files this person is offered (ReportData::availableTo),
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
}
