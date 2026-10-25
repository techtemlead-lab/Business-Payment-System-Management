import {Link, router, usePage} from '@inertiajs/react';
import {Search, Gift, Layers, UserRound, Store, Menu, ShoppingBag, Home, LogOut, FileBarChart2, Bell} from 'lucide-react';
import {useT} from '../shared/i18n/t';

export function DealerHeader(){
    const page = usePage<any>().props;
    const translate = useT();
    const publicBase = String(page.publicBase ?? '');
    const customize = page.siteCustomization ?? {};
    const offersTitle = customize.header_offers_title || translate('portal', 'header_offers');
    const offersSubtitle = customize.header_offers_subtitle || translate('portal', 'header_offers_sub');
    const networkTitle = customize.header_projects_title || translate('portal', 'header_network');
    const networkSubtitle = customize.header_projects_subtitle || translate('portal', 'header_network_sub');

    return <>
        <div className="portal-company-bar">MADINA NATURAL HEALTH CARE</div>
        <header className="portal-site-header">
            <div className="portal-container portal-header-inner">
                <a href={`${publicBase}/`} className="portal-logo" aria-label="Madina Natural Health Care home">
                    <img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care"/>
                </a>
                <form method="get" action={`${publicBase}/products`} className="portal-site-search">
                    <input name="q" placeholder={translate('portal', 'search_products')} aria-label={translate('portal', 'search_products')}/>
                    <button aria-label={translate('portal', 'search_products')}><Search size={22}/></button>
                </form>
                <div className="portal-header-links">
                    <a href={`${publicBase}/#featured-products`}><Gift/><span><b>{offersTitle}</b><small>{offersSubtitle}</small></span></a>
                    <a href={`${publicBase}/dealers`}><Layers/><span><b>{networkTitle}</b><small>{networkSubtitle}</small></span></a>
                    <Link href="/profile"><UserRound/><span><b>{translate('portal', 'nav_module_account')}</b><small>{translate('portal', 'profile')}</small></span></Link>
                    <button type="button" onClick={()=>router.post('/logout')} className="portal-header-logout" aria-label={translate('portal', 'log_out')} title={translate('portal', 'log_out')}>
                        <LogOut size={18}/><span><b>{translate('portal', 'log_out')}</b><small>{translate('portal', 'exit_session')}</small></span>
                    </button>
                    <Link href="/catalog" className="portal-shop"><Store size={18}/><b>{translate('portal', 'shop')}</b></Link>
                </div>
            </div>
        </header>
    </>;
}

export function DealerBottomNav({onMenu}:{onMenu:()=>void}){
    const translate = useT();
    const {url} = usePage();
    const path = url.split('?')[0] || '/';
    const item = (href: string, label: string, Icon: typeof Home, className = '') => {
        const active = href === '/' ? path === '/' : path === href || path.startsWith(href + '/');
        return (
            <Link href={href} className={`${className} ${active ? 'is-active' : ''}`.trim()} aria-current={active ? 'page' : undefined}>
                <Icon size={href === '/' ? 22 : 20}/>
                {label}
            </Link>
        );
    };

    return (
        <nav className="portal-bottom-nav" aria-label={translate('portal', 'account_navigation')}>
            <button type="button" onClick={onMenu} aria-label={translate('portal', 'open_navigation')}>
                <Menu size={20}/>{translate('portal', 'menu')}
            </button>
            {item('/catalog', translate('portal', 'shop'), Store)}
            {item('/', translate('portal', 'overview'), Home, 'portal-mobile-home')}
            {item('/orders', translate('portal', 'orders'), ShoppingBag)}
            {item('/full-report', translate('portal', 'full-report'), FileBarChart2)}
            {item('/notifications', translate('portal', 'notifications'), Bell)}
        </nav>
    );
}
