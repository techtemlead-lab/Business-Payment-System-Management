import {useState} from 'react';
import {Link, router, usePage} from '@inertiajs/react';
import {Search, Menu, ShoppingBag, Home, Wallet, LayoutDashboard, Package} from 'lucide-react';
import {useT} from '../shared/i18n/t';
import {partnerChromeEnabled} from '../shared/partnerChromeNav';

/** Product search in the partner top bar. It opens the portal's own product list, so nobody leaves the panel. */
export function PartnerProductSearch(){
    const translate = useT();
    const page = usePage<any>();
    const role = String(page.props.auth?.user?.role ?? 'dealer');
    const [query, setQuery] = useState('');
    if (!partnerChromeEnabled(role, page.props.dashboardControls, 'catalog')) {
        return null;
    }

    return (
        <form
            className="portal-product-search"
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                const term = query.trim();
                router.get('/catalog', term ? {q: term} : {});
            }}
        >
            <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={translate('portal', 'search_products')} aria-label={translate('portal', 'search_products')}/>
            <button type="submit" aria-label={translate('portal', 'search_products')} title={translate('portal', 'search_products')}><Search size={16}/></button>
        </form>
    );
}

/** The partner (dealer and sub-dealer) bottom bar: pictures only. Every item keeps its name as aria-label and tooltip. */
export function DealerBottomNav({onMenu, menuOpen = false}:{onMenu:()=>void; menuOpen?: boolean}){
    const translate = useT();
    const page = usePage<any>();
    const {url} = page;
    const role = String(page.props.auth?.user?.role ?? 'dealer');
    const controls = page.props.dashboardControls;
    const path = url.split('?')[0] || '/';
    const item = (href: string, label: string, Icon: typeof Home) => {
        const active = href === '/' ? path === '/' : path === href || path.startsWith(href + '/');
        return (
            <Link href={href} className={active ? 'is-active' : ''} aria-current={active ? 'page' : undefined} aria-label={label} title={label}>
                <Icon size={26} strokeWidth={2}/>
            </Link>
        );
    };
    const menuLabel = translate('portal', 'open_navigation');

    return (
        <nav className="portal-bottom-nav portal-bottom-nav--icons" aria-label={translate('portal', 'account_navigation')}>
            <button type="button" data-menu-trigger onClick={onMenu} aria-label={menuLabel} aria-controls="workspace-navigation" aria-expanded={menuOpen} title={menuLabel}>
                <Menu size={26} strokeWidth={2}/>
            </button>
            {item('/', translate('portal', 'nav_bottom_dashboard'), LayoutDashboard)}
            {partnerChromeEnabled(role, controls, 'catalog') && item('/catalog', translate('portal', 'nav_bottom_products'), Package)}
            {partnerChromeEnabled(role, controls, 'orders') && item('/orders', translate('portal', 'nav_bottom_orders'), ShoppingBag)}
            {partnerChromeEnabled(role, controls, 'earnings') && item('/my-money', translate('portal', 'nav_bottom_wallet'), Wallet)}
        </nav>
    );
}
