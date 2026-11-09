/**
 * Role-aware portal navigation for staff and partner shells.
 * Virtual items deep-link into existing section routes/query keys.
 */

export type NavMother = {
    id: string;
    labelKey: string;
    items: string[];
    /** The first row after a thin line in the office menu. */
    startsBlock?: boolean;
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
    policies: 'policies', reports: 'reports',
    customization: 'website', website: 'website', content: 'content', 'public-trust': 'public_trust', enquiries: 'enquiries', feedback: 'feedback',
    'share-feedback': 'share_feedback', partnerships: 'partnerships', audit: 'audit', profile: 'profile',
    security: 'security', notifications: 'notifications', circulars: 'circulars', 'my-money': 'nav_module_earnings',
    ranks: 'ranks', commissions: 'commissions', 'network-tree': 'network_tree',
    'area-dealers': 'area_dealers', 'place-codes': 'place_codes', branches: 'branches',
    'branch-performance': 'branch_performance',
    settlements: 'settlements', simulator: 'simulator', 'payouts-hub': 'payouts_hub', treasury: 'treasury', 'identity-review': 'identity_review',
    'sub-dealers': 'sub_dealers', 'sub-dealer-orders': 'sub_dealer_orders',
    'sub-dealer-applications': 'sub_dealer_applications', 'login-rules': 'login_rules',
    employees: 'employees', dashboards: 'dashboards', help: 'help', 'support-admin': 'support_admin', agreement: 'agreement',
    messages: 'chat_nav_partner', 'partner-messages': 'chat_nav_office', 'top-sellers': 'top_sellers',
    training: 'training', events: 'events', 'data-cleanup': 'cleanup_nav', prescriptions: 'prescriptions', 'sales-kit': 'sales_kit', customers: 'customer_book', schemes: 'dated_schemes', 'privacy-requests': 'privacy_requests_nav',
    purchasing: 'purchasing_nav',
    'stock-counts': 'stock_counts_nav',
    settings: 'nav_module_settings',
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
    /** The order list and one order's page are the same row. */
    orders: {
        ...defaultSpec('orders'),
        match: ({ section }) => ['orders', 'order'].includes(section),
    },
    /** A settings page with its own address, opened only from the Settings page. */
    'courier-areas': {
        id: 'courier-areas',
        section: 'courier-areas',
        labelKey: 'service_integrations',
        hrefPath: 'operations/courier-areas',
        iconKey: 'service-integrations',
    },
    /** One row for the commission page; its tabs keep their own addresses. */
    commissions: {
        id: 'commissions',
        section: 'commissions',
        labelKey: 'nav_commission_hub',
        hrefPath: 'commissions',
        iconKey: 'commissions',
        match: ({ section }) => ['commissions', 'policies', 'simulator', 'policy-schedules'].includes(section),
    },
    /** One partner row for all their money. */
    'partner-money': {
        id: 'partner-money',
        section: 'my-money',
        labelKey: 'nav_module_earnings',
        hrefPath: 'my-money',
        iconKey: 'my-money',
        match: ({ section }) => section === 'my-money' || section === 'team-orders',
    },
    /** One office row for everything in Treasury; its tab strip opens the rest. */
    treasury: {
        id: 'treasury',
        section: 'treasury',
        labelKey: 'treasury',
        hrefPath: 'treasury/funds',
        iconKey: 'treasury',
        match: ({ section }) => section === 'treasury',
    },
    /** The same row for someone who may open only the order report. */
    'treasury-orders': {
        id: 'treasury-orders',
        section: 'treasury-orders',
        labelKey: 'treasury',
        hrefPath: 'treasury/orders',
        iconKey: 'treasury',
        match: ({ section }) => section === 'treasury',
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

/**
 * A sidebar row that holds several pages as tabs. `tabs` are in display order, and
 * `home` is a page of the row's own, opened instead of the first tab.
 * Row ids are repeated in app/Support/OfficeHubs.php and PartnerHubTabs.php.
 */
type MenuRow = { id: string; labelKey: string; iconKey: string; tabs: string[]; home?: string };

const MENU_ROWS: Record<string, MenuRow> = Object.fromEntries(([
    { id: 'office-messages', labelKey: 'nav_module_messages', iconKey: 'messages', tabs: ['partner-messages', 'support-admin', 'enquiries'] },
    { id: 'office-stock', labelKey: 'nav_module_stock', iconKey: 'stock', tabs: ['catalog-products', 'stock', 'stock-lots', 'purchasing', 'stock-counts', 'branches'] },
    { id: 'office-partners', labelKey: 'nav_module_partners', iconKey: 'partners', tabs: ['accounts', 'sub-dealers', 'network-tree', 'area-dealers', 'ranks'] },
    { id: 'office-applications', labelKey: 'nav_module_applications', iconKey: 'partnerships', tabs: ['partnerships', 'sub-dealer-applications'] },
    { id: 'office-for-partners', labelKey: 'nav_module_for_partners', iconKey: 'agreement', tabs: ['schemes', 'contests', 'sales-kit', 'training', 'events', 'agreement'] },
    { id: 'office-staff', labelKey: 'nav_module_people', iconKey: 'people', tabs: ['employees', 'payroll', 'staff-operations', 'field-sales', 'access-management'] },
    { id: 'office-reports', labelKey: 'nav_module_reports', iconKey: 'reports', tabs: ['top-sellers', 'partner-scorecard', 'branch-performance', 'financial-reports'] },
    { id: 'office-website', labelKey: 'label_web', iconKey: 'website', tabs: ['website', 'content', 'public-trust', 'video-marketing', 'feedback'] },
    { id: 'office-settings', labelKey: 'nav_module_settings', iconKey: 'settings', home: 'settings', tabs: [
        'finance-settings', 'payment-accounts', 'financial-readiness', 'place-codes', 'dashboards', 'identity-review',
        'login-rules', 'service-integrations', 'courier-areas', 'data-imports', 'audit', 'privacy-requests', 'data-cleanup',
    ] },
    { id: 'partner-orders', labelKey: 'nav_module_commerce', iconKey: 'orders', tabs: ['orders', 'deliveries'] },
    { id: 'partner-team', labelKey: 'nav_module_network', iconKey: 'network', tabs: ['network', 'customers', 'ranks', 'top-sellers', 'contests'] },
    { id: 'partner-learning', labelKey: 'nav_module_learning', iconKey: 'learning', tabs: ['training', 'events', 'sales-kit'] },
    { id: 'partner-office', labelKey: 'nav_module_office', iconKey: 'messages', tabs: ['messages', 'partner-notices', 'share-feedback', 'help'] },
    { id: 'partner-account', labelKey: 'nav_module_account', iconKey: 'profile', tabs: ['profile', 'security', 'agreement'] },
] satisfies MenuRow[]).map((row) => [row.id, row]));

/** A row item is "rowId@tab,tab": the row and the tabs open to this person, first one first. */
function menuRowSpec(row: MenuRow, open: string[]): NavLinkSpec {
    const first = resolveNavLink(open[0]);
    return {
        id: row.id,
        section: row.home ?? first.section,
        labelKey: row.labelKey,
        hrefPath: row.home ?? first.hrefPath,
        iconKey: row.iconKey,
        match: ({ section, search }) => section === row.id || section === row.home || row.tabs.some((tab) => isNavItemActive(tab, section, search)),
    };
}

export function resolveNavLink(item: string): NavLinkSpec {
    const [rowId, open] = item.split('@');
    const row = MENU_ROWS[rowId];
    if (row) {
        return menuRowSpec(row, open ? open.split(',') : row.tabs);
    }
    return NAV_LINK_SPECS[item] ?? defaultSpec(item);
}

/** The pages a menu item covers: its open tabs for a row, otherwise just itself. A row's badge and search use these. */
export function menuRowTabs(item: string): string[] {
    const [rowId, open] = item.split('@');
    return MENU_ROWS[rowId] && open ? open.split(',') : [item];
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

/** Shows only if a tab is open to this person, opens the first open one, and takes a lone tab's own name. */
function rowItem(rowId: string, available: Set<string>): string | null {
    const open = pick(available, MENU_ROWS[rowId].tabs);
    if (open.length === 0) {
        return null;
    }
    return open.length === 1 ? open[0] : `${rowId}@${open.join(',')}`;
}

function rowMother(rowId: string, available: Set<string>): NavMother | null {
    const item = rowItem(rowId, available);
    return item === null ? null : mother(rowId, MENU_ROWS[rowId].labelKey, [item]);
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
    if (capabilities.some((cap) => ['finance', 'accounts'].includes(cap)) || ['catalog', 'pricing', 'inventory'].every((cap) => capabilities.includes(cap))) items.add('data-imports');
    if (capabilities.includes('finance') || role === 'super_admin') items.add('service-integrations');
    if (capabilities.includes('finance')) {
        ['payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'finance-settings', 'identity-review',
            'commissions', 'policies', 'settlements', 'simulator', 'payouts-hub', 'payments'].forEach((item) => items.add(item));
    }
    const opensTreasury = capabilities.includes('finance') || OWNER_ROLES.includes(role);
    if (opensTreasury) items.add('treasury');
    if (opensTreasury || capabilities.includes('reports')) items.add('treasury-orders');
    else if (capabilities.includes('field_sales')) items.add('financial-reports');
    if (capabilities.some((cap) => ['reports', 'orders', 'finance'].includes(cap))) {
        items.add('top-sellers');
        items.add('partner-scorecard');
    }
    if (role === 'super_admin') items.add('access-management');
    if (capabilities.some((cap) => ['content', 'catalog'].includes(cap))) items.add('video-marketing');
    if (capabilities.includes('accounts')) {
        ['dashboards', 'employees', 'place-codes', 'accounts', 'partnerships', 'sub-dealer-applications', 'network-tree', 'area-dealers', 'ranks', 'sub-dealers', 'enquiries'].forEach((item) => items.add(item));
    }
    // Owners open the account lists to reset or see passwords (PortalCatalog::assertSection).
    if (['super_admin', 'md', 'chairman'].includes(role)) ['accounts', 'sub-dealers', 'employees'].forEach((item) => items.add(item));
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('data-cleanup');
    if (ownerPortal && OWNER_ROLES.includes(role)) items.add('login-rules');
    if (ownerPortal && OWNER_ROLES.includes(role)) items.add('courier-areas');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('sales-kit');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('schemes');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('contests');
    if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(role)) items.add('privacy-requests');
    if (prescriber) items.add('prescriptions');
    if (capabilities.some((cap) => ['catalog', 'pricing', 'inventory', 'orders', 'finance'].includes(cap))) items.add('catalog');
    if (capabilities.includes('inventory')) {
        items.add('inventory');
        items.add('stock-lots');
        items.add('branches');
        items.add('purchasing');
        items.add('stock-counts');
    }
    if ((capabilities.includes('accounts') || OWNER_ROLES.includes(role)) && !items.has('branches')) items.add('branches');
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
        const items = new Set(['overview', 'orders', 'purchase', 'deliveries', 'catalog', 'network', 'ranks', 'top-sellers', 'my-money', 'profile', 'security', 'notifications', 'circulars', 'agreement', 'share-feedback', 'help', 'messages', 'training', 'events']);
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
        items.add('contests');
        return items;
    }
    if (role === 'sub_dealer') {
        const items = new Set(['overview', 'orders', 'purchase', 'deliveries', 'catalog', 'network', 'top-sellers', 'my-money', 'profile', 'security', 'notifications', 'circulars', 'agreement', 'share-feedback', 'help', 'messages', 'training', 'events']);
        if (controls.subdealer_catalog_enabled === false) {
            items.delete('catalog');
            items.delete('purchase');
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
        items.add('contests');
        return items;
    }
    return new Set(['profile', 'security', 'notifications']);
}

/** The office menu: one list of rows in four blocks, with a thin line between blocks. */
function staffMothers(available: Set<string>): NavMother[] {
    const blocks: Array<Array<NavMother | null>> = [
        [
            mother('overview', 'nav_module_overview', pick(available, ['overview'])),
            mother('orders', 'orders', pick(available, ['orders'])),
            mother('payments', 'payments', pick(available, ['payments'])),
            mother('deliveries', 'deliveries', pick(available, ['deliveries'])),
            rowMother('office-messages', available),
        ],
        [
            mother('finance', 'treasury', pick(available, available.has('treasury') ? ['treasury'] : ['treasury-orders'])),
            mother('payouts', 'payouts_hub', pick(available, ['payouts-hub'])),
            mother('commissions', 'nav_commission_hub', pick(available, ['commissions'])),
        ],
        [
            rowMother('office-stock', available),
            rowMother('office-partners', available),
            rowMother('office-applications', available),
            rowMother('office-for-partners', available),
            mother('clinic', 'prescriptions', pick(available, ['prescriptions'])),
        ],
        [
            rowMother('office-staff', available),
            rowMother('office-reports', available),
            rowMother('office-website', available),
            rowMother('office-settings', available),
        ],
    ];

    return blocks.flatMap((block, blockIndex) => compactMothers(block).map((entry, rowIndex) => (blockIndex > 0 && rowIndex === 0 ? { ...entry, startsBlock: true } : entry)));
}

/** The dealer and sub-dealer menu: the day's work first, one row for each kind of work. */
function partnerMothers(role: string, available: Set<string>): NavMother[] {
    const teamAvailable = role === 'dealer' ? available : new Set([...available].filter((item) => item !== 'network'));

    return compactMothers([
        mother('home', 'nav_module_home', pick(available, ['overview'])),
        mother('new-order', 'purchase', pick(available, ['purchase'])),
        rowMother('partner-orders', available),
        mother('products', 'nav_products', pick(available, ['catalog'])),
        mother('earnings', 'nav_module_earnings', pick(available, ['partner-money'])),
        rowMother('partner-team', teamAvailable),
        rowMother('partner-learning', available),
        rowMother('partner-office', available),
        rowMother('partner-account', available),
        mother('clinic', 'prescriptions', pick(available, ['prescriptions'])),
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

/** The sidebar row a page sits in, or the page itself when it is a row of its own. */
function rowItemOf(item: string, available: Set<string>): string {
    const row = Object.values(MENU_ROWS).find((entry) => entry.id.startsWith('office-') && entry.tabs.includes(item));
    return row ? (rowItem(row.id, available) ?? item) : item;
}

/** Phone bottom bar for office staff: home, then up to three of the pages this person can really open, under their row's name. */
export function staffBottomLinks(available: Set<string>): string[] {
    const daily = pick(available, ['orders', 'stock', 'payouts-hub', 'partner-messages', 'website', 'accounts', 'field-sales']);

    return [...pick(available, ['overview']), ...daily.slice(0, 3).map((item) => rowItemOf(item, available))];
}

/** Partners read shorter names for the pages they share with the office. */
const PARTNER_LABEL_KEYS: Record<string, string> = {
    'team-orders': 'team_orders_title',
    overview: 'nav_module_home',
    orders: 'nav_partner_orders',
    catalog: 'nav_products',
    network: 'nav_partner_network',
    profile: 'nav_partner_profile',
    security: 'nav_partner_security',
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
