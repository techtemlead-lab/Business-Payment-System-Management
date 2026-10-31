import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import {
    LayoutDashboard, ShoppingBag, ShoppingCart, Package, Users, Truck, Wallet, GitBranch,
    Settings, ShieldCheck, FileText, Leaf, Bell, Menu, X, LogOut, ArrowUpRight,
    Coins, BadgeCheck, BookOpen, MessageSquare, SlidersHorizontal, MapPin, Award,
    Trophy, TrendingUp, Percent, Store, Landmark, UserCheck, AlertTriangle,
    Briefcase, Hash, FileBarChart2, ChevronDown, UserRound, LifeBuoy, ArrowLeftRight, BarChart3, Compass, Search,
    Layers, ArrowDownToLine, ArrowUpFromLine, type LucideIcon
} from 'lucide-react';
import { Flash } from './UI';
import { DealerHeader, DealerBottomNav } from './PortalHeader';
import LanguageSwitcher from './LanguageSwitcher';
import OnboardingTour from './OnboardingTour';
import ThemeToggle from './ThemeToggle';
import { t, useT, useTranslations } from '../shared/i18n/t';
import {
    type NavMother,
    type StaffDomain,
    buildDomainMothers,
    buildPartnerAvailable,
    buildStaffAvailable,
    compactMothers,
    isNavItemActive,
    labelKeyForNavItem,
    mother,
    resolveNavLink,
    resolveStaffDomain,
    sectionHref,
} from '../shared/portalNav';

const icons: Record<string, LucideIcon> = {
    'field-sales': MapPin, 'staff-operations': UserCheck, payroll: Coins, 'my-payroll': Wallet,
    'payment-accounts': Landmark, 'policy-schedules': Percent, 'reserve-payouts': Award,
    'data-imports': FileText, 'service-integrations': Settings, 'commission-holds': ShieldCheck, 'financial-readiness': BookOpen, 'financial-reports': FileText,
    overview: LayoutDashboard, orders: ShoppingBag, purchase: ShoppingCart, catalog: Package, 'catalog-products': Package,
    'catalog-taxonomy': Layers, accounts: Users, inventory: Package, stock: Package,
    'product-in': ArrowDownToLine, 'product-out': ArrowUpFromLine,
    deliveries: Truck, dues: Wallet,
    payments: Coins, network: GitBranch, policies: SlidersHorizontal, salary: BadgeCheck, withdrawals: Wallet, ledger: BookOpen,
    reports: FileText, customization: SlidersHorizontal, website: SlidersHorizontal, content: FileText, enquiries: MessageSquare, feedback: MessageSquare, 'share-feedback': MessageSquare, partnerships: Briefcase, audit: ShieldCheck,
    profile: Users, security: Settings, notifications: Bell, circulars: BookOpen, earnings: Coins, 'full-report': FileBarChart2, ranks: Award,
    commissions: Percent, 'network-tree': GitBranch, 'area-dealers': MapPin, 'place-codes': Hash, branches: Store, 'branch-performance': BarChart3, rewards: Trophy, 'capital-flow': TrendingUp,
    settlements: BadgeCheck, simulator: SlidersHorizontal, 'payouts-hub': Wallet,
    treasury: Landmark, 'benefits-fund': ShieldCheck, 'sub-dealers': Store, 'sub-dealer-orders': ShoppingBag,
    employees: UserCheck, dashboards: SlidersHorizontal, help: LifeBuoy, 'support-admin': MessageSquare,
};

export default function Shell({ section, children, action }: { section: string; children: ReactNode; action?: ReactNode }) {
    const page = usePage<any>();
    const { auth, portalBase, portal, demoMode, dashboardNotice, dashboardControls, publicPageEnabled, publicBase, unreadNotices = 0, navAttention = {}, tour, userManualChapters, workspaceBranches } = page.props;
    const [manualOpen, setManualOpen] = useState(false);
    const [menuQuery, setMenuQuery] = useState('');
    const unreadCount = Number(unreadNotices ?? 0);
    const attention = {
        notifications: Number(navAttention?.notifications ?? unreadCount ?? 0),
        enquiries: Number(navAttention?.enquiries ?? 0),
        withdrawals: Number(navAttention?.withdrawals ?? 0),
        'support-admin': Number(navAttention?.['support-admin'] ?? 0),
    } as Record<string, number>;
    const translate = useT();
    const translations = useTranslations();
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

    const locationSearch = useMemo(() => {
        const pageUrl = String(page.url || '');
        const queryIndex = pageUrl.indexOf('?');
        if (queryIndex >= 0) {
            return pageUrl.slice(queryIndex);
        }
        if (typeof window !== 'undefined') {
            return window.location.search;
        }
        return '';
    }, [page.url]);

    const sectionLabel = (item: string) => {
        const key = labelKeyForNavItem(item, staff);
        return translate('portal', key);
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
                const items = sidebar.current?.querySelectorAll<HTMLElement>('a, button:not(:disabled), input');
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

    const readDomainPin = (): StaffDomain | null => {
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

    const writeDomainPin = (domain: StaffDomain) => {
        if (typeof window === 'undefined') {
            return;
        }
        try {
            window.sessionStorage.setItem('madina.staff.domain', domain);
        } catch {
            // Ignore private-mode storage failures.
        }
    };

    const currentDomain = resolveStaffDomain(section, readDomainPin);

    const staffAvailable = useMemo(
        () => buildStaffAvailable({ capabilities, role: user.role, ownerPortal }),
        [capabilities, ownerPortal, user.role],
    );

    const partnerAvailable = useMemo(
        () => buildPartnerAvailable(user.role, dashboardControls),
        [user.role, dashboardControls],
    );

    const domainMothers = useMemo(
        () => buildDomainMothers({
            staff,
            role: user.role,
            staffAvailable,
            partnerAvailable,
            currentDomain,
        }),
        [staff, user.role, partnerAvailable, staffAvailable, currentDomain],
    );

    const itemBadge = (item: string): number => {
        const sectionKey = resolveNavLink(item).section;
        if (sectionKey === 'notifications' || item === 'notifications') {
            return attention.notifications;
        }
        if (sectionKey === 'enquiries' || item === 'enquiries') {
            return attention.enquiries;
        }
        if (sectionKey === 'withdrawals' || item === 'withdrawals') {
            return attention.withdrawals;
        }
        if (sectionKey === 'support-admin' || item === 'support-admin') {
            return attention['support-admin'];
        }
        return 0;
    };

    const motherBadge = (items: string[]): number => items.reduce((sum, item) => sum + itemBadge(item), 0);

    const formatBadge = (count: number): string => (count > 99 ? '99+' : String(count));

    const activeMotherIds = useMemo(
        () => domainMothers.filter((entry) => entry.items.some((item) => isNavItemActive(item, section, locationSearch))).map((entry) => entry.id),
        [domainMothers, section, locationSearch],
    );

    const [expandedMothers, setExpandedMothers] = useState<string[]>(() => activeMotherIds);

    const filteredMothers = useMemo(() => {
        const query = menuQuery.trim().toLowerCase();
        if (!query) {
            return domainMothers;
        }

        return compactMothers(domainMothers.map((entry) => {
            const motherLabel = t(translations, 'portal', entry.labelKey).toLowerCase();
            if (motherLabel.includes(query)) {
                return entry;
            }
            const items = entry.items.filter((item) => {
                const label = t(translations, 'portal', labelKeyForNavItem(item, staff)).toLowerCase();
                return label.includes(query) || item.toLowerCase().includes(query);
            });
            return mother(entry.id, entry.labelKey, items);
        }));
    }, [domainMothers, menuQuery, staff, translations]);

    useEffect(() => {
        if (staff) {
            return;
        }
        setExpandedMothers((previous) => {
            const next = new Set(previous);
            activeMotherIds.forEach((id) => next.add(id));
            if (menuQuery.trim()) {
                filteredMothers.forEach((entry) => next.add(entry.id));
            }
            if (next.size === 0 && domainMothers[0]) {
                next.add(domainMothers[0].id);
            }
            const nextList = Array.from(next);
            if (nextList.length === previous.length && nextList.every((id) => previous.includes(id))) {
                return previous;
            }
            return nextList;
        });
    }, [activeMotherIds, domainMothers, filteredMothers, menuQuery, staff]);

    const toggleMother = (id: string) => {
        setExpandedMothers((previous) => (
            previous.includes(id) ? previous.filter((entry) => entry !== id) : [...previous, id]
        ));
    };

    const renderLink = (item: string) => {
        const spec = resolveNavLink(item);
        const Icon = icons[spec.iconKey] || icons[item] || LayoutDashboard;
        const badge = itemBadge(item);
        const active = isNavItemActive(item, section, locationSearch);
        return (
            <Link
                aria-current={active ? 'page' : undefined}
                className={active ? 'selected' : ''}
                href={sectionHref(base, item)}
                key={item}
                onClick={() => {
                    if (staff) {
                        writeDomainPin(currentDomain);
                    }
                    setOpen(false);
                }}
                data-tour={item === 'help' ? 'sidebar-help' : undefined}
                data-nav-item={item}
            >
                <Icon size={17} />
                <span className="nav-link-label">{sectionLabel(item)}</span>
                {badge > 0 && (
                    <span className="nav-attention-badge" aria-label={translate('portal', 'nav_attention_count', { count: String(badge) })}>
                        {formatBadge(badge)}
                    </span>
                )}
                {active && <i />}
            </Link>
        );
    };

    const renderStaffSections = (mothers: NavMother[]) => mothers.map((entry) => {
        const hasActive = entry.items.some((item) => isNavItemActive(item, section, locationSearch));
        const badge = motherBadge(entry.items);
        return (
            <nav
                key={entry.id}
                className={`nav-group nav-section ${hasActive ? 'has-active' : ''}`}
                aria-label={translate('portal', entry.labelKey)}
                data-nav-section={entry.id}
            >
                <div className="nav-section-label">
                    <span>{translate('portal', entry.labelKey)}</span>
                    {badge > 0 && (
                        <span className="nav-attention-badge nav-section-badge" aria-hidden="true">
                            {formatBadge(badge)}
                        </span>
                    )}
                </div>
                <div className="nav-section-body">
                    {entry.items.map(renderLink)}
                </div>
            </nav>
        );
    });

    const renderPartnerMothers = (mothers: NavMother[]) => mothers.map((entry) => {
        const expanded = expandedMothers.includes(entry.id) || Boolean(menuQuery.trim());
        const hasActive = entry.items.some((item) => isNavItemActive(item, section, locationSearch));
        const badge = motherBadge(entry.items);
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
                    {badge > 0 && (
                        <span className="nav-attention-badge nav-mother-badge" aria-hidden="true">
                            {formatBadge(badge)}
                        </span>
                    )}
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

    const headingItem = (() => {
        if (section === 'catalog' && staff) {
            return tabOfSearch(locationSearch) === 'taxonomy' ? 'catalog-taxonomy' : 'catalog-products';
        }
        if (section === 'inventory' && staff) {
            const action = actionOfSearch(locationSearch);
            if (action === 'stock_in') return 'product-in';
            if (action === 'stock_out') return 'product-out';
            return 'stock';
        }
        return section === '' ? 'overview' : section;
    })();

    const currentSectionTitle = section === 'overview' || section === ''
        ? dashboardTitle
        : sectionLabel(headingItem);
    const currentSectionHint = section === 'overview' || section === ''
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
                className={`sidebar ${open ? 'open' : ''} ${staff ? 'sidebar-premium' : ''}`}
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

                <label className="sidebar-menu-search">
                    <Search size={14} aria-hidden="true" />
                    <input
                        type="search"
                        value={menuQuery}
                        onChange={(event) => setMenuQuery(event.target.value)}
                        placeholder={translate('portal', 'search_menu')}
                        aria-label={translate('portal', 'search_menu')}
                        autoComplete="off"
                    />
                </label>

                <div className="sidebar-nav-container" data-tour="sidebar-nav">
                    {filteredMothers.length > 0
                        ? (staff ? renderStaffSections(filteredMothers) : renderPartnerMothers(filteredMothers))
                        : <p className="sidebar-menu-empty">{translate('portal', 'search_menu_empty')}</p>}
                </div>

                <div className="sidebar-footer">
                    <button
                        type="button"
                        className="sidebar-guide-button"
                        onClick={() => {
                            setOpen(false);
                            setManualOpen(true);
                        }}
                    >
                        <Compass size={18} />
                        <span>{translate('portal', 'tour_guide_launcher')}</span>
                    </button>
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
                    <div className="header-actions" data-tour="header-actions">
                        <ThemeToggle compact />
                        {staff && workspaceBranches?.branches?.length > 0 && (
                        <label className="branch-workspace-select" style={{display:'inline-flex',alignItems:'center',gap:6,fontSize:12}}>
                            <Store size={14} />
                            <select
                                aria-label={translate('portal','branch_select')}
                                value={workspaceBranches.active_branch_id ?? ''}
                                onChange={(e) => {
                                    const id = e.target.value;
                                    if (!id) return;
                                    router.post(`/commands/branches/${id}/select`, {}, { preserveScroll: true });
                                }}
                            >
                                <option value="">{translate('portal','branch_pick')}</option>
                                {workspaceBranches.branches.map((branch: any) => (
                                    <option key={branch.id} value={branch.id}>{branch.name}</option>
                                ))}
                            </select>
                        </label>
                    )}
                    <LanguageSwitcher compact />
                        <button
                            type="button"
                            className="icon-button tour-guide-launcher"
                            aria-label={translate('portal', 'tour_guide_launcher')}
                            title={translate('portal', 'tour_guide_launcher')}
                            data-tooltip={translate('portal', 'tour_guide_launcher')}
                            onClick={() => setManualOpen(true)}
                        >
                            <Compass size={19} />
                        </button>
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
                                    <button
                                        type="button"
                                        role="menuitem"
                                        onClick={() => {
                                            setProfileOpen(false);
                                            setManualOpen(true);
                                        }}
                                    >
                                        <Compass size={16} />
                                        {translate('portal', 'tour_guide_launcher')}
                                    </button>
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
            <OnboardingTour
                tour={tour}
                chapters={userManualChapters}
                forceOpenManual={manualOpen}
                onCloseManual={() => setManualOpen(false)}
            />
        </div>
    );
}

function tabOfSearch(search: string): string | null {
    return new URLSearchParams(search).get('tab');
}

function actionOfSearch(search: string): string | null {
    return new URLSearchParams(search).get('action');
}
