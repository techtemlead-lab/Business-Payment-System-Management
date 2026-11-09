import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import {
    LayoutDashboard, ShoppingBag, ShoppingCart, Package, Users, Truck, Wallet, GitBranch,
    Settings, ShieldCheck, FileText, Leaf, Bell, Menu, X, LogOut, ArrowUpRight,
    Coins, BadgeCheck, BookOpen, MessageSquare, SlidersHorizontal, MapPin, Award,
    Trophy, TrendingUp, Percent, Store, Landmark, UserCheck, AlertTriangle,
    Briefcase, Hash, FileBarChart2, ChevronDown, UserRound, LifeBuoy, ArrowLeftRight, BarChart3, Compass, Search,
    Layers, ArrowDownToLine, ArrowUpFromLine, ScrollText, GraduationCap, CalendarDays, ReceiptText, Phone, Globe, Stethoscope, type LucideIcon
} from 'lucide-react';
import { Flash, label } from './UI';
import { DealerBottomNav, PartnerProductSearch } from './PortalHeader';
import LanguageSwitcher from './LanguageSwitcher';
import NotificationBell from './NotificationBell';
import GlobalSearchLauncher from './GlobalSearchLauncher';
import OnboardingTour from './OnboardingTour';
import { useNativeFieldMessages } from '../shared/useNativeFieldMessages';
import ThemeToggle from './ThemeToggle';
import { t, useT, useTranslations } from '../shared/i18n/t';
import {
    type NavMother,
    buildNavMothers,
    buildPartnerAvailable,
    buildStaffAvailable,
    compactMothers,
    isNavItemActive,
    labelKeyForNavItem,
    mother,
    resolveNavLink,
    sectionHref,
    staffBottomLinks,
} from '../shared/portalNav';

function BranchWorkspaceSelect({ branches }: { branches: { view?: string; can_view_all?: boolean; active_branch_id?: number | string | null; branches: Array<{ id: number | string; name: string }> } }) {
    const translate = useT();
    const root = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [pendingId, setPendingId] = useState<string | null>(null);
    const serverId = branches.view === 'all' ? 'all' : (branches.active_branch_id != null ? String(branches.active_branch_id) : '');
    const selectedId = pendingId ?? serverId;
    const selectedName = selectedId === 'all'
        ? translate('portal', 'branch_view_all')
        : (branches.branches.find((branch) => String(branch.id) === selectedId)?.name ?? translate('portal', 'branch_select'));

    useEffect(() => {
        setPendingId(null);
    }, [serverId]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const closeOnOutside = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', closeOnOutside);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('mousedown', closeOnOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    const choose = (id: string) => {
        setOpen(false);
        if (id === selectedId) {
            return;
        }
        setPendingId(id);
        const visit = { preserveScroll: true, onError: () => setPendingId(null) };
        if (id === 'all') {
            router.post('/commands/branches/view-all', {}, visit);
            return;
        }
        router.post(`/commands/branches/${id}/select`, {}, visit);
    };

    return (
        <div className="branch-workspace-select" ref={root}>
            <button
                type="button"
                className="branch-workspace-trigger"
                aria-label={translate('portal', 'branch_select')}
                aria-haspopup="listbox"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
            >
                <Store size={14} aria-hidden="true" />
                <span>{selectedName}</span>
                <ChevronDown size={14} aria-hidden="true" />
            </button>
            {open && (
                <ul className="branch-workspace-menu" role="listbox" aria-label={translate('portal', 'branch_select')}>
                    {branches.can_view_all && (
                        <li>
                            <button type="button" role="option" aria-selected={selectedId === 'all'} onClick={() => choose('all')}>
                                {translate('portal', 'branch_view_all')}
                            </button>
                        </li>
                    )}
                    {branches.branches.map((branch) => (
                        <li key={branch.id}>
                            <button type="button" role="option" aria-selected={selectedId === String(branch.id)} onClick={() => choose(String(branch.id))}>
                                {branch.name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const icons: Record<string, LucideIcon> = {
    'field-sales': MapPin, 'staff-operations': UserCheck, payroll: Coins, 'my-payroll': Wallet,
    'payment-accounts': Landmark, 'policy-schedules': Percent, 'reserve-payouts': Award,
    'data-imports': FileText, 'service-integrations': Settings, 'commission-holds': ShieldCheck, 'financial-readiness': BookOpen, 'financial-reports': FileText,
    overview: LayoutDashboard, orders: ShoppingBag, purchase: ShoppingCart, catalog: Package, 'catalog-products': Package,
    'catalog-taxonomy': Layers, accounts: Users, inventory: Package, stock: Package,
    'product-in': ArrowDownToLine, 'product-out': ArrowUpFromLine,
    deliveries: Truck,
    payments: Coins, network: GitBranch, policies: SlidersHorizontal, salary: BadgeCheck, withdrawals: Wallet, ledger: BookOpen,
    reports: FileText, customization: SlidersHorizontal, website: SlidersHorizontal, content: FileText, enquiries: MessageSquare, feedback: MessageSquare, 'share-feedback': MessageSquare, partnerships: Briefcase, audit: ShieldCheck,
    profile: Users, security: Settings, notifications: Bell, circulars: BookOpen, 'my-money': Coins, ranks: Award,
    commissions: Percent, 'network-tree': GitBranch, 'area-dealers': MapPin, 'place-codes': Hash, branches: Store, 'branch-performance': BarChart3, rewards: Trophy, 'capital-flow': TrendingUp,
    settlements: BadgeCheck, simulator: SlidersHorizontal, 'payouts-hub': Wallet,
    treasury: Landmark, 'sub-dealers': Store, 'sub-dealer-orders': ShoppingBag, 'sub-dealer-applications': Briefcase,
    employees: UserCheck, dashboards: SlidersHorizontal, help: LifeBuoy, 'support-admin': MessageSquare, agreement: ScrollText, 'top-sellers': Trophy,
    finance: Landmark, payouts: Wallet, accounting: BookOpen, 'partner-messages': MessageSquare, messages: MessageSquare, partners: Users, people: UserCheck, settings: Settings, 'login-rules': ShieldCheck,
    training: GraduationCap, learning: GraduationCap, events: CalendarDays, 'data-cleanup': Settings, 'privacy-requests': ShieldCheck, prescriptions: Stethoscope, clinic: Stethoscope, purchasing: ShoppingCart, 'stock-counts': Hash,
};

export default function Shell({ section, children, action }: { section: string; children: ReactNode; action?: ReactNode }) {
    useNativeFieldMessages();
    const page = usePage<any>();
    const { auth, portalBase, portal, demoMode, dashboardNotice, dashboardControls, siteCustomization, siteBrand, unreadNotices = 0, navAttention = {}, tour, userManualChapters, workspaceBranches } = page.props;
    const [manualOpen, setManualOpen] = useState(false);
    const [menuQuery, setMenuQuery] = useState('');
    const unreadCount = Number(unreadNotices ?? 0);
    const attention = {
        notifications: Number(navAttention?.notifications ?? unreadCount ?? 0),
        enquiries: Number(navAttention?.enquiries ?? 0),
        'payouts-hub': Number(navAttention?.withdrawals ?? 0),
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
    const dashboardTitle = ownerPortal
        ? translate('portal', 'owner_dashboard')
        : staff
            ? translate('portal', 'company_dashboard')
            : translate('portal', portal === 'subdealer' ? 'subdealer_workspace' : 'dealer_workspace');

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

    const staffAvailable = useMemo(
        () => buildStaffAvailable({ capabilities, role: user.role, ownerPortal, chatAnswerer: Boolean(auth.chatAnswerer), prescriber: Boolean(auth.prescriber) }),
        [capabilities, ownerPortal, user.role, auth.chatAnswerer, auth.prescriber],
    );

    const partnerAvailable = useMemo(
        () => buildPartnerAvailable(user.role, dashboardControls, Boolean(auth.prescriber)),
        [user.role, dashboardControls, auth.prescriber],
    );

    const navMothers = useMemo(
        () => buildNavMothers({ staff, role: user.role, staffAvailable, partnerAvailable }),
        [staff, user.role, partnerAvailable, staffAvailable],
    );

    const itemBadge = (item: string): number => {
        const sectionKey = resolveNavLink(item).section;
        if (sectionKey === 'notifications' || item === 'notifications') {
            return attention.notifications;
        }
        if (sectionKey === 'enquiries' || item === 'enquiries') {
            return attention.enquiries;
        }
        if (sectionKey === 'payouts-hub') {
            return attention['payouts-hub'];
        }
        if (sectionKey === 'support-admin' || item === 'support-admin') {
            return attention['support-admin'];
        }
        return 0;
    };

    const motherBadge = (items: string[]): number => items.reduce((sum, item) => sum + itemBadge(item), 0);

    const formatBadge = (count: number): string => (count > 99 ? '99+' : String(count));

    const activeMotherIds = useMemo(
        () => navMothers.filter((entry) => entry.items.some((item) => isNavItemActive(item, section, locationSearch))).map((entry) => entry.id),
        [navMothers, section, locationSearch],
    );

    const [expandedMother, setExpandedMother] = useState<string | null>(() => activeMotherIds[0] ?? null);

    const filteredMothers = useMemo(() => {
        const query = menuQuery.trim().toLowerCase();
        if (!query) {
            return navMothers;
        }

        return compactMothers(navMothers.map((entry) => {
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
    }, [navMothers, menuQuery, staff, translations]);

    const searchingMenu = menuQuery.trim().length > 0;

    useEffect(() => {
        setExpandedMother(activeMotherIds[0] ?? null);
    }, [activeMotherIds]);

    const toggleMother = (id: string) => {
        setExpandedMother((current) => (current === id ? null : id));
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
                prefetch
                onClick={() => setOpen(false)}
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

    const renderStaffSection = (entry: NavMother) => {
        const hasActive = entry.items.some((item) => isNavItemActive(item, section, locationSearch));
        const badge = motherBadge(entry.items);
        const Icon = icons[entry.id] || icons[entry.items[0]] || LayoutDashboard;

        if (entry.items.length === 1 && !searchingMenu) {
            return (
                <div key={entry.id} className={`nav-section nav-section-single ${hasActive ? 'has-active' : ''}`} data-nav-section={entry.id}>
                    <div className="nav-section-body">
                        {renderLink(entry.items[0])}
                    </div>
                </div>
            );
        }

        const expanded = searchingMenu || expandedMother === entry.id;

        return (
            <div key={entry.id} className={`nav-section ${expanded ? 'is-open' : ''} ${hasActive ? 'has-active' : ''}`} data-nav-section={entry.id}>
                <button
                    type="button"
                    className="nav-section-toggle"
                    aria-expanded={expanded}
                    onClick={() => toggleMother(entry.id)}
                >
                    <Icon size={15} />
                    <span>{translate('portal', entry.labelKey)}</span>
                    {badge > 0 && (
                        <span className="nav-attention-badge nav-section-badge" aria-hidden="true">
                            {formatBadge(badge)}
                        </span>
                    )}
                    <ChevronDown size={14} className="nav-chevron" aria-hidden="true" />
                </button>
                {expanded && (
                    <div className="nav-section-body">
                        {entry.items.map(renderLink)}
                    </div>
                )}
            </div>
        );
    };

    const renderPartnerMothers = (mothers: NavMother[]) => mothers.map((entry) => {
        const hasActive = entry.items.some((item) => isNavItemActive(item, section, locationSearch));

        if (entry.items.length === 1 && !searchingMenu) {
            return (
                <nav key={entry.id} className={`nav-group nav-mother nav-mother-single ${hasActive ? 'has-active' : ''}`} aria-label={translate('portal', entry.labelKey)}>
                    <div className="nav-mother-body">
                        {renderLink(entry.items[0])}
                    </div>
                </nav>
            );
        }

        const expanded = searchingMenu || expandedMother === entry.id;
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
    /** Each page has its own short subtitle; staff and partners get different wording. */
    const sectionHint = (item: string): string => {
        const key = `${staff ? 'section_hint_' : 'section_hint_partner_'}${item.replaceAll('-', '_')}`;
        const localized = translate('portal', key);
        return localized !== key ? localized : translate('portal', 'section_hint');
    };
    const currentSectionHint = section === 'overview' || section === ''
        ? translate('portal', 'welcome_back', { name: user.name.split(' ')[0], scope: translate('portal', staff ? 'business' : 'account') })
        : (section === 'catalog' && staff
            ? translate('portal', 'catalog_staff_hint')
            : sectionHint(headingItem));

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
                    <img src={siteBrand?.logo || '/madina-header-logo.png?v=10'} alt="" />
                    <span className="sidebar-logo-mark" aria-hidden="true">MNHC</span>
                </a>
                <div className="workspace-label">
                    <span className="live-dot" />
                    {hubLabel}
                </div>

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
                    {filteredMothers.length > 0 ? (
                        staff
                            ? <nav className="sidebar-section-list" aria-label={translate('portal', 'company_navigation')}>{filteredMothers.map(renderStaffSection)}</nav>
                            : renderPartnerMothers(filteredMothers)
                    ) : <p className="sidebar-menu-empty">{translate('portal', 'search_menu_empty')}</p>}
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
                            <b>{siteCustomization?.partner_help_title || translate('portal', 'help_title')}</b>
                            <span>{siteCustomization?.partner_help_body || translate('portal', 'help_body')}</span>
                            {siteCustomization?.partner_help_phone && (
                                <a href={`tel:${String(siteCustomization.partner_help_phone).replace(/[^0-9+]/g, '')}`}>
                                    {translate('portal', 'partner_help_call')} {siteCustomization.partner_help_phone} <Phone size={14} />
                                </a>
                            )}
                            <Link href={`${base}/help#support-request`} onClick={() => setOpen(false)}>{translate('partner_hub', 'help_card_link')} <ArrowUpRight size={14} /></Link>
                        </div>
                    )}
                </div>
            </aside>

            <div className="workspace" inert={open}>
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
                    {!staff && <PartnerProductSearch />}
                    {staff && workspaceBranches?.branches?.length > 0 && (
                        <BranchWorkspaceSelect branches={workspaceBranches} />
                    )}
                    <div className="header-actions" data-tour="header-actions">
                        <GlobalSearchLauncher />
                        <a
                            href={`${page.props.publicBase || ''}/`}
                            className="icon-button portal-website-link"
                            aria-label={translate('portal', 'portal_visit_website')}
                            title={translate('portal', 'portal_visit_website')}
                            data-tooltip={translate('portal', 'portal_visit_website')}
                        >
                            <Globe size={19} />
                        </a>
                        <ThemeToggle compact />
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
                        <NotificationBell base={base} unreadCount={unreadCount} />
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
                                    <span>{auth.customRoleName || label(user.role, translate)}</span>
                                </div>
                                <ChevronDown size={14} className={`profile-chevron ${profileOpen ? 'open' : ''}`} aria-hidden="true" />
                            </button>
                            {profileOpen && (
                                <div className="profile-menu-panel" role="menu">
                                    <div className="profile-menu-theme">
                                        <ThemeToggle />
                                    </div>
                                    <Link href={base + '/profile'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <UserRound size={16} />
                                        {translate('portal', 'view_profile')}
                                    </Link>
                                    <Link href={base + '/security'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <Settings size={16} />
                                        {translate('portal', 'account_settings')}
                                    </Link>
                                    <a href={`${page.props.publicBase || ''}/`} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <Globe size={16} />
                                        {translate('portal', 'portal_visit_website')}
                                    </a>
                                    <Link href={base + '/notifications'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <Bell size={16} />
                                        {translate('portal', 'notifications')}
                                    </Link>
                                    <Link href={base + '/help'} role="menuitem" onClick={() => setProfileOpen(false)}>
                                        <LifeBuoy size={16} />
                                        {translate('portal', 'help')}
                                    </Link>
                                    {staff && staffAvailable.has('my-payroll') && (
                                        <Link href={sectionHref(base, 'my-payroll')} role="menuitem" onClick={() => setProfileOpen(false)}>
                                            <Wallet size={16} />
                                            {translate('portal', 'my_payroll')}
                                        </Link>
                                    )}
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
                            <h1>{currentSectionTitle}</h1>
                            <p>{currentSectionHint}</p>
                        </div>
                        {action}
                    </div>

                    {dashboardNotice && dashboardNotice.text && (dashboardNotice.every_page || section === 'overview' || section === '') && (
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
                        <span>{siteBrand?.name || translate('portal', 'brand_company_bar')}</span>
                        <span>{translate('portal', 'footer_timezone_currency')}</span>
                    </footer>
                </main>
                {!staff && <DealerBottomNav onMenu={() => setOpen(true)} />}
                {staff && (
                    <nav className="portal-bottom-nav" aria-label={translate('portal', 'company_navigation')}>
                        <button type="button" onClick={() => setOpen(true)} aria-label={translate('portal', 'open_navigation')} title={translate('portal', 'open_navigation')}>
                            <Menu size={20} />{translate('portal', 'menu')}
                        </button>
                        {staffBottomLinks(staffAvailable).map((item) => {
                            const spec = resolveNavLink(item);
                            const Icon = icons[spec.iconKey] || LayoutDashboard;
                            const active = isNavItemActive(item, section, locationSearch);
                            return (
                                <Link key={item} href={sectionHref(base, item)} className={active ? 'is-active' : ''} aria-current={active ? 'page' : undefined}>
                                    <Icon size={20} />
                                    {sectionLabel(item)}
                                </Link>
                            );
                        })}
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
