import { usePage } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import type { OfficeHub } from '../OfficeHubTabs';

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
export function useTreasuryHub(): OfficeHub {
    const translate = useT();
    const page = usePage<any>();
    const viewer = useTreasuryViewer();
    const path = page.url.split('?')[0];
    const waiting = Number(page.props.tabs?.waiting ?? 0);

    return {
        home: 'treasury',
        label: translate('treasury', 'title'),
        tabs: PLACES.filter((place) => place.open(viewer)).map((place) => ({
            key: place.key,
            label: translate('treasury', place.label),
            href: place.href,
            active: path === place.href || path.startsWith(`${place.href}/`),
            count: place.key === 'work' ? waiting : undefined,
        })),
    };
}
