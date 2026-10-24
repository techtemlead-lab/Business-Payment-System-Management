import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import {
    LayoutDashboard, ShoppingBag, Package, Users, Truck, Wallet, GitBranch,
    Settings, ShieldCheck, FileText, Leaf, Bell, Menu, X, LogOut, ArrowUpRight,
    Coins, BadgeCheck, BookOpen, MessageSquare, SlidersHorizontal, MapPin, Award,
    Trophy, TrendingUp, Percent, Store, Landmark, UserCheck, AlertTriangle,
    Briefcase, Hash, type LucideIcon
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
    profile: Users, security: Settings, notifications: Bell, earnings: Coins, ranks: Award,
    commissions: Percent, 'network-tree': GitBranch, 'area-dealers': MapPin, 'place-codes': Hash, rewards: Trophy, 'capital-flow': TrendingUp,
    settlements: BadgeCheck, simulator: SlidersHorizontal, 'payouts-hub': Wallet,
    treasury: Landmark, 'benefits-fund': ShieldCheck, 'sub-dealers': Store, 'sub-dealer-orders': ShoppingBag,
    employees: UserCheck, dashboards: SlidersHorizontal
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
    notifications: 'notifications', earnings: 'earnings', ranks: 'ranks', commissions: 'commissions',
    'network-tree': 'network_tree', 'area-dealers': 'area_dealers', 'place-codes': 'place_codes', rewards: 'rewards', 'capital-flow': 'capital_flow',
    settlements: 'settlements', simulator: 'simulator', 'payouts-hub': 'payouts_hub', treasury: 'treasury',
    'benefits-fund': 'benefits_fund',
    'sub-dealers': 'sub_dealers', 'sub-dealer-orders': 'sub_dealer_orders', employees: 'employees', dashboards: 'dashboards',
};

const operationsSections = new Set([
    'data-imports', 'service-integrations', 'field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts',
    'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports',
    'dues-adjustments', 'finance-settings', 'access-management', 'video-marketing',
]);

export default function Shell({ section, children, action }: { section: string; children: ReactNode; action?: ReactNode }) {
    const { auth, portalBase, portal, demoMode, dashboardNotice, publicPageEnabled, publicBase } = usePage<any>().props;
    const translate = useT();
    const user = auth.user;
    const capabilities: string[] = auth.capabilities;
    const staff = portal === 'manage' || portal === 'sadmin';
    const ownerPortal = portal === 'sadmin';
    const base = portalBase || '';
    const shellClass = portal === 'sadmin' ? 'owner-shell' : portal === 'manage' ? 'company-shell' : portal === 'subdealer' ? 'subdealer-shell' : 'dealer-shell';
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
    const workspaceLabel = ownerPortal
        ? translate('portal', 'owner_workspace')
        : user.role === 'employee'
            ? translate('portal', 'employee_workspace')
            : staff
                ? translate('portal', 'company_workspace')
                : user.role === 'sub_dealer'
                    ? translate('portal', 'subdealer_workspace')
                    : translate('portal', 'dealer_workspace');

    const [mobile, setMobile] = useState(() => matchMedia('(max-width:1023px)').matches);

    useEffect(() => {
        const media = matchMedia('(max-width:1023px)');
        const change = () => setMobile(media.matches);
        media.addEventListener('change', change);
        return () => media.removeEventListener('change', change);
    }, []);

    const [open, setOpen] = useState(false);
    const sidebar = useRef<HTMLElement>(null);
    const toggle = useRef<HTMLButtonElement>(null);

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

    const companyGroup = staff ? [
        'overview',
        'my-payroll',
        ...(capabilities.includes('field_sales') ? ['field-sales'] : []),
        ...(capabilities.includes('staff_admin') ? ['staff-operations'] : []),
        ...(capabilities.includes('payroll') ? ['payroll'] : []),
        ...(capabilities.some(cap => ['finance', 'accounts'].includes(cap)) ? ['data-imports'] : []),
        ...(capabilities.includes('finance') || user.role === 'super_admin' ? ['service-integrations'] : []),
        ...(capabilities.includes('finance') ? ['payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'dues-adjustments', 'finance-settings'] : []),
        ...(capabilities.some(cap => ['finance', 'reports', 'field_sales'].includes(cap)) ? ['financial-reports'] : []),
        ...(user.role === 'super_admin' ? ['access-management'] : []),
        ...(capabilities.some(cap => ['content', 'catalog'].includes(cap)) ? ['video-marketing'] : []),
        ...(capabilities.includes('accounts') ? ['dashboards', 'employees'] : []),
        ...(capabilities.includes('finance') ? ['treasury'] : []),
        ...(ownerPortal && ['super_admin', 'md', 'chairman'].includes(user.role) ? ['benefits-fund'] : []),
        ...(capabilities.includes('accounts') ? ['place-codes'] : []),
        ...(capabilities.some(cap => ['catalog', 'pricing', 'inventory', 'orders', 'finance'].includes(cap)) ? ['catalog'] : []),
        ...(capabilities.includes('inventory') ? ['inventory'] : []),
        ...(capabilities.includes('content') ? ['website', 'content', 'enquiries'] : []),
        ...(capabilities.includes('audit') ? ['audit'] : [])
    ] : [];

    const dealerGroup = staff ? [
        ...(capabilities.includes('accounts') ? ['accounts', 'partnerships'] : []),
        ...(capabilities.includes('orders') || capabilities.includes('finance') ? ['orders', 'deliveries', 'dues'] : []),
        ...(capabilities.includes('finance') ? ['commissions', 'policies', 'ledger', 'reports'] : []),
        ...(capabilities.includes('accounts') ? ['network-tree', 'area-dealers', 'ranks'] : []),
        ...(capabilities.includes('finance') ? ['rewards', 'settlements', 'simulator', 'payouts-hub', 'payments', 'withdrawals'] : []),
    ] : (user.role === 'dealer' ? ['orders', 'deliveries', 'dues', 'catalog', 'network', 'ranks', 'earnings', 'withdrawals'] : []);

    const subDealerGroup = staff ? [
        ...(capabilities.includes('accounts') ? ['sub-dealers'] : []),
        ...(capabilities.includes('orders') || capabilities.includes('finance') ? ['sub-dealer-orders'] : []),
        ...(capabilities.includes('finance') ? ['salary'] : []),
    ] : (user.role === 'sub_dealer' ? ['orders', 'deliveries', 'dues', 'catalog', 'salary', 'earnings', 'withdrawals'] : []);

    const group = (title: string, items: string[]) => items.length > 0 && (
        <nav className="nav-group" aria-label={title}>
            <span>{title}</span>
            {items.map(item => {
                const Icon = icons[item] || LayoutDashboard;
                return (
                    <Link
                        onClick={() => setOpen(false)}
                        aria-current={section === item ? 'page' : undefined}
                        className={section === item ? 'selected' : ''}
                        href={`${base}/${operationsSections.has(item) ? 'operations/' : ''}${item === 'overview' ? '' : item}`}
                        key={item}
                    >
                        <Icon size={18} />
                        {sectionLabel(item)}
                        {section === item && <i />}
                    </Link>
                );
            })}
        </nav>
    );

    const isDashboardsDomain = section === 'dashboards';
    const isCompanyDomain = ['overview', 'employees', 'treasury', 'benefits-fund', 'place-codes', 'capital-flow', 'catalog', 'inventory', 'website', 'customization', 'content', 'enquiries', 'audit'].includes(section);
    const isDealerDomain = ['accounts', 'partnerships', 'orders', 'deliveries', 'dues', 'commissions', 'network-tree', 'area-dealers', 'ranks', 'rewards', 'settlements', 'simulator', 'payouts-hub', 'payments', 'policies', 'ledger', 'reports', 'withdrawals'].includes(section);
    const isSubDealerDomain = ['sub-dealers', 'sub-dealer-orders', 'salary'].includes(section);
    const currentDomain = isDashboardsDomain ? 'dashboards' : isSubDealerDomain ? 'sub_dealers' : isDealerDomain ? 'dealers' : 'company';
    const currentSectionTitle = section === 'overview'
        ? dashboardTitle
        : sectionLabel(section);
    const currentSectionHint = section === 'overview'
        ? translate('portal', 'welcome_back', { name: user.name.split(' ')[0], scope: translate('portal', staff ? 'business' : 'account') })
        : (section === 'catalog' && staff
            ? translate('portal', 'catalog_staff_hint')
            : translate('portal', 'section_hint'));

    return (
        <div className={`app-shell ${shellClass}`}>
            <a className="skip-link" href="#workspace-main">{translate('portal', 'skip_content')}</a>
            {open && <div className="sidebar-overlay" onClick={() => setOpen(false)} />}

            <aside
                ref={sidebar}
                id="workspace-navigation"
                className={`sidebar ${open ? 'open' : ''}`}
                inert={!open && (!staff || mobile)}
                aria-hidden={!open && (!staff || mobile)}
                role={open ? 'dialog' : undefined}
                aria-modal={open || undefined}
                aria-label={staff ? translate('portal', 'company_navigation') : translate('portal', 'account_navigation')}
            >
                <button className={`icon-button ${staff ? 'mobile-only' : ''}`} aria-label={translate('portal', 'close_navigation')} onClick={() => setOpen(false)}>
                    <X size={18} />
                </button>
                <a href={base || '/'} className="portal-logo sidebar-logo" aria-label="Madina dashboard">
                    <img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care" />
                </a>
                <div className="workspace-label">
                    <span className="live-dot" />
                    {workspaceLabel}
                </div>

                {staff ? (
                    <>
                        {group(ownerPortal ? translate('portal', 'nav_owner_section') : translate('portal', 'nav_company_section'), companyGroup)}
                        {group(translate('portal', 'nav_dealer_section'), dealerGroup)}
                        {group(translate('portal', 'nav_subdealer_section'), subDealerGroup)}
                        {group(translate('portal', 'nav_account'), ['profile', 'security', 'notifications'])}
                    </>
                ) : (
                    <>
                        {user.role === 'dealer' && group(translate('portal', 'nav_dealer_workspace'), dealerGroup)}
                        {user.role === 'sub_dealer' && group(translate('portal', 'nav_subdealer_workspace'), subDealerGroup)}
                        {group(translate('portal', 'nav_my_earnings'), ['earnings', 'withdrawals'])}
                        {group(translate('portal', 'nav_account'), ['profile', 'security', 'notifications'])}
                    </>
                )}

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
                    <button className="sign-out" onClick={() => router.post('/logout')}>
                        <LogOut size={17} />{translate('portal', 'log_out')}
                    </button>
                </div>
            </aside>

            <div className="workspace" inert={open}>
                {!staff && <DealerHeader />}
                <header className="workspace-header">
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
                        <span>{translate('portal', 'workspace')}</span>
                        <span>/</span>
                        <b>{currentSectionTitle}</b>
                    </div>
                    <div className="header-actions">
                        <LanguageSwitcher compact />
                        <Link href={base + '/notifications'} className="icon-button" aria-label={translate('portal', 'notifications')}>
                            <Bell size={19} />
                        </Link>
                        <div className="avatar">{user.name.slice(0, 1)}</div>
                        <div className="user-caption">
                            <b>{user.name}</b>
                            <span>{user.role.replaceAll('_', ' ')}</span>
                        </div>
                        <button
                            type="button"
                            className="workspace-logout-btn"
                            title={translate('portal', 'log_out')}
                            aria-label={translate('portal', 'log_out')}
                            onClick={() => router.post('/logout')}
                        >
                            <LogOut size={16} />
                            <span>{translate('portal', 'log_out')}</span>
                        </button>
                    </div>
                </header>

                <main id="workspace-main" className="workspace-main">
                    <div className="page-heading portal-fade-in">
                        <div>
                            <div className="eyebrow">MADINA / {portalLabel}</div>
                            <h1>{currentSectionTitle}</h1>
                            <p>{currentSectionHint}</p>
                        </div>
                        {action}
                    </div>

                    {staff && (
                        <div className="domain-rail portal-fade-in">
                            <div className="domain-rail-top">
                                <div className="domain-rail-tabs" role="tablist" aria-label={translate('portal', 'workspace')}>
                                    <Link
                                        href="/"
                                        id="domain-tab-company"
                                        role="tab"
                                        aria-selected={currentDomain === 'company'}
                                        aria-controls="domain-panel"
                                        tabIndex={currentDomain === 'company' ? 0 : -1}
                                        className={`domain-tab domain-tab-company ${currentDomain === 'company' ? 'active' : ''}`}
                                    >
                                        <Briefcase size={15} />
                                        {ownerPortal ? translate('portal', 'nav_owner_section') : translate('portal', 'nav_company_section')}
                                    </Link>

                                    {capabilities.some(c => ['accounts', 'orders', 'finance'].includes(c)) && (
                                        <Link
                                            href={capabilities.includes('accounts') ? '/accounts' : (capabilities.includes('orders') ? '/orders' : '/commissions')}
                                            id="domain-tab-dealers"
                                            role="tab"
                                            aria-selected={currentDomain === 'dealers'}
                                            aria-controls="domain-panel"
                                            tabIndex={currentDomain === 'dealers' ? 0 : -1}
                                            className={`domain-tab domain-tab-dealers ${currentDomain === 'dealers' ? 'active' : ''}`}
                                        >
                                            <Users size={15} />
                                            {translate('portal', 'nav_dealer_section')}
                                        </Link>
                                    )}

                                    {capabilities.some(c => ['accounts', 'orders', 'finance'].includes(c)) && (
                                        <Link
                                            href={capabilities.includes('accounts') ? '/sub-dealers' : (capabilities.includes('orders') ? '/sub-dealer-orders' : '/salary')}
                                            id="domain-tab-subdealers"
                                            role="tab"
                                            aria-selected={currentDomain === 'sub_dealers'}
                                            aria-controls="domain-panel"
                                            tabIndex={currentDomain === 'sub_dealers' ? 0 : -1}
                                            className={`domain-tab domain-tab-subdealers ${currentDomain === 'sub_dealers' ? 'active' : ''}`}
                                        >
                                            <Store size={15} />
                                            {translate('portal', 'nav_subdealer_section')}
                                        </Link>
                                    )}

                                    {capabilities.includes('accounts') && (
                                        <Link
                                            href="/dashboards"
                                            id="domain-tab-dashboards"
                                            role="tab"
                                            aria-selected={currentDomain === 'dashboards'}
                                            aria-controls="domain-panel"
                                            tabIndex={currentDomain === 'dashboards' ? 0 : -1}
                                            className={`domain-tab domain-tab-dashboards ${currentDomain === 'dashboards' ? 'active' : ''}`}
                                        >
                                            <SlidersHorizontal size={15} />
                                            {translate('portal', 'nav_dashboard_mgmt')}
                                        </Link>
                                    )}
                                </div>

                                <span className="domain-rail-caption">
                                    {currentDomain === 'dashboards'
                                        ? translate('portal', 'domain_dashboards')
                                        : currentDomain === 'company'
                                            ? translate('portal', 'domain_company')
                                            : currentDomain === 'dealers'
                                                ? translate('portal', 'domain_dealers')
                                                : translate('portal', 'domain_sub_dealers')}
                                </span>
                            </div>

                            <div className="domain-rail-links">
                                <span>{translate('portal', 'subsections')}</span>
                                {currentDomain === 'company' && (
                                    <>
                                        <Link href="/" className={`button small ${section === 'overview' ? 'dark' : ''}`}>{translate('portal', 'overview')}</Link>
                                        {capabilities.includes('accounts') && (
                                            <Link href="/employees" className={`button small ${section === 'employees' ? 'dark' : ''}`}>{translate('portal', 'employees')}</Link>
                                        )}
                                        {capabilities.includes('finance') && (
                                            <Link href="/treasury" className={`button small ${['treasury', 'capital-flow'].includes(section) ? 'dark' : ''}`}>{translate('portal', 'treasury')}</Link>
                                        )}
                                        {ownerPortal && ['super_admin', 'md', 'chairman'].includes(user.role) && (
                                            <Link href="/benefits-fund" className={`button small ${section === 'benefits-fund' ? 'dark' : ''}`}>{translate('portal', 'benefits_fund')}</Link>
                                        )}
                                        {capabilities.includes('accounts') && (
                                            <Link href="/place-codes" className={`button small ${section === 'place-codes' ? 'dark' : ''}`}>{translate('portal', 'place_codes')}</Link>
                                        )}
                                        {capabilities.some(cap => ['catalog', 'pricing', 'inventory', 'orders', 'finance'].includes(cap)) && (
                                            <Link href="/catalog" className={`button small ${section === 'catalog' ? 'dark' : ''}`}>{translate('portal', 'product_management')}</Link>
                                        )}
                                        {capabilities.includes('inventory') && (
                                            <Link href="/inventory" className={`button small ${section === 'inventory' ? 'dark' : ''}`}>{translate('portal', 'inventory')}</Link>
                                        )}
                                        {capabilities.includes('content') && (
                                            <>
                                                <Link href="/website" className={`button small ${['website', 'customization'].includes(section) ? 'dark' : ''}`}>{translate('portal', 'website')}</Link>
                                                <Link href="/content" className={`button small ${section === 'content' ? 'dark' : ''}`}>{translate('portal', 'content')}</Link>
                                                <Link href="/enquiries" className={`button small ${section === 'enquiries' ? 'dark' : ''}`}>{translate('portal', 'enquiries')}</Link>
                                            </>
                                        )}
                                        {capabilities.includes('audit') && (
                                            <Link href="/audit" className={`button small ${section === 'audit' ? 'dark' : ''}`}>{translate('portal', 'audit')}</Link>
                                        )}
                                    </>
                                )}

                                {currentDomain === 'dealers' && (
                                    <>
                                        {capabilities.includes('accounts') && <Link href="/accounts" className={`button small ${section === 'accounts' ? 'dark' : ''}`}>{translate('portal', 'accounts')}</Link>}
                                        {capabilities.includes('accounts') && <Link href="/partnerships" className={`button small ${section === 'partnerships' ? 'dark' : ''}`}>{translate('portal', 'partnerships')}</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/orders" className={`button small ${section === 'orders' ? 'dark' : ''}`}>{translate('portal', 'orders')}</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/deliveries" className={`button small ${section === 'deliveries' ? 'dark' : ''}`}>{translate('portal', 'deliveries')}</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/dues" className={`button small ${section === 'dues' ? 'dark' : ''}`}>{translate('portal', 'dues')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/commissions" className={`button small accent ${section === 'commissions' ? 'dark' : ''}`}>{translate('portal', 'commissions')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/policies" className={`button small ${section === 'policies' ? 'dark' : ''}`}>{translate('portal', 'policies')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/ledger" className={`button small ${section === 'ledger' ? 'dark' : ''}`}>{translate('portal', 'ledger')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/reports" className={`button small ${section === 'reports' ? 'dark' : ''}`}>{translate('portal', 'reports')}</Link>}
                                        {capabilities.includes('accounts') && <Link href="/network-tree" className={`button small ${section === 'network-tree' ? 'dark' : ''}`}>{translate('portal', 'network_tree')}</Link>}
                                        {capabilities.includes('accounts') && <Link href="/area-dealers" className={`button small ${section === 'area-dealers' ? 'dark' : ''}`}>{translate('portal', 'area_dealers')}</Link>}
                                        {capabilities.includes('accounts') && <Link href="/ranks" className={`button small ${section === 'ranks' ? 'dark' : ''}`}>{translate('portal', 'ranks')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/rewards" className={`button small ${section === 'rewards' ? 'dark' : ''}`}>{translate('portal', 'rewards')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/settlements" className={`button small ${section === 'settlements' ? 'dark' : ''}`}>{translate('portal', 'settlements')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/simulator" className={`button small ${section === 'simulator' ? 'dark' : ''}`}>{translate('portal', 'simulator')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/payouts-hub" className={`button small ${section === 'payouts-hub' ? 'dark' : ''}`}>{translate('portal', 'payouts_hub')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/payments" className={`button small ${section === 'payments' ? 'dark' : ''}`}>{translate('portal', 'payments')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/withdrawals" className={`button small ${section === 'withdrawals' ? 'dark' : ''}`}>{translate('portal', 'withdrawals')}</Link>}
                                    </>
                                )}

                                {currentDomain === 'sub_dealers' && (
                                    <>
                                        {capabilities.includes('accounts') && <Link href="/sub-dealers" className={`button small ${section === 'sub-dealers' ? 'dark' : ''}`}>{translate('portal', 'sub_dealers')}</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/sub-dealer-orders" className={`button small ${section === 'sub-dealer-orders' ? 'dark' : ''}`}>{translate('portal', 'sub_dealer_orders')}</Link>}
                                        {capabilities.includes('finance') && <Link href="/salary" className={`button small ${section === 'salary' ? 'dark' : ''}`}>{translate('portal', 'salary')}</Link>}
                                    </>
                                )}

                                {currentDomain === 'dashboards' && (
                                    <>
                                        <Link href="/dashboards" className={`button small ${section === 'dashboards' ? 'dark' : ''}`}>{translate('portal', 'dashboards')}</Link>
                                        <Link href="/employees" className={`button small ${section === 'employees' ? 'dark' : ''}`}>{translate('portal', 'employees')}</Link>
                                        <Link href="/accounts" className="button small">{translate('portal', 'accounts')}</Link>
                                        <Link href="/sub-dealers" className="button small">{translate('portal', 'sub_dealers')}</Link>
                                    </>
                                )}
                            </div>
                        </div>
                    )}

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
                        <span>Madina Natural Health Care</span>
                        <span>Asia/Dhaka · BDT</span>
                    </footer>
                </main>
                {!staff && <DealerBottomNav onMenu={() => setOpen(true)} />}
            </div>
        </div>
    );
}
