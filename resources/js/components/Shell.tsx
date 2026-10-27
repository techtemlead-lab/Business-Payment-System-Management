import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import {
    LayoutDashboard, ShoppingBag, Package, Users, Truck, Wallet, GitBranch,
    Settings, ShieldCheck, FileText, Leaf, Bell, Menu, X, LogOut, ArrowUpRight,
    Coins, BadgeCheck, BookOpen, MessageSquare, SlidersHorizontal, MapPin, Award,
    Trophy, TrendingUp, Percent, Store, Landmark, UserCheck, AlertTriangle,
    Briefcase, Hash, FileBarChart2, ChevronDown, UserRound, LifeBuoy, ArrowLeftRight, type LucideIcon
} from 'lucide-react';
import { Flash } from './UI';
import { DealerHeader, DealerBottomNav } from './PortalHeader';
import LanguageSwitcher from './LanguageSwitcher';
import { useT } from '../shared/i18n/t';

const icons: Record<string, LucideIcon> = {
    'field-sales': MapPin, 'staff-operations': UserCheck, payroll: Coins, 'my-payroll': Wallet,
    'payment-accounts': Landmark, 'policy-schedules': Percent, 'reserve-payouts': Award,
    'data-imports': FileText, 'service-integrations': Settings, 'commission-holds': ShieldCheck, 'financial-readiness': BookOpen, 'financial-reports': FileText,
    overview: LayoutDashboard, orders: ShoppingBag, catalog: Package, accounts: Users, inventory: Package, deliveries: Truck, dues: Wallet,
    payments: Coins, network: GitBranch, policies: SlidersHorizontal, salary: BadgeCheck, withdrawals: Wallet, ledger: BookOpen,
    reports: FileText, customization: SlidersHorizontal, website: SlidersHorizontal, content: FileText, enquiries: MessageSquare, partnerships: Briefcase, audit: ShieldCheck,
    profile: Users, security: Settings, notifications: Bell, circulars: BookOpen, earnings: Coins, 'full-report': FileBarChart2, ranks: Award,
    commissions: Percent, 'network-tree': GitBranch, 'area-dealers': MapPin, 'place-codes': Hash, rewards: Trophy, 'capital-flow': TrendingUp,
    settlements: BadgeCheck, simulator: SlidersHorizontal, 'payouts-hub': Wallet,
    treasury: Landmark, 'benefits-fund': ShieldCheck, 'sub-dealers': Store, 'sub-dealer-orders': ShoppingBag,
    employees: UserCheck, dashboards: SlidersHorizontal, help: LifeBuoy, 'support-admin': MessageSquare
};

const sectionKeys: Record<string, string> = {
    'field-sales': 'field_sales', 'staff-operations': 'staff_operations', payroll: 'payroll', 'my-payroll': 'my_payroll',
    'payment-accounts': 'payment_accounts', 'policy-schedules': 'policy_schedules', 'reserve-payouts': 'reserve_payouts',
    'data-imports': 'data_imports', 'service-integrations': 'service_integrations', 'commission-holds': 'commission_holds', 'financial-readiness': 'financial_readiness',
    'financial-reports': 'financial_reports', 'dues-adjustments': 'dues_adjustments', 'finance-settings': 'finance_settings',
    'access-management': 'access_management', 'video-marketing': 'video_marketing',
    overview: 'overview', orders: 'orders', catalog: 'catalog', accounts: 'accounts', inventory: 'inventory',
    deliveries: 'deliveries', dues: 'dues', payments: 'payments', network: 'network', policies: 'policies',
    salary: 'salary', withdrawals: 'withdrawals', ledger: 'ledger', reports: 'reports', customization: 'website',
    website: 'website', content: 'content', enquiries: 'enquiries', partnerships: 'partnerships', audit: 'audit', profile: 'profile', security: 'security',
    notifications: 'notifications', circulars: 'circulars', earnings: 'earnings', 'full-report': 'full-report', ranks: 'ranks', commissions: 'commissions',
    'network-tree': 'network_tree', 'area-dealers': 'area_dealers', 'place-codes': 'place_codes', rewards: 'rewards', 'capital-flow': 'capital_flow',
    settlements: 'settlements', simulator: 'simulator', 'payouts-hub': 'payouts_hub', treasury: 'treasury',
    'benefits-fund': 'benefits_fund',
    'sub-dealers': 'sub_dealers', 'sub-dealer-orders': 'sub_dealer_orders', employees: 'employees', dashboards: 'dashboards',
    help: 'help', 'support-admin': 'support_admin',
};

const operationsSections = new Set([
    'data-imports', 'service-integrations', 'field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts',
    'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports',
    'dues-adjustments', 'finance-settings', 'access-management', 'video-marketing',
]);

type NavMother = {
    id: string;
    labelKey: string;
    items: string[];
};

function pick(available: Set<string>, items: string[]): string[] {
    return items.filter((item) => available.has(item));
}

function mother(id: string, labelKey: string, items: string[]): NavMother | null {
    return items.length > 0 ? { id, labelKey, items } : null;
}

function compactMothers(mothers: Array<NavMother | null>): NavMother[] {
    return mothers.filter((entry): entry is NavMother => entry !== null);
}

export default function Shell({ section, children, action }: { section: string; children: ReactNode; action?: ReactNode }) {
    const { auth, portalBase, portal, demoMode, dashboardNotice, dashboardControls, publicPageEnabled, publicBase, unreadNotices = 0 } = usePage<any>().props;
    const unreadCount = Number(unreadNotices ?? 0);
    const translate = useT();
    const user = auth.user;
    const capabilities: string[] = auth.capabilities;
    const workspaceMode = String(user.workspace_mode || 'partner');
    const staff = portal === 'manage' || portal === 'sadmin' || workspaceMode === 'staff';
    const ownerPortal = portal === 'sadmin';
    const base = portalBase || '';
    const shellClass = portal === 'sadmin' ? 'owner-shell' : (portal === 'manage' || workspaceMode === 'staff') ? 'company-shell' : portal === 'subdealer' ? 'subdealer-shell' : 'dealer-shell';
    const portalLabel = ownerPortal
        ? translate('portal', 'owner_portal')
        : staff
            ? translate('portal', 'company_portal')
            : portal === 'subdealer'
                ? translate('portal', 'subdealer_portal')
                : translate('portal', 'dealer_portal');
    const dashboardTitle = ownerPortal
        ? translate('portal', 'owner_dashboard')
        : staff
            ? translate('portal', 'company_dashboard')
            : translate('portal', 'my_account');
    const sectionLabel = (item: string) => {
        if (item === 'catalog' && staff) {
            return translate('portal', 'product_management');
        }
        const key = sectionKeys[item];
        return key ? translate('portal', key) : translate('portal', 'order_details');
    };
    const hubLabel = (() => {
        if (ownerPortal) {
            const shop = typeof user.shop_name === 'string' && user.shop_name.trim() !== '' ? user.shop_name.trim() : null;
            return shop
                ? translate('portal', 'owner_hub_named', { shop })
                : translate('portal', 'owner_workspace');
        }
        if (user.role === 'employee' || user.role === 'sr') {
            return translate('portal', 'staff_terminal');
        }
        if (staff) {
            return translate('portal', 'company_workspace');
        }
        if (user.role === 'sub_dealer') {
            return translate('portal', 'subdealer_workspace');
        }
        return translate('portal', 'dealer_workspace');
    })();

    const [mobile, setMobile] = useState(() => typeof window !== 'undefined' && matchMedia('(max-width:1023px)').matches);

    useEffect(() => {
        const media = matchMedia('(max-width:1023px)');
        const change = () => setMobile(media.matches);
        change();
        media.addEventListener('change', change);
        return () => media.removeEventListener('change', change);
    }, []);

    const [open, setOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const sidebar = useRef<HTMLElement>(null);
    const toggle = useRef<HTMLButtonElement>(null);
    const profileMenu = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        sidebar.current?.querySelector<HTMLElement>('a')?.focus();
        const keydown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
            if (event.key === 'Tab') {
                const items = sidebar.current?.querySelectorAll<HTMLElement>('a, button:not(:disabled)');
                if (!items?.length) return;
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        };
        document.addEventListener('keydown', keydown);
        return () => { document.body.style.overflow = previousOverflow; document.removeEventListener('keydown', keydown); toggle.current?.focus(); };
    }, [open]);

    useEffect(() => {
        if (!profileOpen) return;
        const onPointer = (event: MouseEvent) => {
            if (!profileMenu.current?.contains(event.target as Node)) {
                setProfileOpen(false);
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setProfileOpen(false);
        };
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [profileOpen]);

    const dealerOnlySections = ['accounts', 'partnerships', 'area-dealers'];
    const subDealerOnlySections = ['sub-dealers', 'salary'];
    const sharedPartnerSections = ['commissions', 'policies', 'simulator', 'network-tree', 'ranks'];
    const accountSections = ['profile', 'security', 'notifications', 'help'];
    const managementSections = [
        'overview', 'employees', 'dashboards', 'access-management', 'my-payroll', 'payroll', 'staff-operations', 'field-sales',
        'orders', 'deliveries', 'dues', 'sub-dealer-orders',
        'treasury', 'benefits-fund', 'payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds',
        'financial-readiness', 'dues-adjustments', 'finance-settings', 'financial-reports', 'data-imports', 'service-integrations',
        'rewards', 'settlements', 'payouts-hub', 'payments', 'withdrawals', 'ledger', 'reports',
        'catalog', 'inventory', 'place-codes', 'video-marketing', 'website', 'content', 'enquiries', 'audit', 'support-admin',
    ];

    const readDomainPin = (): 'company' | 'dealers' | 'sub_dealers' | null => {
        if (typeof window === 'undefined') {
            return null;
        }
        try {
            const pin = window.sessionStorage.getItem('madina.staff.domain');
            return pin === 'company' || pin === 'dealers' || pin === 'sub_dealers' ? pin : null;
        } catch {
            return null;
        }
    };

    const writeDomainPin = (domain: 'company' | 'dealers' | 'sub_dealers') => {
        if (typeof window === 'undefined') {
            return;
        }
        try {
            window.sessionStorage.setItem('madina.staff.domain', domain);
        } catch {
            // Ignore private-mode storage failures.
        }
    };

    const resolvedDomain = ((): 'company' | 'dealers' | 'sub_dealers' => {
        if (dealerOnlySections.includes(section)) {
            return 'dealers';
        }
        if (subDealerOnlySections.includes(section)) {
            return 'sub_dealers';
        }
        if (sharedPartnerSections.includes(section)) {
            const pin = readDomainPin();
            if (pin === 'dealers' || pin === 'sub_dealers') {
                return pin;
            }
            return 'dealers';
        }
        if (accountSections.includes(section)) {
            return readDomainPin() ?? 'company';
        }
        if (managementSections.includes(section) || section === '') {
            return 'company';
        }
        const pin = readDomainPin();
        return pin ?? 'company';
    })();
    const currentDomain = resolvedDomain;

    const staffAvailable = useMemo(() => {
        const items = new Set<string>(['overview', 'profile', 'security', 'notifications', 'help']);
        if (capabilities.includes('accounts') || ownerPortal) items.add('support-admin');
        if (capabilities.includes('field_sales')) items.add('field-sales');
        if (capabilities.includes('staff_admin')) items.add('staff-operations');
        if (capabilities.includes('payroll')) items.add('payroll');
        items.add('my-payroll');
        if (capabilities.some((cap) => ['finance', 'accounts'].includes(cap))) items.add('data-imports');
        if (capabilities.includes('finance') || user.role === 'super_admin') items.add('service-integrations');
        if (capabilities.includes('finance')) {
            ['payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'dues-adjustments', 'finance-settings', 'treasury',
                'commissions', 'policies', 'ledger', 'reports', 'rewards', 'settlements', 'simulator', 'payouts-hub', 'payments', 'withdrawals', 'salary'].forEach((item) => items.add(item));
        }
        if (capabilities.some((cap) => ['finance', 'reports', 'field_sales'].includes(cap))) items.add('financial-reports');
        if (capabilities.some((cap) => ['reports', 'orders', 'finance'].includes(cap))) items.add('reports');
        if (user.role === 'super_admin') items.add('access-management');
        if (capabilities.some((cap) => ['content', 'catalog'].includes(cap))) items.add('video-marketing');
        if (capabilities.includes('accounts')) {
            ['dashboards', 'employees', 'place-codes', 'accounts', 'partnerships', 'network-tree', 'area-dealers', 'ranks', 'sub-dealers', 'enquiries'].forEach((item) => items.add(item));
        }
        if (ownerPortal && ['super_admin', 'md', 'chairman'].includes(user.role)) items.add('benefits-fund');
        if (capabilities.some((cap) => ['catalog', 'pricing', 'inventory', 'orders', 'finance'].includes(cap))) items.add('catalog');
        if (capabilities.includes('inventory')) items.add('inventory');
        if (capabilities.includes('content')) {
            ['website', 'content', 'enquiries'].forEach((item) => items.add(item));
        }
        if (capabilities.includes('audit')) items.add('audit');
        if (capabilities.includes('orders') || capabilities.includes('finance')) {
            ['orders', 'deliveries', 'dues', 'sub-dealer-orders'].forEach((item) => items.add(item));
        }
        return items;
    }, [capabilities, ownerPortal, user.role]);

    const partnerAvailable = useMemo(() => {
        const controls = dashboardControls || {};
        if (user.role === 'dealer') {
            const items = new Set(['overview', 'orders', 'deliveries', 'dues', 'catalog', 'network', 'ranks', 'earnings', 'full-report', 'withdrawals', 'profile', 'security', 'notifications', 'circulars', 'help']);
            if (controls.dealer_catalog_enabled === false) {
                items.delete('catalog');
            }
            if (controls.dealer_network_tree_enabled === false) {
                items.delete('network');
            }
            if (controls.dealer_ranks_enabled === false) {
                items.delete('ranks');
            }
            if (controls.dealer_orders_enabled === false) {
                items.delete('orders');
                items.delete('deliveries');
                items.delete('dues');
            }
            return items;
        }
        if (user.role === 'sub_dealer') {
            const items = new Set(['overview', 'orders', 'deliveries', 'dues', 'catalog', 'salary', 'earnings', 'full-report', 'withdrawals', 'profile', 'security', 'notifications', 'circulars', 'help']);
            if (controls.subdealer_catalog_enabled === false) {
                items.delete('catalog');
            }
            if (controls.subdealer_salary_enabled === false) {
                items.delete('salary');
            }
            if (controls.subdealer_deliveries_enabled === false) {
                items.delete('deliveries');
            }
            if (controls.subdealer_orders_enabled === false) {
                items.delete('orders');
                items.delete('dues');
            }
            return items;
        }
        return new Set(['profile', 'security', 'notifications']);
    }, [user.role, dashboardControls]);

    const domainMothers = useMemo((): NavMother[] => {
        if (!staff) {
            if (user.role === 'dealer') {
                return compactMothers([
                    mother('home', 'nav_module_home', pick(partnerAvailable, ['overview'])),
                    mother('commerce', 'nav_module_commerce', pick(partnerAvailable, ['orders', 'deliveries', 'dues', 'catalog'])),
                    mother('network', 'nav_module_network', pick(partnerAvailable, ['network', 'ranks'])),
                    mother('earnings', 'nav_module_earnings', pick(partnerAvailable, ['earnings', 'full-report', 'withdrawals'])),
                    mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'circulars', 'help'])),
                ]);
            }
            if (user.role === 'sub_dealer') {
                return compactMothers([
                    mother('home', 'nav_module_home', pick(partnerAvailable, ['overview'])),
                    mother('commerce', 'nav_module_commerce', pick(partnerAvailable, ['orders', 'deliveries', 'dues', 'catalog'])),
                    mother('earnings', 'nav_module_earnings', pick(partnerAvailable, ['salary', 'earnings', 'full-report', 'withdrawals'])),
                    mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'circulars', 'help'])),
                ]);
            }
            return compactMothers([
                mother('account', 'nav_module_account', pick(partnerAvailable, ['profile', 'security', 'notifications', 'help'])),
            ]);
        }

        const company = compactMothers([
            mother('home', 'nav_module_home', pick(staffAvailable, ['overview'])),
            mother('people', 'nav_module_people', pick(staffAvailable, ['employees', 'dashboards', 'access-management', 'my-payroll', 'payroll', 'staff-operations', 'field-sales'])),
            mother('fulfillment', 'nav_module_fulfillment', pick(staffAvailable, ['orders', 'deliveries', 'dues', 'sub-dealer-orders'])),
            mother('finance_ops', 'nav_module_finance_ops', pick(staffAvailable, [
                'treasury', 'benefits-fund', 'payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds',
                'financial-readiness', 'dues-adjustments', 'finance-settings', 'financial-reports', 'data-imports', 'service-integrations',
                'rewards', 'settlements', 'payouts-hub', 'payments', 'withdrawals', 'ledger', 'reports',
            ])),
            mother('products', 'nav_module_products', pick(staffAvailable, ['catalog', 'inventory', 'place-codes', 'video-marketing'])),
            mother('website', 'nav_module_website', pick(staffAvailable, ['website', 'content', 'enquiries'])),
            mother('control', 'nav_module_control', pick(staffAvailable, ['audit', 'support-admin'])),
        ]);

        const dealers = compactMothers([
            mother('directory', 'nav_module_directory', pick(staffAvailable, ['accounts', 'partnerships'])),
            mother('commission', 'nav_module_commission', pick(staffAvailable, ['commissions', 'policies', 'simulator'])),
            mother('network', 'nav_module_network', pick(staffAvailable, ['network-tree', 'area-dealers', 'ranks'])),
        ]);

        const subDealers = compactMothers([
            mother('directory', 'nav_module_directory', pick(staffAvailable, ['sub-dealers'])),
            mother('commission', 'nav_module_commission', pick(staffAvailable, ['commissions', 'policies', 'simulator'])),
            mother('network', 'nav_module_network', pick(staffAvailable, ['network-tree', 'ranks'])),
            mother('compensation', 'nav_module_compensation', pick(staffAvailable, ['salary'])),
        ]);

        const account = compactMothers([
            mother('account', 'nav_module_account', pick(staffAvailable, ['profile', 'security', 'notifications', 'help'])),
        ]);

        const domainModules = currentDomain === 'dealers'
            ? dealers
            : currentDomain === 'sub_dealers'
                ? subDealers
                : company;

        return [...domainModules, ...account];
    }, [staff, user.role, partnerAvailable, staffAvailable, currentDomain]);

    const activeMotherIds = useMemo(
        () => domainMothers.filter((entry) => entry.items.includes(section)).map((entry) => entry.id),
        [domainMothers, section],
    );

    const [expandedMothers, setExpandedMothers] = useState<string[]>(() => activeMotherIds);

    useEffect(() => {
        setExpandedMothers((previous) => {
            const next = new Set(previous);
            activeMotherIds.forEach((id) => next.add(id));
            if (next.size === 0 && domainMothers[0]) {
                next.add(domainMothers[0].id);
            }
            return Array.from(next);
        });
    }, [activeMotherIds, domainMothers]);

    const toggleMother = (id: string) => {
        setExpandedMothers((previous) => (
            previous.includes(id) ? previous.filter((entry) => entry !== id) : [...previous, id]
        ));
    };

    const sectionHref = (item: string) => `${base}/${operationsSections.has(item) ? 'operations/' : ''}${item === 'overview' ? '' : item}`;

    const renderLink = (item: string) => {
        const Icon = icons[item] || LayoutDashboard;
        return (
            <Link
                aria-current={section === item ? 'page' : undefined}
                className={section === item ? 'selected' : ''}
                href={sectionHref(item)}
                key={item}
                onClick={() => {
                    if (staff) {
                        writeDomainPin(currentDomain);
                    }
                    setOpen(false);
                }}
                data-tour={item === 'help' ? 'sidebar-help' : undefined}
            >
                <Icon size={17} />
                {sectionLabel(item)}
                {section === item && <i />}
            </Link>
        );
    };

    const renderMothers = (mothers: NavMother[]) => mothers.map((entry) => {
        const expanded = expandedMothers.includes(entry.id);
        const hasActive = entry.items.includes(section);
        return (
            <nav
                key={entry.id}
                className={`nav-group nav-mother ${hasActive ? 'has-active' : ''} ${expanded ? 'is-open' : ''}`}
                aria-label={translate('portal', entry.labelKey)}
            >
                <button
                    type="button"
                    className="nav-mother-toggle"
                    aria-expanded={expanded}
                    onClick={() => toggleMother(entry.id)}
                >
                    <span>{translate('portal', entry.labelKey)}</span>
                    <ChevronDown size={14} className="nav-chevron" aria-hidden="true" />
                </button>
                {expanded && (
                    <div className="nav-mother-body">
                        {entry.items.map(renderLink)}
                    </div>
                )}
            </nav>
        );
    });

    const canDealerDomain = capabilities.some((c) => ['accounts', 'orders', 'finance'].includes(c));

    const domainTabs = staff ? (
        <>
            <Link
                href="/"
                onClick={() => {
                    writeDomainPin('company');
                    setOpen(false);
                }}
                className={`sidebar-domain-tab domain-tab-company ${currentDomain === 'company' ? 'active' : ''}`}
                aria-current={currentDomain === 'company' ? 'page' : undefined}
            >
                <Briefcase size={14} />
                {ownerPortal ? translate('portal', 'nav_management_section') : translate('portal', 'nav_company_section')}
            </Link>
            {canDealerDomain && (
                <Link
                    href={capabilities.includes('accounts') ? '/accounts' : '/commissions'}
                    onClick={() => {
                        writeDomainPin('dealers');
                        setOpen(false);
                    }}
                    className={`sidebar-domain-tab domain-tab-dealers ${currentDomain === 'dealers' ? 'active' : ''}`}
                    aria-current={currentDomain === 'dealers' ? 'page' : undefined}
                >
                    <Users size={14} />
                    {translate('portal', 'nav_dealer_section')}
                </Link>
            )}
            {canDealerDomain && (
                <Link
                    href={capabilities.includes('accounts') ? '/sub-dealers' : (capabilities.includes('finance') ? '/salary' : '/commissions')}
                    onClick={() => {
                        writeDomainPin('sub_dealers');
                        setOpen(false);
                    }}
                    className={`sidebar-domain-tab domain-tab-subdealers ${currentDomain === 'sub_dealers' ? 'active' : ''}`}
                    aria-current={currentDomain === 'sub_dealers' ? 'page' : undefined}
                >
                    <Store size={14} />
                    {translate('portal', 'nav_subdealer_section')}
                </Link>
            )}
        </>
    ) : null;

    const currentSectionTitle = section === 'overview'
        ? dashboardTitle
        : sectionLabel(section);
    const currentSectionHint = section === 'overview'
        ? translate('portal', 'welcome_back', { name: user.name.split(' ')[0], scope: translate('portal', staff ? 'business' : 'account') })
        : (section === 'catalog' && staff
            ? translate('portal', 'catalog_staff_hint')
            : translate('portal', 'section_hint'));

    const signOut = () => {
        setProfileOpen(false);
        router.post('/logout');
    };

    return (
        <div className={`app-shell ${shellClass}`}>
            <a className="skip-link" href="#workspace-main">{translate('portal', 'skip_content')}</a>
            {open && <div className="sidebar-overlay" onClick={() => setOpen(false)} />}

            <aside
                ref={sidebar}
                id="workspace-navigation"
                className={`sidebar ${open ? 'open' : ''}`}
                inert={!open && mobile}
                aria-hidden={!open && mobile}
                role={open ? 'dialog' : undefined}
                aria-modal={open || undefined}
                aria-label={staff ? translate('portal', 'company_navigation') : translate('portal', 'account_navigation')}
            >
                <button className="icon-button mobile-only" aria-label={translate('portal', 'close_navigation')} onClick={() => setOpen(false)}>
                    <X size={18} />
                </button>
                <a href={base || '/'} className="portal-logo sidebar-logo" aria-label={translate('portal', 'brand_dashboard_aria')}>
                    <img src="/madina-header-logo.png?v=8" alt={translate('portal', 'brand_alt')} />
                </a>
                <div className="workspace-label">
                    <span className="live-dot" />
                    {hubLabel}
                </div>

                {staff && (
                    <nav className="sidebar-domain-switch" data-tour="sidebar-domains" aria-label={translate('portal', 'domain_switch')}>
                        {domainTabs}
                    </nav>
                )}

                {staff && (
                    <div className="sidebar-domain-label">
                        {currentDomain === 'company'
                            ? (ownerPortal ? translate('portal', 'nav_management_section') : translate('portal', 'nav_company_section'))
                            : currentDomain === 'dealers'
                                ? translate('portal', 'nav_dealer_section')
                                : translate('portal', 'nav_subdealer_section')}
                    </div>
                )}

                {renderMothers(domainMothers)}

                <div className="sidebar-footer">
                    {!staff && (
                        <div className="help-card">
                            <Leaf size={22} />
                            <b>{translate('portal', 'help_title')}</b>
                            <span>{translate('portal', 'help_body')}</span>
                            {(publicPageEnabled?.contact ?? true) && (
                                <a href={`${publicBase || ''}/contact`}>{translate('portal', 'contact_company')} <ArrowUpRight size={14} /></a>
                            )}
                        </div>
                    )}
                </div>
            </aside>

            <div className="workspace" inert={open}>
                {!staff && <DealerHeader />}
                <header className="workspace-header glass-header">
                    <div className="breadcrumb">
                        <button
                            ref={toggle}
                            className="icon-button workspace-menu-button"
                            aria-label={translate('portal', 'open_navigation')}
                            aria-controls="workspace-navigation"
                            aria-expanded={open}
                            onClick={() => setOpen(true)}
                        >
                            <Menu size={19} />
                        </button>
                        <span>{hubLabel}</span>
                        <span>/</span>
                        <b>{currentSectionTitle}</b>
                    </div>
                    <div className="header-actions">
                        <LanguageSwitcher compact />
                        <Link
                            href={base + '/notifications'}
                            className="icon-button notice-bell"
                            aria-label={translate('portal', 'notifications')}
                            title={translate('portal', 'notifications')}
                            data-tooltip={translate('portal', 'notifications')}
                        >
                            <Bell size={19} />
                            {unreadCount > 0 && (
                                <span className="notice-bell-count" aria-hidden="true">
                                    {unreadCount > 99 ? '99+' : unreadCount}
                                </span>
                            )}
                        </Link>
                        <div className="profile-menu" ref={profileMenu}>
                            <button
                                type="button"
                                className="profile-menu-trigger"
                                aria-haspopup="menu"
                                aria-expanded={profileOpen}
                                aria-label={translate('portal', 'profile_menu')}
                                onClick={() => setProfileOpen((value) => !value)}
                            >
                                <div className="avatar">
                                    {user.photo_url
                                        ? <img src={user.photo_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: '50%' }} />
                                        : user.name.slice(0, 1)}
                                </div>
                                <div className="user-caption">
                                    <b>{user.name}</b>
                                    <span>{user.role.replaceAll('_', ' ')}</span>
                                </div>
                                <ChevronDown size={14} className={`profile-chevron ${profileOpen ? 'open' : ''}`} aria-hidden="true" />
                            </button>
                            {profileOpen && (
                                <div className="profile-menu-panel" role="menu">
                                    <Link href={base + '/profile'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <UserRound size={16} />
                                        {translate('portal', 'view_profile')}
                                    </Link>
                                    <Link href={base + '/security'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <Settings size={16} />
                                        {translate('portal', 'account_settings')}
                                    </Link>
                                    <Link href={base + '/notifications'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <Bell size={16} />
                                        {translate('portal', 'notifications')}
                                    </Link>
                                    {user.has_linked_staff && (
                                        <button
                                            type="button"
                                            role="menuitem"
                                            onClick={() => {
                                                setProfileOpen(false);
                                                router.post('/commands/workspace-mode', {
                                                    mode: workspaceMode === 'staff' ? 'partner' : 'staff',
                                                });
                                            }}
                                        >
                                            <ArrowLeftRight size={16} />
                                            {workspaceMode === 'staff'
                                                ? translate('portal', 'switch_to_partner_dashboard')
                                                : translate('portal', 'switch_to_staff_dashboard')}
                                        </button>
                                    )}
                                    <button type="button" role="menuitem" className="profile-menu-logout" onClick={signOut}>
                                        <LogOut size={16} />
                                        {translate('portal', 'log_out')}
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                <main id="workspace-main" className="workspace-main">
                    <div className="page-heading portal-fade-in">
                        <div>
                            <div className="eyebrow">{translate('portal', 'brand_eyebrow', { portal: portalLabel })}</div>
                            <h1>{currentSectionTitle}</h1>
                            <p>{currentSectionHint}</p>
                        </div>
                        {action}
                    </div>

                    {dashboardNotice && dashboardNotice.text && (
                        <div className={`portal-notice portal-notice-${dashboardNotice.type || 'info'}`}>
                            <Bell size={18} />
                            <div>{dashboardNotice.text}</div>
                        </div>
                    )}

                    {dashboardNotice?.maintenance && !staff && (
                        <div className="portal-notice portal-notice-danger">
                            <AlertTriangle size={20} />
                            <div>
                                <strong>{translate('portal', 'maintenance_title')}</strong>
                                <p>{translate('portal', 'maintenance_body')}</p>
                            </div>
                        </div>
                    )}

                    <Flash />
                    <div id="domain-panel" role="tabpanel" aria-live="polite" className="portal-workspace-body portal-fade-in-delayed">{children}</div>
                    {demoMode && (
                        <div className="demo-banner">
                            {translate('portal', 'demo_banner')}
                        </div>
                    )}
                    <footer className="workspace-footer">
                        <span>{translate('portal', 'brand_company_bar')}</span>
                        <span>{translate('portal', 'footer_timezone_currency')}</span>
                    </footer>
                </main>
                {!staff && <DealerBottomNav onMenu={() => setOpen(true)} />}
                {staff && (
                    <nav className="staff-domain-bottom-nav" aria-label={translate('portal', 'domain_switch')}>
                        {domainTabs}
                    </nav>
                )}
            </div>
        </div>
    );
}
