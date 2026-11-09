import { useEffect, useRef } from 'react';
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
