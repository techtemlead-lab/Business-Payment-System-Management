import {useState} from 'react';
import {Link, router, usePage} from '@inertiajs/react';
import {Search, Store, Menu, ShoppingBag, Home, Network, Wallet} from 'lucide-react';
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

export function DealerBottomNav({onMenu}:{onMenu:()=>void}){
    const translate = useT();
    const page = usePage<any>();
    const {url} = page;
    const role = String(page.props.auth?.user?.role ?? 'dealer');
    const controls = page.props.dashboardControls;
    const path = url.split('?')[0] || '/';
    const item = (href: string, label: string, Icon: typeof Home, className = '') => {
        const active = href === '/' ? path === '/' : path === href || path.startsWith(href + '/');
        return (
            <Link href={href} className={`${className} ${active ? 'is-active' : ''}`.trim()} aria-current={active ? 'page' : undefined} title={label}>
                <Icon size={href === '/' ? 22 : 20}/>
                {label}
            </Link>
        );
    };

    const ordersEnabled = partnerChromeEnabled(role, controls, 'orders');
    const catalogEnabled = partnerChromeEnabled(role, controls, 'catalog');
    const commerceHref = ordersEnabled ? '/orders' : (catalogEnabled ? '/catalog' : null);
    const commerceIcon = ordersEnabled ? ShoppingBag : Store;
    const commerceLabel = ordersEnabled ? translate('portal', 'orders') : translate('portal', 'shop');

    return (
        <nav className="portal-bottom-nav" aria-label={translate('portal', 'account_navigation')}>
            <button type="button" onClick={onMenu} aria-label={translate('portal', 'open_navigation')} title={translate('portal', 'open_navigation')}>
                <Menu size={20}/>{translate('portal', 'menu')}
            </button>
            {item('/', translate('portal', 'nav_module_home'), Home, 'portal-mobile-home')}
            {commerceHref && item(commerceHref, commerceLabel, commerceIcon)}
            {role === 'dealer'
                ? (partnerChromeEnabled(role, controls, 'network') && item('/network', translate('portal', 'nav_module_network'), Network))
                : null}
            {partnerChromeEnabled(role, controls, 'earnings') && item('/my-money', translate('portal', 'nav_module_earnings'), Wallet)}
        </nav>
    );
}
