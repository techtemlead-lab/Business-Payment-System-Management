import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { number } from './UI';
import { useT } from '../shared/i18n/t';
import '../../css/partner-hub.css';

export type PartnerHub = {
    title: string;
    active: string;
    tabs: { key: string; path: string; label: string }[];
};

/**
 * The tab row that joins related partner pages. The tabs are real links to each page's
 * own address, so old links keep working and a tab only loads what it shows.
 */
export default function PartnerHubNav({ hub }: { hub: PartnerHub }) {
    const translate = useT();
    const { portalBase, unreadNotices } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const listRef = useRef<HTMLElement>(null);

    // Phones scroll the tabs sideways: keep the open one in view after a page load.
    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>('.is-active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [hub.active]);

    return (
        <div className="partner-hub-nav">
            <h2 className="partner-hub-title">{hub.title}</h2>
            <nav ref={listRef} className="order-status-tabs" aria-label={translate('partner_hub', 'tabs_aria')}>
                {hub.tabs.map((tab) => {
                    const isActive = tab.key === hub.active;
                    const unread = tab.key === 'notifications' ? Number(unreadNotices ?? 0) : 0;

                    return (
                        <Link key={tab.key} href={`${base}${tab.path}`} className={`order-status-tab${isActive ? ' is-active' : ''}`} aria-current={isActive ? 'page' : undefined}>
                            <span>{tab.label}</span>
                            {unread > 0 && <b>{number(unread)}</b>}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}

export type SubViewItem = { key: string; label: string; href?: string };

/** Smaller buttons inside a tab, so one list is on screen at a time. An item with an href opens another page. */
export function SubViewChips({ items, active, onSelect }: { items: SubViewItem[]; active: string; onSelect?: (key: string) => void }) {
    const translate = useT();

    return (
        <div className="preset-chips partner-hub-views" role="group" aria-label={translate('partner_hub', 'views_aria')}>
            {items.map((item) => {
                const className = `button small${item.key === active ? ' dark' : ''}`;

                return item.href ? (
                    <Link key={item.key} className={className} href={item.href} aria-current={item.key === active ? 'page' : undefined}>{item.label}</Link>
                ) : (
                    <button key={item.key} type="button" className={className} aria-pressed={item.key === active} onClick={() => onSelect?.(item.key)}>{item.label}</button>
                );
            })}
        </div>
    );
}

/** Which list inside a tab is showing. It lives in the address as ?view=, so a reload or shared link keeps it. */
export function useSubView<View extends string>(allowed: readonly View[], fallback: View): [View, (next: string) => void] {
    const { url } = usePage();
    const requested = new URLSearchParams(url.split('?')[1] ?? '').get('view');
    const [view, setView] = useState<View>(allowed.includes(requested as View) ? (requested as View) : fallback);

    const choose = (next: string): void => {
        setView(next as View);
        const params = new URLSearchParams(window.location.search);
        params.set('view', next);
        window.history.replaceState(window.history.state, '', `${window.location.pathname}?${params.toString()}${window.location.hash}`);
    };

    return [view, choose];
}

export const ALL_RECORDS_VIEWS = ['commissions', 'orders', 'team', 'team_orders'] as const;
export type AllRecordsView = (typeof ALL_RECORDS_VIEWS)[number];

/** The lists under "সব হিসাব একসাথে", including the party ledger, which is its own page. */
export function AllRecordsViews({ active, onSelect }: { active: string; onSelect?: (key: string) => void }) {
    const translate = useT();
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const items: SubViewItem[] = [
        ...ALL_RECORDS_VIEWS.map((key) => ({
            key,
            label: translate('partner_hub', `view_${key}`),
            href: onSelect ? undefined : `${base}/full-report?view=${key}`,
        })),
        { key: 'statement', label: translate('portal', 'stmt_title'), href: `${base}/statement` },
    ];

    return <SubViewChips items={items} active={active} onSelect={onSelect} />;
}
