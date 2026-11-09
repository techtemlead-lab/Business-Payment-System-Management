import { useEffect, useRef } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { number } from '../UI';
import { useT } from '../../shared/i18n/t';

type Viewer = { finance: boolean; reports: boolean };
type Place = { key: string; href: string; label: string; open: (viewer: Viewer) => boolean };

const PLACES: Place[] = [
    { key: 'funds', href: '/treasury/funds', label: 'tab_funds', open: (viewer) => viewer.finance },
    { key: 'orders', href: '/treasury/orders', label: 'tab_orders', open: (viewer) => viewer.finance || viewer.reports },
    { key: 'events', href: '/treasury/events', label: 'tab_events', open: (viewer) => viewer.finance },
    { key: 'partners', href: '/treasury/partners', label: 'tab_partners', open: (viewer) => viewer.finance },
    { key: 'work', href: '/treasury/work', label: 'tab_work', open: (viewer) => viewer.finance },
];

const OWNER_ROLES = ['super_admin', 'md', 'chairman'];

/** Who may open what, by the same rules the server uses in TreasuryAccess. */
export function useTreasuryViewer(): Viewer {
    const { auth } = usePage<any>().props;
    const capabilities: string[] = auth?.capabilities ?? [];

    return {
        finance: OWNER_ROLES.includes(String(auth?.user?.role ?? '')) || capabilities.includes('finance'),
        reports: capabilities.includes('reports'),
    };
}

/** The five places inside Treasury. A place the viewer may not open is not offered. */
export default function TreasuryTabs() {
    const translate = useT();
    const page = usePage<any>();
    const viewer = useTreasuryViewer();
    const strip = useRef<HTMLElement>(null);
    const path = page.url.split('?')[0];
    const waiting = Number(page.props.tabs?.waiting ?? 0);
    const places = PLACES.filter((place) => place.open(viewer));

    // On a phone the strip scrolls sideways: keep the open place in view.
    useEffect(() => {
        strip.current?.querySelector<HTMLElement>('.is-active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [path]);

    if (places.length < 2) {
        return null;
    }

    return (
        <nav ref={strip} className="tsy-tabs" aria-label={translate('treasury', 'title')}>
            {places.map((place) => {
                const active = path === place.href || path.startsWith(`${place.href}/`);

                return (
                    <Link key={place.key} href={place.href} className={`tsy-tab${active ? ' is-active' : ''}`} aria-current={active ? 'page' : undefined}>
                        {translate('treasury', place.label)}
                        {place.key === 'work' && waiting > 0 && <b className="tsy-tab-count">{number(waiting)}</b>}
                    </Link>
                );
            })}
        </nav>
    );
}
