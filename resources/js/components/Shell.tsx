import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import {
    LayoutDashboard, ShoppingBag, Package, Users, Truck, Wallet, GitBranch,
    Settings, ShieldCheck, FileText, Leaf, Bell, Menu, X, LogOut, ArrowUpRight,
    Coins, BadgeCheck, BookOpen, MessageSquare, SlidersHorizontal, MapPin, Award,
    Trophy, TrendingUp, Percent, Store, Landmark, Building2, UserCheck, AlertTriangle, type LucideIcon
} from 'lucide-react';
import { Flash } from './UI';
import { DealerHeader, DealerBottomNav } from './PortalHeader';

const icons: Record<string, LucideIcon> = {
    'field-sales': MapPin, 'staff-operations': UserCheck, payroll: Coins, 'my-payroll': Wallet,
    'payment-accounts': Landmark, 'policy-schedules': Percent, 'reserve-payouts': Award,
    'data-imports': FileText, 'commission-holds': ShieldCheck, 'financial-readiness': BookOpen, 'financial-reports': FileText,
    overview: LayoutDashboard, orders: ShoppingBag, catalog: Package, accounts: Users, inventory: Package, deliveries: Truck, dues: Wallet,
    payments: Coins, network: GitBranch, policies: SlidersHorizontal, salary: BadgeCheck, withdrawals: Wallet, ledger: BookOpen,
    reports: FileText, customization: SlidersHorizontal, content: FileText, enquiries: MessageSquare, audit: ShieldCheck,
    profile: Users, security: Settings, notifications: Bell, earnings: Coins, ranks: Award,
    commissions: Percent, 'network-tree': GitBranch, 'area-dealers': MapPin, rewards: Trophy, 'capital-flow': TrendingUp,
    settlements: BadgeCheck, simulator: SlidersHorizontal, 'payouts-hub': Wallet,
    treasury: Landmark, 'sub-dealers': Store, 'sub-dealer-orders': ShoppingBag,
    employees: UserCheck, dashboards: SlidersHorizontal
};

const names: Record<string, string> = {
    'field-sales': 'Field sales', 'staff-operations': 'Territories & targets', 'payroll': 'Staff payroll', 'my-payroll': 'My payroll',
    'payment-accounts': 'Payment accounts', 'policy-schedules': 'Commission schedules', 'reserve-payouts': 'Reserve payouts',
    'data-imports': 'Excel data import', 'commission-holds': 'Commission holds', 'financial-readiness': 'Opening balance review', 'financial-reports': 'Financial reports', 'dues-adjustments':'Due adjustments', 'finance-settings':'Finance settings', 'access-management':'Roles & permissions', 'video-marketing':'Video marketing',
    overview: 'Overview', orders: 'Orders', catalog: 'Product collection', accounts: 'Dealer Directory', inventory: 'Inventory',
    deliveries: 'Deliveries', dues: 'Outstanding dues', payments: 'Payment review', network: 'My network', policies: 'Commission policies',
    salary: 'Sub-dealer flat 3% salary', withdrawals: 'Withdrawals', ledger: 'Financial ledger', reports: 'Reports', customization: 'Site Customization',
    content: 'Website content', enquiries: 'Enquiries', audit: 'Activity log', profile: 'My profile', security: 'Account security',
    notifications: 'Notifications', earnings: 'My earnings', ranks: 'Ranks & Royalties',
    commissions: 'Commission Studio', 'network-tree': 'Network Tree', 'area-dealers': 'Area Dealers', rewards: 'Car & Bike Rewards', 'capital-flow': 'Capital Waterfall',
    settlements: 'Settlements & Payouts', simulator: 'What-If Simulator', 'payouts-hub': 'Disbursement Hub',
    treasury: 'Treasury & Money Flow', 'sub-dealers': 'Sub-Dealer Directory', 'sub-dealer-orders': '40% Margin Orders',
    employees: 'Employees & Staff', dashboards: 'Dashboard Management'
};

export default function Shell({ section, children, action }: { section: string; children: ReactNode; action?: ReactNode }) {
    const { auth, portalBase, demoMode, dashboardNotice, dashboardControls } = usePage<any>().props;
    const user = auth.user;
    const capabilities: string[] = auth.capabilities;
    const staff = portalBase === '/company';
    const base = staff ? '/company' : '/workspace';
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

    // Grouping by 3 Pillars for Staff
    const companyGroup = staff ? [
        'overview',
        'my-payroll',
        ...(capabilities.includes('field_sales') ? ['field-sales'] : []),
        ...(capabilities.includes('staff_admin') ? ['staff-operations'] : []),
        ...(capabilities.includes('payroll') ? ['payroll'] : []),
        ...(capabilities.some(cap=>['finance','accounts'].includes(cap)) ? ['data-imports'] : []),
        ...(capabilities.includes('finance') ? ['payment-accounts', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness','dues-adjustments','finance-settings'] : []),
        ...(capabilities.some(cap=>['finance','reports','field_sales'].includes(cap)) ? ['financial-reports'] : []),
        ...(user.role==='super_admin'?['access-management']:[]),
        ...(capabilities.some(cap=>['content','catalog'].includes(cap))?['video-marketing']:[]),
        ...(capabilities.includes('accounts') ? ['dashboards', 'employees'] : []),
        ...(capabilities.includes('finance') ? ['treasury'] : []),
        ...(capabilities.some(cap=>['catalog','pricing','inventory','orders','finance'].includes(cap)) ? ['catalog'] : []),
        ...(capabilities.includes('inventory') ? ['inventory'] : []),
        ...(capabilities.includes('content') ? ['customization', 'content', 'enquiries'] : []),
        ...(capabilities.includes('audit') ? ['audit'] : [])
    ] : [];

    const dealerGroup = staff ? [
        ...(capabilities.includes('accounts') ? ['accounts'] : []),
        ...(capabilities.includes('orders') || capabilities.includes('finance') ? ['orders', 'deliveries', 'dues'] : []),
        ...(capabilities.includes('finance') ? ['commissions'] : []),
        ...(capabilities.includes('accounts') ? ['network-tree', 'area-dealers', 'ranks'] : []),
        ...(capabilities.includes('finance') ? ['rewards', 'settlements', 'simulator', 'payouts-hub', 'payments'] : []),
    ] : (user.role === 'dealer' ? ['orders', 'deliveries', 'dues', 'catalog', 'network', 'ranks'] : []);

    const subDealerGroup = staff ? [
        ...(capabilities.includes('accounts') ? ['sub-dealers'] : []),
        ...(capabilities.includes('orders') || capabilities.includes('finance') ? ['sub-dealer-orders'] : []),
        ...(capabilities.includes('finance') ? ['salary'] : []),
    ] : (user.role === 'sub_dealer' ? ['orders', 'deliveries', 'dues', 'catalog', 'salary'] : []);

    const group = (title: string, items: string[]) => items.length > 0 && (
        <nav className="nav-group" aria-label={title}>
            <span>{title}</span>
            {items.map(item => {
                const Icon = icons[item] || LayoutDashboard;
                const itemName = item === 'catalog' && staff ? 'Product Management' : names[item];
                return (
                    <Link
                        onClick={() => setOpen(false)}
                        aria-current={section === item ? 'page' : undefined}
                        className={section === item ? 'selected' : ''}
                        href={`${base}/${['data-imports','field-sales','staff-operations','payroll','my-payroll','payment-accounts','policy-schedules','reserve-payouts','commission-holds','financial-readiness','financial-reports','dues-adjustments','finance-settings','access-management','video-marketing'].includes(item)?'operations/':''}${item === 'overview' ? '' : item}`}
                        key={item}
                    >
                        <Icon size={18} />
                        {itemName}
                        {section === item && <i />}
                    </Link>
                );
            })}
        </nav>
    );

    // Active Domain Determination for Top Pillar Bar
    const isDashboardsDomain = section === 'dashboards';
    const isCompanyDomain = ['overview', 'employees', 'treasury', 'capital-flow', 'catalog', 'inventory', 'customization', 'content', 'enquiries', 'audit'].includes(section);
    const isDealerDomain = ['accounts', 'orders', 'deliveries', 'dues', 'commissions', 'network-tree', 'area-dealers', 'ranks', 'rewards', 'settlements', 'simulator', 'payouts-hub', 'payments', 'policies', 'ledger', 'reports'].includes(section);
    const isSubDealerDomain = ['sub-dealers', 'sub-dealer-orders', 'salary'].includes(section);
    const currentDomain = isDashboardsDomain ? 'dashboards' : isSubDealerDomain ? 'sub_dealers' : isDealerDomain ? 'dealers' : 'company';

    return (
        <div className={`app-shell ${staff ? 'company-shell' : 'dealer-shell'}`}>
            <a className="skip-link" href="#workspace-main">Skip to content</a>
            {open && <div className="sidebar-overlay" onClick={() => setOpen(false)} />}

            <aside
                ref={sidebar}
                id="workspace-navigation"
                className={`sidebar ${open ? 'open' : ''}`}
                inert={!open && (!staff || mobile)}
                aria-hidden={!open && (!staff || mobile)}
                role={open ? 'dialog' : undefined}
                aria-modal={open || undefined}
                aria-label={staff ? 'Company navigation' : 'Account navigation'}
            >
                <button className={`icon-button ${staff ? 'mobile-only' : ''}`} aria-label="Close navigation" onClick={() => setOpen(false)}>
                    <X size={18} />
                </button>
                <a href={base} className="portal-logo sidebar-logo" aria-label="Madina dashboard">
                    <img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care" />
                </a>
                <div className="workspace-label">
                    <span className="live-dot" />
                    {user.role === 'employee' ? 'Employee workspace' : staff ? 'Company workspace' : user.role === 'sub_dealer' ? 'Sub-dealer workspace' : 'Dealer workspace'}
                </div>

                {staff ? (
                    <>
                        {group('🏢 COMPANY SECTION', companyGroup)}
                        {group('🤝 DEALER SECTION', dealerGroup)}
                        {group('🏬 SUB-DEALER SECTION', subDealerGroup)}
                        {group('⚙️ ACCOUNT', ['profile', 'security'])}
                    </>
                ) : (
                    <>
                        {user.role === 'dealer' && group('DEALER WORKSPACE', dealerGroup)}
                        {user.role === 'sub_dealer' && group('SUB-DEALER WORKSPACE', subDealerGroup)}
                        {group('MY EARNINGS', ['earnings', 'withdrawals'])}
                        {group('ACCOUNT', ['profile', 'security'])}
                    </>
                )}

                <div className="sidebar-footer">
                    {!staff && (
                        <div className="help-card">
                            <Leaf size={22} />
                            <b>Growing, together.</b>
                            <span>One connected Madina network.</span>
                            <a href="/contact">Contact the company <ArrowUpRight size={14} /></a>
                        </div>
                    )}
                    <button className="sign-out" onClick={() => router.post('/logout')}>
                        <LogOut size={17} />Log out
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
                            aria-label="Open navigation"
                            aria-controls="workspace-navigation"
                            aria-expanded={open}
                            onClick={() => setOpen(true)}
                        >
                            <Menu size={19} />
                        </button>
                        <span>Workspace</span>
                        <span>/</span>
                        <b>{section === 'catalog' && staff ? 'Product Management' : (names[section] ?? 'Order details')}</b>
                    </div>
                    <div className="header-actions">
                        <Link href={base + '/notifications'} className="icon-button" aria-label="Notifications">
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
                            title="Log out"
                            aria-label="Log out"
                            onClick={() => router.post('/logout')}
                        >
                            <LogOut size={16} />
                            <span>Log out</span>
                        </button>
                    </div>
                </header>

                <main id="workspace-main" className="workspace-main">
                    <div className="page-heading">
                        <div>
                            <div className="eyebrow">MADINA / {staff ? 'COMPANY' : 'PARTNER'} PORTAL</div>
                            <h1>
                                {section === 'overview'
                                    ? (staff ? 'Company Dashboard' : 'My Account')
                                    : (section === 'catalog' && staff ? 'Product Management' : (names[section] ?? 'Order details'))}
                            </h1>
                            <p>
                                {section === 'overview'
                                    ? `Welcome back, ${user.name.split(' ')[0]}. Here’s what’s happening across your ${staff ? 'business' : 'account'}.`
                                    : (section === 'catalog' && staff
                                        ? 'Manage product information, partner pricing, stock availability and the public showcase.'
                                        : 'Everything you need, with a clear trail of activity.')}
                            </p>
                        </div>
                        {action}
                    </div>

                    {/* Staff Top Domain Navigation Banner with 3 Pillars & Subsections */}
                    {staff && (
                        <div style={{
                            background: '#ffffff',
                            border: '1px solid #e2e8f0',
                            borderRadius: 10,
                            padding: '14px 18px',
                            marginBottom: 20,
                            boxShadow: '0 1px 3px rgba(0,0,0,0.03)'
                        }}>
                            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    <Link
                                        href="/company"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            gap: 8,
                                            padding: '8px 16px',
                                            borderRadius: 8,
                                            fontSize: 12,
                                            fontWeight: 700,
                                            textDecoration: 'none',
                                            background: currentDomain === 'company' ? '#011627' : '#f8fafc',
                                            color: currentDomain === 'company' ? '#ffffff' : '#475569',
                                            border: currentDomain === 'company' ? '1px solid #011627' : '1px solid #e2e8f0',
                                        }}
                                    >
                                        <span>🏢</span> COMPANY SECTION
                                    </Link>

                                    {capabilities.some(c => ['accounts', 'orders', 'finance'].includes(c)) && (
                                        <Link
                                            href={capabilities.includes('accounts') ? '/company/accounts' : (capabilities.includes('orders') ? '/company/orders' : '/company/commissions')}
                                            style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                padding: '8px 16px',
                                                borderRadius: 8,
                                                fontSize: 12,
                                                fontWeight: 700,
                                                textDecoration: 'none',
                                                background: currentDomain === 'dealers' ? '#2e3192' : '#f8fafc',
                                                color: currentDomain === 'dealers' ? '#ffffff' : '#475569',
                                                border: currentDomain === 'dealers' ? '1px solid #2e3192' : '1px solid #e2e8f0',
                                            }}
                                        >
                                            <span>🤝</span> DEALER SECTION
                                        </Link>
                                    )}

                                    {capabilities.some(c => ['accounts', 'orders', 'finance'].includes(c)) && (
                                        <Link
                                            href={capabilities.includes('accounts') ? '/company/sub-dealers' : (capabilities.includes('orders') ? '/company/sub-dealer-orders' : '/company/salary')}
                                            style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                padding: '8px 16px',
                                                borderRadius: 8,
                                                fontSize: 12,
                                                fontWeight: 700,
                                                textDecoration: 'none',
                                                background: currentDomain === 'sub_dealers' ? '#059669' : '#f8fafc',
                                                color: currentDomain === 'sub_dealers' ? '#ffffff' : '#475569',
                                                border: currentDomain === 'sub_dealers' ? '1px solid #059669' : '1px solid #e2e8f0',
                                            }}
                                        >
                                            <span>🏬</span> SUB-DEALER SECTION
                                        </Link>
                                    )}

                                    {capabilities.includes('accounts') && (
                                        <Link
                                            href="/company/dashboards"
                                            style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                padding: '8px 16px',
                                                borderRadius: 8,
                                                fontSize: 12,
                                                fontWeight: 700,
                                                textDecoration: 'none',
                                                background: currentDomain === 'dashboards' ? '#2e3192' : '#f8fafc',
                                                color: currentDomain === 'dashboards' ? '#ffffff' : '#475569',
                                                border: currentDomain === 'dashboards' ? '1px solid #2e3192' : '1px solid #e2e8f0',
                                            }}
                                        >
                                            <span>🎛️</span> DASHBOARD MANAGEMENT
                                        </Link>
                                    )}
                                </div>

                                <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>
                                    {currentDomain === 'dashboards'
                                        ? 'Managing Global Dashboards & Role Controls'
                                        : currentDomain === 'company'
                                            ? 'Managing Company & Treasury'
                                            : currentDomain === 'dealers'
                                                ? 'Managing Dealer Network & Commissions'
                                                : 'Managing Sub-Dealers & 40% Margin'}
                                </span>
                            </div>

                            <div style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 6,
                                flexWrap: 'wrap',
                                paddingTop: 12,
                                marginTop: 12,
                                borderTop: '1px solid #f1f5f9'
                            }}>
                                <span style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', marginRight: 4 }}>
                                    Subsections:
                                </span>
                                {currentDomain === 'company' && (
                                    <>
                                        <Link href="/company" className={`button small ${section === 'overview' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Overview</Link>
                                        {capabilities.includes('accounts') && (
                                            <Link href="/company/employees" className={`button small ${section === 'employees' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Employees & Staff</Link>
                                        )}
                                        {capabilities.includes('finance') && (
                                            <Link href="/company/treasury" className={`button small ${['treasury', 'capital-flow'].includes(section) ? 'dark' : ''}`} style={{ fontSize: 11 }}>Treasury & Money Flow</Link>
                                        )}
                                        <Link href="/company/catalog" className={`button small ${section === 'catalog' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Product Management</Link>
                                        {capabilities.includes('inventory') && (
                                            <Link href="/company/inventory" className={`button small ${section === 'inventory' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Inventory & Stock</Link>
                                        )}
                                        {capabilities.includes('content') && (
                                            <Link href="/company/customization" className={`button small ${section === 'customization' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Site Customization</Link>
                                        )}
                                        {capabilities.includes('audit') && (
                                            <Link href="/company/audit" className={`button small ${section === 'audit' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Audit Trail</Link>
                                        )}
                                    </>
                                )}

                                {currentDomain === 'dealers' && (
                                    <>
                                        {capabilities.includes('accounts') && <Link href="/company/accounts" className={`button small ${section === 'accounts' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Dealer Accounts</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/company/orders" className={`button small ${section === 'orders' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Orders</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/company/deliveries" className={`button small ${section === 'deliveries' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Deliveries</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/company/dues" className={`button small ${section === 'dues' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Outstanding Dues</Link>}
                                        {capabilities.includes('finance') && <Link href="/company/commissions" className={`button small ${section === 'commissions' ? 'dark' : ''}`} style={{ fontSize: 11, background: section === 'commissions' ? '#2e3192' : '#eef0ff', color: section === 'commissions' ? '#fff' : '#2e3192', borderColor: '#c7d2fe' }}>★ Commission Studio</Link>}
                                        {capabilities.includes('accounts') && <Link href="/company/network-tree" className={`button small ${section === 'network-tree' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Network Tree</Link>}
                                        {capabilities.includes('accounts') && <Link href="/company/area-dealers" className={`button small ${section === 'area-dealers' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Area Dealers</Link>}
                                        {capabilities.includes('accounts') && <Link href="/company/ranks" className={`button small ${section === 'ranks' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Ranks & Royalties</Link>}
                                        {capabilities.includes('finance') && <Link href="/company/rewards" className={`button small ${section === 'rewards' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Car & Bike Rewards</Link>}
                                        {capabilities.includes('finance') && <Link href="/company/settlements" className={`button small ${section === 'settlements' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Settlements</Link>}
                                        {capabilities.includes('finance') && <Link href="/company/simulator" className={`button small ${section === 'simulator' ? 'dark' : ''}`} style={{ fontSize: 11 }}>What-If Simulator</Link>}
                                        {capabilities.includes('finance') && <Link href="/company/payouts-hub" className={`button small ${section === 'payouts-hub' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Disbursements</Link>}
                                    </>
                                )}

                                {currentDomain === 'sub_dealers' && (
                                    <>
                                        {capabilities.includes('accounts') && <Link href="/company/sub-dealers" className={`button small ${section === 'sub-dealers' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Sub-Dealer Accounts</Link>}
                                        {(capabilities.includes('orders') || capabilities.includes('finance')) && <Link href="/company/sub-dealer-orders" className={`button small ${section === 'sub-dealer-orders' ? 'dark' : ''}`} style={{ fontSize: 11 }}>40% Margin Orders</Link>}
                                        {capabilities.includes('finance') && <Link href="/company/salary" className={`button small ${section === 'salary' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Monthly Salary · 3%</Link>}
                                    </>
                                )}

                                {currentDomain === 'dashboards' && (
                                    <>
                                        <Link href="/company/dashboards" className={`button small ${section === 'dashboards' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Control Center</Link>
                                        <Link href="/company/employees" className={`button small ${section === 'employees' ? 'dark' : ''}`} style={{ fontSize: 11 }}>Employees & Staff</Link>
                                        <Link href="/company/accounts" className="button small" style={{ fontSize: 11 }}>Dealer Accounts</Link>
                                        <Link href="/company/sub-dealers" className="button small" style={{ fontSize: 11 }}>Sub-Dealer Accounts</Link>
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {dashboardNotice && dashboardNotice.text && (
                        <div style={{
                            padding: '12px 16px',
                            marginBottom: 20,
                            borderRadius: 8,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 12,
                            fontSize: 13,
                            fontWeight: 500,
                            background: dashboardNotice.type === 'warning' ? '#fffbeb' : dashboardNotice.type === 'success' ? '#ecfdf5' : '#eff6ff',
                            color: dashboardNotice.type === 'warning' ? '#92400e' : dashboardNotice.type === 'success' ? '#065f46' : '#1e40af',
                            border: `1px solid ${dashboardNotice.type === 'warning' ? '#fde68a' : dashboardNotice.type === 'success' ? '#a7f3d0' : '#bfdbfe'}`
                        }}>
                            <Bell size={18} style={{ flexShrink: 0 }} />
                            <div style={{ flex: 1 }}>{dashboardNotice.text}</div>
                        </div>
                    )}

                    {dashboardNotice?.maintenance && !staff && (
                        <div style={{
                            padding: '16px 20px',
                            marginBottom: 20,
                            borderRadius: 8,
                            background: '#fef2f2',
                            border: '1px solid #fecaca',
                            color: '#991b1b',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 12
                        }}>
                            <AlertTriangle size={20} style={{ flexShrink: 0 }} />
                            <div>
                                <div style={{ fontWeight: 700, fontSize: 14 }}>System Notice: Portal Under Scheduled Maintenance</div>
                                <div style={{ fontSize: 12, marginTop: 2 }}>Some workspace ordering and submission features may be temporarily limited while administrative maintenance is underway.</div>
                            </div>
                        </div>
                    )}

                    <Flash />
                    {children}
                    {demoMode && (
                        <div className="demo-banner">
                            LOCAL PREVIEW · Synthetic demonstration data. Earnings and payouts are not active.
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
