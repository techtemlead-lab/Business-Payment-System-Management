import { useEffect, useRef } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { number } from './UI';
import PartnerViewSelect from './PartnerViewSelect';
import { useT } from '../shared/i18n/t';
import '../../css/partner-hub.css';

export type PartnerHub = {
    title: string;
    active: string;
    tabs: { key: string; path: string; label: string }[];
};

/** Up to this many tabs stay a compact segmented row on a phone; more become one drop-down. */
const SEGMENTED_UP_TO = 3;

/**
 * The tab row that joins related partner pages. The tabs are real links to each page's
 * own address, so old links keep working and a tab only loads what it shows. A phone
 * gets a drop-down when there are more than three tabs, so nothing scrolls sideways.
 */
export default function PartnerHubNav({ hub }: { hub: PartnerHub }) {
    const translate = useT();
    const { portalBase, unreadNotices } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const listRef = useRef<HTMLElement>(null);
    const many = hub.tabs.length > SEGMENTED_UP_TO;

    // Narrow screens may still scroll the tabs a little: keep the open one in view after a page load.
    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>('.is-active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [hub.active]);

    return (
        <div className={`partner-hub-nav${many ? ' phn-many' : ' phn-few'}`}>
            <h2 className="partner-hub-title">{hub.title}</h2>
            {many && (
                <PartnerViewSelect
                    className="phn-select"
                    label={hub.title}
                    value={hub.active}
                    options={hub.tabs.map((tab) => {
                        const unread = tab.key === 'notifications' ? Number(unreadNotices ?? 0) : 0;

                        return { value: tab.key, label: unread > 0 ? `${tab.label} (${number(unread)})` : tab.label };
                    })}
                    onChange={(key) => {
                        const tab = hub.tabs.find((item) => item.key === key);
                        if (tab) {
                            router.visit(`${base}${tab.path}`);
                        }
                    }}
                />
            )}
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
