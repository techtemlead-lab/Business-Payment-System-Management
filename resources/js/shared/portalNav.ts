/**
 * Role-aware portal navigation for staff and partner shells.
 * Virtual items deep-link into existing section routes/query keys.
 */

export type StaffDomain = 'company' | 'dealers' | 'sub_dealers';

export type NavMother = {
    id: string;
    labelKey: string;
    items: string[];
};

export type NavLinkSpec = {
    id: string;
    /** Portal section key used by WorkspaceController / deep links. */
    section: string;
    labelKey: string;
    /** Path after portal base (no leading slash). Empty string = overview. */
    hrefPath: string;
    iconKey: string;
    /** Optional active matcher; defaults to section equality. */
    match?: (ctx: { section: string; search: string }) => boolean;
};

type CapabilityContext = {
    capabilities: string[];
    role: string;
    ownerPortal: boolean;
    dashboardControls?: Record<string, unknown> | null;
};

export const OPERATIONS_SECTIONS = new Set([
    'data-imports', 'service-integrations', 'field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts',
    'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports',
    'dues-adjustments', 'finance-settings', 'access-management', 'video-marketing',
]);

export const DEALER_ONLY_SECTIONS = ['accounts', 'partnerships', 'area-dealers'];
export const SUB_DEALER_ONLY_SECTIONS = ['sub-dealers', 'salary'];
export const SHARED_PARTNER_SECTIONS = ['commissions', 'policies', 'simulator', 'network-tree', 'ranks'];
export const ACCOUNT_SECTIONS = ['profile', 'security', 'notifications', 'help'];
export const MANAGEMENT_SECTIONS = [
    'overview', 'employees', 'dashboards', 'access-management', 'my-payroll', 'payroll', 'staff-operations', 'field-sales',
    'orders', 'order', 'deliveries', 'dues', 'sub-dealer-orders',
    'treasury', 'benefits-fund', 'payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds',
    'financial-readiness', 'dues-adjustments', 'finance-settings', 'financial-reports', 'data-imports', 'service-integrations',
    'rewards', 'settlements', 'payouts-hub', 'payments', 'withdrawals', 'ledger', 'reports',
    'catalog', 'inventory', 'branches', 'branch-performance', 'place-codes', 'video-marketing', 'website', 'content', 'enquiries', 'feedback', 'audit', 'support-admin',
];

const tabOf = (search: string): string | null => new URLSearchParams(search).get('tab');
const actionOf = (search: string): string | null => new URLSearchParams(search).get('action');

/** Section id → i18n portal.* key for default labels. */
export const SECTION_LABEL_KEYS: Record<string, string> = {
    'field-sales': 'field_sales', 'staff-operations': 'staff_operations', payroll: 'payroll', 'my-payroll': 'my_payroll',
    'payment-accounts': 'payment_accounts', 'policy-schedules': 'policy_schedules', 'reserve-payouts': 'reserve_payouts',
    'data-imports': 'data_imports', 'service-integrations': 'service_integrations', 'commission-holds': 'commission_holds',
    'financial-readiness': 'financial_readiness', 'financial-reports': 'financial_reports',
    'dues-adjustments': 'dues_adjustments', 'finance-settings': 'finance_settings',
    'access-management': 'access_management', 'video-marketing': 'video_marketing',
    overview: 'overview', orders: 'orders', purchase: 'purchase', catalog: 'catalog', accounts: 'accounts',
    inventory: 'inventory', deliveries: 'deliveries', dues: 'dues', payments: 'payments', network: 'network',
    policies: 'policies', salary: 'salary', withdrawals: 'withdrawals', ledger: 'ledger', reports: 'reports',
    customization: 'website', website: 'website', content: 'content', enquiries: 'enquiries', feedback: 'feedback',
    'share-feedback': 'share_feedback', partnerships: 'partnerships', audit: 'audit', profile: 'profile',
    security: 'security', notifications: 'notifications', circulars: 'circulars', earnings: 'earnings',
    'full-report': 'full_report', ranks: 'ranks', commissions: 'commissions', 'network-tree': 'network_tree',
    'area-dealers': 'area_dealers', 'place-codes': 'place_codes', branches: 'branches',
    'branch-performance': 'branch_performance', rewards: 'rewards', 'capital-flow': 'capital_flow',
    settlements: 'settlements', simulator: 'simulator', 'payouts-hub': 'payouts_hub', treasury: 'treasury',
    'benefits-fund': 'benefits_fund', 'sub-dealers': 'sub_dealers', 'sub-dealer-orders': 'sub_dealer_orders',
    employees: 'employees', dashboards: 'dashboards', help: 'help', 'support-admin': 'support_admin',
};

function defaultHrefPath(section: string): string {
    if (section === 'overview') {
        return '';
    }
    if (OPERATIONS_SECTIONS.has(section)) {
        return `operations/${section}`;
    }
    return section;
}

function defaultSpec(id: string): NavLinkSpec {
    const section = id;
    return {
        id,
        section,
        labelKey: SECTION_LABEL_KEYS[id] ?? id.replaceAll('-', '_'),
        hrefPath: defaultHrefPath(section),
        iconKey: id,
    };
}

/**
 * Virtual Stream 1 tools + 1:1 section links.
 * Keep section/query keys stable for deep links.
 */
export const NAV_LINK_SPECS: Record<string, NavLinkSpec> = {
    overview: defaultSpec('overview'),
    'catalog-products': {
        id: 'catalog-products',
        section: 'catalog',
        labelKey: 'nav_products',
        hrefPath: 'catalog',
        iconKey: 'catalog',
        match: ({ section, search }) => section === 'catalog' && tabOf(search) !== 'taxonomy',
    },
    'catalog-taxonomy': {
        id: 'catalog-taxonomy',
        section: 'catalog',
        labelKey: 'cms_taxonomy_tab',
        hrefPath: 'catalog?tab=taxonomy',
        iconKey: 'catalog-taxonomy',
        match: ({ section, search }) => section === 'catalog' && tabOf(search) === 'taxonomy',
    },
    stock: {
        id: 'stock',
        section: 'inventory',
        labelKey: 'stock_on_hand',
        hrefPath: 'inventory',
        iconKey: 'inventory',
        match: ({ section, search }) => section === 'inventory' && !actionOf(search),
    },
    'product-in': {
        id: 'product-in',
        section: 'inventory',
        labelKey: 'stock_action_product_in',
        hrefPath: 'inventory?action=stock_in',
        iconKey: 'product-in',
        match: ({ section, search }) => section === 'inventory' && actionOf(search) === 'stock_in',
    },
    'product-out': {
        id: 'product-out',
        section: 'inventory',
        labelKey: 'stock_action_product_out',
        hrefPath: 'inventory?action=stock_out',
        iconKey: 'product-out',
        match: ({ section, search }) => section === 'inventory' && actionOf(search) === 'stock_out',
    },
};

export function resolveNavLink(item: string): NavLinkSpec {
    return NAV_LINK_SPECS[item] ?? defaultSpec(item);
}

export function isNavItemActive(item: string, section: string, search = ''): boolean {
    const spec = resolveNavLink(item);
    const normalized = section === '' ? 'overview' : section;
    if (spec.match) {
        return spec.match({ section: normalized, search });
    }
    return normalized === spec.section;
}

export function sectionHref(base: string, item: string): string {
    const path = resolveNavLink(item).hrefPath;
    if (!path) {
        return base || '/';
    }
    return `${base}/${path}`;
}

export function pick(available: Set<string>, items: string[]): string[] {
    return items.filter((item) => {
        const section = resolveNavLink(item).section;
        return available.has(section);
    });
}

export function mother(id: string, labelKey: string, items: string[]): NavMother | null {
    return items.length > 0 ? { id, labelKey, items } : null;
}

export function compactMothers(mothers: Array<NavMother | null>): NavMother[] {
    return mothers.filter((entry): entry is NavMother => entry !== null);
}

export function buildStaffAvailable(ctx: CapabilityContext): Set<string> {
    const { capabilities, role, ownerPortal } = ctx;
    const items = new Set<string>(['overview', 'profile', 'security', 'notifications', 'help']);
    if (capabilities.includes('accounts') || ownerPortal) items.add('support-admin');
    if (capabilities.includes('field_sales')) items.add('field-sales');
    if (capabilities.includes('staff_admin')) items.add('staff-operations');
    if (capabilities.includes('payroll')) items.add('payroll');
    items.add('my-payroll');
    if (capabilities.some((cap) => ['finance', 'accounts'].includes(cap))) items.add('data-imports');
    if (capabilities.includes('finance') || role === 'super_admin') items.add('service-integrations');
    if (capabilities.includes('finance')) {
        ['payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'dues-adjustments', 'finance-settings', 'treasury',
            'commissions', 'policies', 'ledger', 'reports', 'rewards', 'settlements', 'simulator', 'payouts-hub', 'payments', 'withdrawals', 'salary'].forEach((item) => items.add(item));
    }
    if (capabilities.some((cap) => ['finance', 'reports', 'field_sales'].includes(cap))) items.add('financial-reports');
    if (capabilities.some((cap) => ['reports', 'orders', 'finance'].includes(cap))) items.add('reports');
    if (role === 'super_admin') items.add('access-management');
    if (capabilities.some((cap) => ['content', 'catalog'].includes(cap))) items.add('video-marketing');
    if (capabilities.includes('accounts')) {
        ['dashboards', 'employees', 'place-codes', 'accounts', 'partnerships', 'network-tree', 'area-dealers', 'ranks', 'sub-dealers', 'enquiries'].forEach((item) => items.add(item));
    }
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('benefits-fund');
    if (capabilities.some((cap) => ['catalog', 'pricing', 'inventory', 'orders', 'finance'].includes(cap))) items.add('catalog');
    if (capabilities.includes('inventory')) {
        items.add('inventory');
        items.add('branches');
    }
    if (capabilities.includes('accounts') && !items.has('branches')) items.add('branches');
    if (capabilities.some((cap) => ['reports', 'finance', 'inventory'].includes(cap)) || ['super_admin', 'md', 'chairman'].includes(role)) {
        items.add('branch-performance');
    }
    if (capabilities.includes('content')) {
        ['website', 'content', 'enquiries', 'feedback'].forEach((item) => items.add(item));
    }
    if (capabilities.includes('audit')) items.add('audit');
    if (capabilities.includes('orders') || capabilities.includes('finance')) {
        ['orders', 'deliveries', 'dues'].forEach((item) => items.add(item));
    }
    if (capabilities.includes('orders')) {
        items.add('sub-dealer-orders');
    }
    return items;
}

export function buildPartnerAvailable(role: string, dashboardControls?: Record<string, unknown> | null): Set<string> {
    const controls = dashboardControls || {};
    if (role === 'dealer') {
        const items = new Set(['overview', 'orders', 'purchase', 'deliveries', 'dues', 'catalog', 'network', 'ranks', 'earnings', 'full-report', 'withdrawals', 'profile', 'security', 'notifications', 'circulars', 'help']);
        if (controls.dealer_catalog_enabled === false) {
            items.delete('catalog');
            items.delete('purchase');
        }
        if (controls.dealer_network_tree_enabled === false) {
            items.delete('network');
        }
        if (controls.dealer_ranks_enabled === false) {
            items.delete('ranks');
        }
        if (controls.dealer_orders_enabled === false) {
            items.delete('orders');
            items.delete('purchase');
            items.delete('deliveries');
            items.delete('dues');
        }
        return items;
    }
    if (role === 'sub_dealer') {
        const items = new Set(['overview', 'orders', 'purchase', 'deliveries', 'dues', 'catalog', 'salary', 'earnings', 'full-report', 'withdrawals', 'profile', 'security', 'notifications', 'circulars', 'help']);
        if (controls.subdealer_catalog_enabled === false) {
            items.delete('catalog');
            items.delete('purchase');
        }
        if (controls.subdealer_salary_enabled === false) {
            items.delete('salary');
        }
        if (controls.subdealer_deliveries_enabled === false) {
            items.delete('deliveries');
        }
        if (controls.subdealer_orders_enabled === false) {
            items.delete('orders');
            items.delete('purchase');
            items.delete('dues');
        }
        return items;
    }
    return new Set(['profile', 'security', 'notifications']);
}

export function resolveStaffDomain(section: string, readPin: () => StaffDomain | null): StaffDomain {
    if (DEALER_ONLY_SECTIONS.includes(section)) {
        return 'dealers';
    }
    if (SUB_DEALER_ONLY_SECTIONS.includes(section)) {
        return 'sub_dealers';
    }
    if (SHARED_PARTNER_SECTIONS.includes(section)) {
        const pin = readPin();
        if (pin === 'dealers' || pin === 'sub_dealers') {
            return pin;
        }
        return 'dealers';
    }
    if (ACCOUNT_SECTIONS.includes(section)) {
        return readPin() ?? 'company';
    }
    if (MANAGEMENT_SECTIONS.includes(section) || section === '') {
        return 'company';
    }
    return readPin() ?? 'company';
}

export type StaffDomainGroup = {
    id: StaffDomain;
    labelKey: string;
    mothers: NavMother[];
};

function staffNavigation(staffAvailable: Set<string>): {
    company: NavMother[];
    dealers: NavMother[];
    subDealers: NavMother[];
    account: NavMother | null;
} {
    return {
        company: compactMothers([
            mother('overview', 'nav_module_overview', pick(staffAvailable, ['overview'])),
            mother('catalog', 'nav_module_catalog', pick(staffAvailable, ['catalog-products', 'catalog-taxonomy'])),
            mother('stock', 'nav_module_stock', pick(staffAvailable, [
                'stock', 'product-in', 'product-out', 'branches', 'branch-performance', 'place-codes',
            ])),
            mother('orders', 'nav_module_orders', pick(staffAvailable, ['orders', 'deliveries', 'dues'])),
            mother('finance', 'nav_module_finance', pick(staffAvailable, [
                'treasury', 'payments', 'withdrawals', 'payment-accounts', 'ledger', 'dues-adjustments', 'benefits-fund',
                'policy-schedules', 'commission-holds', 'settlements', 'rewards', 'payouts-hub', 'reserve-payouts',
                'finance-settings', 'financial-readiness',
            ])),
            mother('people', 'nav_module_people', pick(staffAvailable, [
                'employees', 'staff-operations', 'payroll', 'my-payroll', 'field-sales', 'access-management',
            ])),
            mother('reports', 'nav_module_reports', pick(staffAvailable, [
                'reports', 'financial-reports', 'dashboards', 'data-imports',
            ])),
            mother('settings', 'nav_module_settings', pick(staffAvailable, [
                'website', 'content', 'video-marketing', 'enquiries', 'feedback', 'service-integrations', 'audit', 'support-admin',
            ])),
            mother('account', 'nav_module_account', pick(staffAvailable, ['profile', 'security', 'notifications', 'help'])),
        ]),
        dealers: compactMothers([
            mother('directory', 'nav_module_directory', pick(staffAvailable, ['accounts', 'partnerships'])),
            mother('commission', 'nav_module_commission', pick(staffAvailable, ['commissions', 'policies', 'simulator'])),
            mother('network', 'nav_module_network', pick(staffAvailable, ['network-tree', 'area-dealers', 'ranks'])),
        ]),
        subDealers: compactMothers([
            mother('directory', 'nav_module_directory', pick(staffAvailable, ['sub-dealers', 'sub-dealer-orders'])),
            mother('commission', 'nav_module_commission', pick(staffAvailable, ['commissions', 'policies', 'simulator'])),
            mother('network', 'nav_module_network', pick(staffAvailable, ['network-tree', 'ranks'])),
            mother('compensation', 'nav_module_compensation', pick(staffAvailable, ['salary'])),
        ]),
        account: mother('account', 'nav_module_account', pick(staffAvailable, ['profile', 'security', 'notifications', 'help'])),
    };
}

export function buildStaffDomainGroups(input: {
    staffAvailable: Set<string>;
    ownerPortal: boolean;
}): StaffDomainGroup[] {
    const navigation = staffNavigation(input.staffAvailable);

    const groups: StaffDomainGroup[] = [
        {
            id: 'company',
            labelKey: input.ownerPortal ? 'nav_management_section' : 'nav_company_section',
            mothers: navigation.company,
        },
        {
            id: 'dealers',
            labelKey: 'nav_dealer_section',
            mothers: navigation.dealers,
        },
        {
            id: 'sub_dealers',
            labelKey: 'nav_subdealer_section',
            mothers: navigation.subDealers,
        },
    ];

    return groups.filter((group) => group.mothers.length > 0);
}

export function buildDomainMothers(input: {
    staff: boolean;
    role: string;
    staffAvailable: Set<string>;
    partnerAvailable: Set<string>;
    currentDomain: StaffDomain;
}): NavMother[] {
    const { staff, role, staffAvailable, partnerAvailable, currentDomain } = input;

    if (!staff) {
        if (role === 'dealer') {
            return compactMothers([
                mother('home', 'nav_module_home', pick(partnerAvailable, ['overview'])),
                mother('commerce', 'nav_module_commerce', pick(partnerAvailable, ['orders', 'purchase', 'catalog', 'deliveries', 'dues'])),
                mother('network', 'nav_module_network', pick(partnerAvailable, ['network', 'ranks'])),
                mother('earnings', 'nav_module_earnings', pick(partnerAvailable, ['earnings', 'full-report', 'withdrawals'])),
                mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'circulars', 'help'])),
            ]);
        }
        if (role === 'sub_dealer') {
            return compactMothers([
                mother('home', 'nav_module_home', pick(partnerAvailable, ['overview'])),
                mother('commerce', 'nav_module_commerce', pick(partnerAvailable, ['orders', 'purchase', 'catalog', 'deliveries', 'dues'])),
                mother('earnings', 'nav_module_earnings', pick(partnerAvailable, ['salary', 'earnings', 'full-report', 'withdrawals'])),
                mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'circulars', 'help'])),
            ]);
        }
        return compactMothers([
            mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'help'])),
        ]);
    }

    const navigation = staffNavigation(staffAvailable);
    const domainModules = currentDomain === 'dealers'
        ? navigation.dealers
        : currentDomain === 'sub_dealers'
            ? navigation.subDealers
            : navigation.company;

    return compactMothers([...domainModules, navigation.account]);
}

export function labelKeyForNavItem(item: string, staff: boolean): string {
    if (item === 'catalog' && staff) {
        return 'product_management';
    }
    return resolveNavLink(item).labelKey;
}
