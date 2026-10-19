import {Link, router, usePage} from '@inertiajs/react';
import {Search,Gift,Layers,UserRound,Store,Menu,ShoppingBag,Home,LogOut} from 'lucide-react';

export function DealerHeader(){
    const page = usePage<any>().props;
    const publicBase = String(page.publicBase ?? '');
    const navCategories: string[] = Array.isArray(page.navCategories) ? page.navCategories.filter(Boolean) : [];
    const customize = page.siteCustomization ?? {};
    const offersTitle = customize.header_offers_title || 'Offers';
    const offersSubtitle = customize.header_offers_subtitle || 'Latest Offers';
    const networkTitle = customize.header_projects_title || 'Dealers';
    const networkSubtitle = customize.header_projects_subtitle || 'Dealer Network';

    return <>
        <div className="portal-company-bar">MADINA NATURAL HEALTH CARE</div>
        <header className="portal-site-header">
            <div className="portal-container portal-header-inner">
                <a href={`${publicBase}/`} className="portal-logo" aria-label="Madina Natural Health Care home">
                    <img src="/madina-header-logo.png?v=6" alt="Madina Natural Health Care"/>
                </a>
                <form method="get" action={`${publicBase}/products`} className="portal-site-search">
                    <input name="q" placeholder="Search" aria-label="Search products"/>
                    <button aria-label="Search"><Search size={22}/></button>
                </form>
                <div className="portal-header-links">
                    <a href={`${publicBase}/#featured-products`}><Gift/><span><b>{offersTitle}</b><small>{offersSubtitle}</small></span></a>
                    <a href={`${publicBase}/dealers`}><Layers/><span><b>{networkTitle}</b><small>{networkSubtitle}</small></span></a>
                    <Link href="/profile"><UserRound/><span><b>Account</b><small>My Profile</small></span></Link>
                    <button type="button" onClick={()=>router.post('/logout')} className="portal-header-logout" aria-label="Log out" title="Log out">
                        <LogOut size={18}/><span><b>Log out</b><small>Exit Session</small></span>
                    </button>
                    <Link href="/catalog" className="portal-shop"><Store size={18}/><b>Shop</b></Link>
                </div>
            </div>
        </header>
        {navCategories.length > 0 && (
            <nav className="portal-category-nav" aria-label="Product categories">
                <div className="portal-container">
                    {navCategories.map(category=>
                        <a key={category} href={publicBase+'/products?category='+encodeURIComponent(category)}>{category}</a>
                    )}
                </div>
            </nav>
        )}
    </>;
}

export function DealerBottomNav({onMenu}:{onMenu:()=>void}){
    return <nav className="portal-bottom-nav" aria-label="Mobile account navigation">
        <button onClick={onMenu}><Menu size={20}/>Menu</button>
        <Link href="/catalog"><Store size={20}/>Shop</Link>
        <Link href="/" className="portal-mobile-home"><Home size={22}/>Dashboard</Link>
        <Link href="/orders"><ShoppingBag size={20}/>Orders</Link>
        <Link href="/earnings"><Gift size={20}/>Earnings</Link>
        <button type="button" onClick={()=>router.post('/logout')} aria-label="Log out" className="portal-mobile-logout"><LogOut size={20}/>Log out</button>
    </nav>;
}
