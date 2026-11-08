/**
 * Role-aware portal navigation for staff and partner shells.
 * Virtual items deep-link into existing section routes/query keys.
 */

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
    /** May answer partner chat: an owner, the Support role, or someone the owner nominated. */
    chatAnswerer?: boolean;
    /** Verified prescriber (partner or office account) or the owner-doctor: shows the Prescriptions menu. */
    prescriber?: boolean;
    dashboardControls?: Record<string, unknown> | null;
};

export const OPERATIONS_SECTIONS = new Set([
    'data-imports', 'service-integrations', 'field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts',
    'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports',
    'finance-settings', 'access-management', 'video-marketing', 'accounting',
]);

const OWNER_ROLES = ['super_admin', 'md', 'chairman'];

const tabOf = (search: string): string | null => new URLSearchParams(search).get('tab');
const actionOf = (search: string): string | null => new URLSearchParams(search).get('action');

/** Section id → i18n portal.* key for default labels. */
export const SECTION_LABEL_KEYS: Record<string, string> = {
    'field-sales': 'field_sales', 'staff-operations': 'staff_operations', payroll: 'payroll', 'my-payroll': 'my_payroll',
    'payment-accounts': 'payment_accounts', 'policy-schedules': 'policy_schedules', 'reserve-payouts': 'reserve_payouts',
    'data-imports': 'data_imports', 'service-integrations': 'service_integrations', 'commission-holds': 'commission_holds',
    'financial-readiness': 'financial_readiness', 'financial-reports': 'financial_reports',
    'finance-settings': 'finance_settings',
    accounting: 'accounting',
    'access-management': 'access_management', 'video-marketing': 'video_marketing',
    overview: 'overview', orders: 'orders', order: 'order_details', purchase: 'purchase', catalog: 'catalog', accounts: 'accounts',
    inventory: 'inventory', deliveries: 'deliveries', payments: 'payments', network: 'network',
    policies: 'policies', salary: 'salary', withdrawals: 'withdrawals', ledger: 'ledger', reports: 'reports',
    customization: 'website', website: 'website', content: 'content', 'public-trust': 'public_trust', enquiries: 'enquiries', feedback: 'feedback',
    'share-feedback': 'share_feedback', partnerships: 'partnerships', audit: 'audit', profile: 'profile',
    security: 'security', notifications: 'notifications', circulars: 'circulars', earnings: 'earnings',
    'full-report': 'full_report', ranks: 'ranks', commissions: 'commissions', 'network-tree': 'network_tree',
    'area-dealers': 'area_dealers', 'place-codes': 'place_codes', branches: 'branches',
    'branch-performance': 'branch_performance', rewards: 'rewards', 'capital-flow': 'capital_flow',
    settlements: 'settlements', simulator: 'simulator', 'payouts-hub': 'payouts_hub', treasury: 'treasury', 'company-funds': 'company_funds',
    'benefits-fund': 'benefits_fund', 'sub-dealers': 'sub_dealers', 'sub-dealer-orders': 'sub_dealer_orders',
    'sub-dealer-applications': 'sub_dealer_applications', 'login-rules': 'login_rules',
    employees: 'employees', dashboards: 'dashboards', help: 'help', 'support-admin': 'support_admin', agreement: 'agreement',
    messages: 'chat_nav_partner', 'partner-messages': 'chat_nav_office', 'top-sellers': 'top_sellers',
    training: 'training', events: 'events', 'payment-receipts': 'payment_receipts', 'data-cleanup': 'cleanup_nav', prescriptions: 'prescriptions', 'sales-kit': 'sales_kit', customers: 'customer_book', schemes: 'dated_schemes', 'privacy-requests': 'privacy_requests_nav',
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
    /** One row for the commission page; its tabs keep their own addresses. */
    commissions: {
        id: 'commissions',
        section: 'commissions',
        labelKey: 'nav_commission_hub',
        hrefPath: 'commissions',
        iconKey: 'commissions',
        match: ({ section }) => ['commissions', 'policies', 'simulator', 'policy-schedules'].includes(section),
    },
    /** One partner row for the money pages; the page's tabs open the others. */
    'partner-money': {
        id: 'partner-money',
        section: 'earnings',
        labelKey: 'nav_module_earnings',
        hrefPath: 'earnings',
        iconKey: 'earnings',
        match: ({ section }) => ['earnings', 'withdrawals', 'payment-receipts', 'full-report'].includes(section),
    },
    /** One partner row for notices; its tabs open office notices and the partner's own updates. */
    'partner-notices': {
        id: 'partner-notices',
        section: 'circulars',
        labelKey: 'circulars',
        hrefPath: 'circulars',
        iconKey: 'circulars',
        match: ({ section }) => ['circulars', 'notifications'].includes(section),
    },
    'catalog-products': {
        id: 'catalog-products',
        section: 'catalog',
        labelKey: 'nav_products',
        hrefPath: 'catalog',
        iconKey: 'catalog',
        match: ({ section }) => section === 'catalog',
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
        labelKey: 'inventory',
        hrefPath: 'inventory',
        iconKey: 'inventory',
        match: ({ section }) => section === 'inventory',
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
    const { capabilities, role, ownerPortal, chatAnswerer, prescriber } = ctx;
    const items = new Set<string>(['overview', 'profile', 'security', 'notifications', 'help']);
    if (capabilities.includes('accounts') || ownerPortal) {
        items.add('support-admin');
        items.add('agreement');
    }
    if (capabilities.includes('support_desk')) {
        items.add('support-admin');
        items.add('enquiries');
    }
    if (chatAnswerer || ownerPortal) items.add('partner-messages');
    if (capabilities.includes('field_sales')) items.add('field-sales');
    if (capabilities.includes('staff_admin')) items.add('staff-operations');
    if (capabilities.includes('payroll')) items.add('payroll');
    if (!OWNER_ROLES.includes(role)) items.add('my-payroll');
    if (capabilities.some((cap) => ['finance', 'accounts'].includes(cap))) items.add('data-imports');
    if (capabilities.includes('finance') || role === 'super_admin') items.add('service-integrations');
    if (capabilities.includes('finance')) {
        ['payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'finance-settings', 'treasury', 'company-funds',
            'commissions', 'policies', 'ledger', 'rewards', 'settlements', 'simulator', 'payouts-hub', 'payments', 'salary', 'accounting'].forEach((item) => items.add(item));
    }
    if (capabilities.some((cap) => ['finance', 'reports', 'field_sales'].includes(cap))) items.add('financial-reports');
    if (capabilities.some((cap) => ['reports', 'orders', 'finance'].includes(cap))) {
        items.add('top-sellers');
    }
    if (role === 'super_admin') items.add('access-management');
    if (capabilities.some((cap) => ['content', 'catalog'].includes(cap))) items.add('video-marketing');
    if (capabilities.includes('accounts')) {
        ['dashboards', 'employees', 'place-codes', 'accounts', 'partnerships', 'sub-dealer-applications', 'network-tree', 'area-dealers', 'ranks', 'sub-dealers', 'enquiries'].forEach((item) => items.add(item));
    }
    // Owners open the account lists to reset or see passwords (PortalCatalog::assertSection).
    if (['super_admin', 'md', 'chairman'].includes(role)) ['accounts', 'sub-dealers', 'employees'].forEach((item) => items.add(item));
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('benefits-fund');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('data-cleanup');
    if (ownerPortal && OWNER_ROLES.includes(role)) items.add('login-rules');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('sales-kit');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('schemes');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('privacy-requests');
    if (prescriber) items.add('prescriptions');
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
        ['website', 'content', 'public-trust', 'enquiries', 'feedback'].forEach((item) => items.add(item));
    }
    if (capabilities.includes('content') || ownerPortal) {
        items.add('training');
        items.add('events');
    }
    if (capabilities.includes('audit')) items.add('audit');
    if (capabilities.includes('orders') || capabilities.includes('finance')) {
        ['orders', 'deliveries'].forEach((item) => items.add(item));
    }
    return items;
}

export function buildPartnerAvailable(role: string, dashboardControls?: Record<string, unknown> | null, prescriber = false): Set<string> {
    const controls = dashboardControls || {};
    if (role === 'dealer') {
        const items = new Set(['overview', 'orders', 'purchase', 'deliveries', 'catalog', 'network', 'ranks', 'top-sellers', 'earnings', 'full-report', 'withdrawals', 'payment-receipts', 'profile', 'security', 'notifications', 'circulars', 'agreement', 'share-feedback', 'help', 'messages', 'training', 'events']);
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
        if (controls.top_sellers_enabled === false) {
            items.delete('top-sellers');
        }
        if (controls.dealer_orders_enabled === false) {
            items.delete('orders');
            items.delete('purchase');
            items.delete('deliveries');
        }
        if (prescriber) {
            items.add('prescriptions');
        }
        items.add('sales-kit');
        items.add('customers');
        return items;
    }
    if (role === 'sub_dealer') {
        const items = new Set(['overview', 'orders', 'purchase', 'deliveries', 'catalog', 'salary', 'top-sellers', 'earnings', 'full-report', 'withdrawals', 'payment-receipts', 'profile', 'security', 'notifications', 'circulars', 'agreement', 'share-feedback', 'help', 'messages', 'training', 'events']);
        if (controls.subdealer_catalog_enabled === false) {
            items.delete('catalog');
            items.delete('purchase');
        }
        if (controls.subdealer_salary_enabled === false) {
            items.delete('salary');
        }
        if (controls.top_sellers_enabled === false) {
            items.delete('top-sellers');
        }
        if (controls.subdealer_deliveries_enabled === false) {
            items.delete('deliveries');
        }
        if (controls.subdealer_orders_enabled === false) {
            items.delete('orders');
            items.delete('purchase');
        }
        if (prescriber) {
            items.add('prescriptions');
        }
        items.add('sales-kit');
        items.add('customers');
        return items;
    }
    return new Set(['profile', 'security', 'notifications']);
}

/** The office menu: one list of groups, daily work first and rarely used settings last. */
function staffMothers(available: Set<string>): NavMother[] {
    return compactMothers([
        mother('overview', 'nav_module_overview', pick(available, ['overview'])),
        mother('orders', 'nav_module_orders', pick(available, ['orders', 'payments', 'deliveries'])),
        mother('messages', 'nav_module_messages', pick(available, ['partner-messages', 'support-admin', 'enquiries', 'feedback'])),
        mother('clinic', 'nav_module_clinic', pick(available, ['prescriptions'])),
        mother('payouts', 'nav_module_payouts', pick(available, ['payouts-hub', 'settlements', 'salary'])),
        mother('partners', 'nav_module_partners', pick(available, [
            'accounts', 'sub-dealers', 'partnerships', 'sub-dealer-applications', 'network-tree', 'area-dealers', 'ranks', 'agreement', 'schemes', 'sales-kit',
        ])),
        mother('stock', 'nav_module_stock', pick(available, ['catalog-products', 'stock', 'branches'])),
        mother('finance', 'nav_module_finance', pick(available, ['treasury', 'company-funds', 'reserve-payouts', 'benefits-fund', 'ledger', 'accounting', 'payment-accounts'])),
        mother('commissions', 'nav_module_commissions', pick(available, [
            'commissions', 'commission-holds', 'rewards',
        ])),
        mother('people', 'nav_module_people', pick(available, ['employees', 'payroll', 'staff-operations', 'field-sales', 'access-management'])),
        mother('reports', 'nav_module_reports', pick(available, ['financial-reports', 'top-sellers', 'branch-performance'])),
        mother('website', 'nav_module_website', pick(available, ['website', 'content', 'public-trust', 'video-marketing', 'training', 'events'])),
        mother('settings', 'nav_module_settings', pick(available, [
            'finance-settings', 'place-codes', 'dashboards', 'login-rules', 'audit', 'service-integrations', 'data-imports', 'financial-readiness', 'data-cleanup', 'privacy-requests',
        ])),
    ]);
}

/** The dealer and sub-dealer menu: the day's work first. A group with one page shows as a plain link. */
function partnerMothers(role: string, available: Set<string>): NavMother[] {
    const shared = {
        home: mother('home', 'nav_module_home', pick(available, ['overview'])),
        newOrder: mother('new-order', 'purchase', pick(available, ['purchase'])),
        orders: mother('commerce', 'nav_module_commerce', pick(available, ['orders', 'deliveries', 'catalog', ...(role === 'dealer' ? [] : ['customers'])])),
        money: mother('earnings', 'nav_module_earnings', pick(available, ['partner-money'])),
        clinic: mother('clinic', 'nav_module_clinic', pick(available, ['prescriptions'])),
        office: mother('office', 'nav_module_office', pick(available, ['messages', 'partner-notices', 'sales-kit', 'share-feedback', 'help'])),
        account: mother('account', 'nav_module_account', pick(available, ['profile', 'security', 'agreement'])),
    };
    if (role === 'dealer') {
        return compactMothers([
            shared.home,
            shared.newOrder,
            shared.orders,
            shared.money,
            mother('network', 'nav_module_network', pick(available, ['network', 'customers', 'ranks', 'top-sellers'])),
            mother('learning', 'nav_module_learning', pick(available, ['training', 'events'])),
            shared.clinic,
            shared.office,
            shared.account,
        ]);
    }
    return compactMothers([
        shared.home,
        shared.newOrder,
        shared.orders,
        mother('compensation', 'nav_module_compensation', pick(available, ['salary'])),
        shared.money,
        mother('learning', 'nav_module_learning', pick(available, ['training', 'events', 'top-sellers'])),
        shared.clinic,
        shared.office,
        shared.account,
    ]);
}

export function buildNavMothers(input: {
    staff: boolean;
    role: string;
    staffAvailable: Set<string>;
    partnerAvailable: Set<string>;
}): NavMother[] {
    const { staff, role, staffAvailable, partnerAvailable } = input;
    if (staff) {
        return staffMothers(staffAvailable);
    }
    if (role === 'dealer' || role === 'sub_dealer') {
        return partnerMothers(role, partnerAvailable);
    }

    return compactMothers([
        mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'help'])),
    ]);
}

/** Phone bottom bar for office staff: home, then up to three of the pages this person can really open. */
export function staffBottomLinks(available: Set<string>): string[] {
    const daily = pick(available, ['orders', 'stock', 'payouts-hub', 'partner-messages', 'website', 'accounts', 'field-sales']);

    return [...pick(available, ['overview']), ...daily.slice(0, 3)];
}

/** Partners read shorter names for the pages they share with the office. */
const PARTNER_LABEL_KEYS: Record<string, string> = {
    orders: 'nav_partner_orders',
    catalog: 'nav_partner_catalog',
    earnings: 'nav_partner_earnings',
    network: 'nav_partner_network',
    profile: 'nav_partner_profile',
    security: 'nav_partner_security',
    salary: 'nav_partner_salary',
};

export function labelKeyForNavItem(item: string, staff: boolean): string {
    if (item === 'catalog' && staff) {
        return 'product_management';
    }
    if (!staff && PARTNER_LABEL_KEYS[item]) {
        return PARTNER_LABEL_KEYS[item];
    }
    return resolveNavLink(item).labelKey;
}
