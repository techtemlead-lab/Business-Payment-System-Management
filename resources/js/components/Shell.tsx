import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { LayoutDashboard, ShoppingBag, Package, Users, Truck, Wallet, GitBranch, Settings, ShieldCheck, FileText, Leaf, Bell, Menu, X, LogOut, ArrowUpRight, Coins, BadgeCheck, BookOpen, MessageSquare, SlidersHorizontal, type LucideIcon } from 'lucide-react';
import { Flash } from './UI';
import { DealerHeader, DealerBottomNav } from './PortalHeader';

const icons: Record<string, LucideIcon> = { overview: LayoutDashboard, orders: ShoppingBag, catalog: Package, accounts: Users, inventory: Package, deliveries: Truck, dues: Wallet, payments: Coins, network: GitBranch, policies: SlidersHorizontal, salary: BadgeCheck, withdrawals: Wallet, ledger: BookOpen, reports: FileText, customization: SlidersHorizontal, content: FileText, enquiries: MessageSquare, audit: ShieldCheck, profile: Users, security: Settings, notifications: Bell, earnings: Coins, ranks: BadgeCheck };
const names: Record<string, string> = { overview: 'Overview', orders: 'Orders', catalog: 'Product collection', accounts: 'Partner accounts', inventory: 'Inventory', deliveries: 'Deliveries', dues: 'Outstanding dues', payments: 'Payment review', network: 'My network', policies: 'Commission policies', salary: 'Salary & tiers', withdrawals: 'Withdrawals', ledger: 'Financial ledger', reports: 'Reports', customization: 'Site Customization', content: 'Website content', enquiries: 'Enquiries', audit: 'Activity log', profile: 'My profile', security: 'Account security', notifications: 'Notifications', earnings: 'My earnings', ranks: 'Rank progress' };

export default function Shell({ section, children, action }: { section: string; children: ReactNode; action?: ReactNode }) {
    const { auth, demoMode } = usePage<any>().props;
    const user = auth.user;
    const capabilities: string[] = auth.capabilities;
    const staff = capabilities.length > 0;
    const base = staff ? '/company' : '/workspace';
    const [mobile,setMobile] = useState(()=>matchMedia('(max-width:1023px)').matches);
    useEffect(()=>{const media=matchMedia('(max-width:1023px)');const change=()=>setMobile(media.matches);media.addEventListener('change',change);return()=>media.removeEventListener('change',change);},[]);
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

    const commerce = !staff || capabilities.includes('orders') || capabilities.includes('finance');
    const operation = ['overview', ...(commerce ? ['orders', 'deliveries', 'dues'] : []), 'catalog', ...(capabilities.includes('inventory') ? ['inventory'] : []), ...(capabilities.includes('accounts') ? ['accounts'] : [])];
    const finance = staff ? (capabilities.includes('finance') ? ['payments', 'policies', 'salary', 'withdrawals', 'ledger'] : []) : ['earnings', 'withdrawals', ...(user.role === 'sub_dealer' ? ['salary'] : ['network', 'ranks'])];
    const management = [...(capabilities.includes('reports') ? ['reports'] : []), ...(capabilities.includes('content') ? ['customization', 'content', 'enquiries'] : []), ...(capabilities.includes('audit') ? ['audit'] : [])];
    const group = (title: string, items: string[]) => items.length > 0 && <nav className="nav-group" aria-label={title}><span>{title}</span>{items.map(item => {
        const Icon = icons[item];
        return <Link onClick={() => setOpen(false)} aria-current={section === item ? 'page' : undefined} className={section === item ? 'selected' : ''} href={`${base}/${item === 'overview' ? '' : item}`} key={item}><Icon size={18} />{names[item]}{section === item && <i />}</Link>;
    })}</nav>;

    return <div className={`app-shell ${staff?'company-shell':'dealer-shell'}`}>
        <a className="skip-link" href="#workspace-main">Skip to content</a>
        {open && <div className="sidebar-overlay" onClick={() => setOpen(false)} />}
        <aside ref={sidebar} id="workspace-navigation" className={`sidebar ${open ? 'open' : ''}`} inert={!open&&(!staff||mobile)} aria-hidden={!open&&(!staff||mobile)} role={open?'dialog':undefined} aria-modal={open||undefined} aria-label={staff?'Company navigation':'Account navigation'}>
            <button className={`icon-button ${staff?'mobile-only':''}`} aria-label="Close navigation" onClick={() => setOpen(false)}><X size={18} /></button>
            <a href={base} className="portal-logo sidebar-logo" aria-label="Madina dashboard"><img src="/madina-header-logo-white.png?v=2" alt="Madina Natural Health Care"/></a>
            <div className="workspace-label"><span className="live-dot" />{staff ? 'Company workspace' : user.role === 'sub_dealer' ? 'Sub-dealer workspace' : 'Dealer workspace'}</div>
            {group('WORKSPACE', operation)}{group('FINANCE & GROWTH', finance)}{group('MANAGEMENT', management)}{group('ACCOUNT', ['profile', 'security'])}
            <div className="sidebar-footer">{!staff&&<div className="help-card"><Leaf size={22} /><b>Growing, together.</b><span>One connected Madina network.</span><a href="/contact">Contact the company <ArrowUpRight size={14} /></a></div>}<button className="sign-out" onClick={() => router.post('/logout')}><LogOut size={17} />Sign out</button></div>
        </aside>
        <div className="workspace" inert={open}>
            {!staff&&<DealerHeader/>}
            <header className="workspace-header"><div className="breadcrumb"><button ref={toggle} className="icon-button workspace-menu-button" aria-label="Open navigation" aria-controls="workspace-navigation" aria-expanded={open} onClick={() => setOpen(true)}><Menu size={19} /></button><span>Workspace</span><span>/</span><b>{names[section] ?? 'Order details'}</b></div>
                <div className="header-actions"><Link href={base+'/notifications'} className="icon-button" aria-label="Notifications"><Bell size={19} /></Link><div className="avatar">{user.name.slice(0, 1)}</div><div className="user-caption"><b>{user.name}</b><span>{user.role.replaceAll('_', ' ')}</span></div></div>
            </header>
            <main id="workspace-main" className="workspace-main">
                <div className="page-heading"><div><div className="eyebrow">MADINA / {staff ? 'COMPANY' : 'PARTNER'} PORTAL</div><h1>{section === 'overview' ? (staff?'Company Dashboard':'My Account') : names[section] ?? 'Order details'}</h1><p>{section === 'overview' ? `Welcome back, ${user.name.split(' ')[0]}. Here’s what’s happening across your ${staff ? 'business' : 'account'}.` : 'Everything you need, with a clear trail of activity.'}</p></div>{action}</div>
                <Flash />{children}
                {demoMode && <div className="demo-banner">LOCAL PREVIEW · Synthetic demonstration data. Earnings and payouts are not active.</div>}
                <footer className="workspace-footer"><span>Madina Natural Health Care</span><span>Asia/Dhaka · BDT</span></footer>
            </main>
            {!staff&&<DealerBottomNav onMenu={()=>setOpen(true)}/>}
        </div>
    </div>;
}

