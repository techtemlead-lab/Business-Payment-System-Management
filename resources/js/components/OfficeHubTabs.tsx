import React from 'react';
import { Link } from '@inertiajs/react';
import { useT } from '../shared/i18n/t';

export type OfficeHub = {
    home: string;
    label: string;
    tabs: Array<{ key: string; label: string; href: string; active: boolean }>;
};

export type ReportGroup = {
    key: string;
    label: string;
    reports: string[];
    links: Array<{ label: string; href: string }>;
};

/** Tabs across pages the office uses as one. Each tab is the page's own address, so old links keep working. */
export default function OfficeHubTabs({ hub }: { hub: OfficeHub }) {
    if (hub.tabs.length < 2) {
        return null;
    }

    return (
        <nav className="order-status-tabs" aria-label={hub.label}>
            {hub.tabs.map((tab) => (
                <Link key={tab.key} href={tab.href} className={`order-status-tab${tab.active ? ' is-active' : ''}`} aria-current={tab.active ? 'page' : undefined}>
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}

/** The Report Centre's groups: orders, money, partners, stock and staff. */
export function ReportGroupTabs({ groups, active, onSelect }: { groups: ReportGroup[]; active: string; onSelect: (key: string) => void }) {
    const translate = useT();

    return (
        <div className="order-status-tabs" role="tablist" aria-label={translate('portal', 'ops_report')}>
            {groups.map((group) => (
                <button key={group.key} type="button" role="tab" aria-selected={group.key === active} className={`order-status-tab${group.key === active ? ' is-active' : ''}`} onClick={() => onSelect(group.key)}>
                    {group.label}
                </button>
            ))}
        </div>
    );
}
