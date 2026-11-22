import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { number } from './UI';
import { useT } from '../shared/i18n/t';

export type OfficeHub = {
    home: string;
    label: string;
    tabs: Array<{ key: string; label: string; href: string; active: boolean; count?: number }>;
};

export type ReportGroup = {
    key: string;
    label: string;
    reports: string[];
    links: Array<{ label: string; href: string }>;
};

type ScrollEdges = { start: boolean; end: boolean };

/**
 * Tells a sideways-scrolling strip whether more of it hides at its start or end, so the strip can fade that edge.
 * Spread `edgeClass` into the strip's class list and attach `ref` and `onScroll`.
 */
export function useScrollEdges<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [edges, setEdges] = useState<ScrollEdges>({ start: false, end: false });

    const measure = useCallback(() => {
        const box = ref.current;
        if (!box) {
            return;
        }
        const start = box.scrollLeft > 2;
        const end = box.scrollWidth - box.clientWidth - box.scrollLeft > 2;
        setEdges((current) => (current.start === start && current.end === end ? current : { start, end }));
    }, []);

    useEffect(() => {
        measure();
        const box = ref.current;
        if (!box || typeof ResizeObserver === 'undefined') {
            return undefined;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(box);
        return () => observer.disconnect();
    }, [measure]);

    const edgeClass = `${edges.start ? ' has-more-start' : ''}${edges.end ? ' has-more-end' : ''}`;

    return { ref, onScroll: measure, edgeClass, measure };
}

/** Scrolls the open tab to the middle of its own strip without moving the page. */
function centreActiveTab(strip: HTMLElement | null): void {
    const tab = strip?.querySelector<HTMLElement>('.is-active, [aria-selected="true"]');
    if (!strip || !tab || strip.scrollWidth <= strip.clientWidth) {
        return;
    }
    const reduced = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const tabLeft = tab.getBoundingClientRect().left - strip.getBoundingClientRect().left + strip.scrollLeft;
    strip.scrollTo({ left: tabLeft - (strip.clientWidth - tab.offsetWidth) / 2, behavior: reduced ? 'auto' : 'smooth' });
}

/** Tabs across pages the office uses as one. Each tab is the page's own address, so old links keep working. */
export default function OfficeHubTabs({ hub }: { hub: OfficeHub }) {
    const { ref: strip, onScroll, edgeClass } = useScrollEdges<HTMLElement>();
    const activeTab = hub.tabs.find((tab) => tab.active)?.key;

    // On a phone the strip scrolls sideways: keep the open tab in the middle, so its neighbours show on both sides.
    useEffect(() => {
        centreActiveTab(strip.current);
    }, [activeTab, strip]);

    if (hub.tabs.length < 2) {
        return null;
    }

    return (
        <nav ref={strip} onScroll={onScroll} className={`office-tabs${edgeClass}`} aria-label={hub.label}>
            {hub.tabs.map((tab) => (
                <Link key={tab.key} href={tab.href} className={`office-tab${tab.active ? ' is-active' : ''}`} aria-current={tab.active ? 'page' : undefined}>
                    {tab.label}
                    {(tab.count ?? 0) > 0 && <b className="office-tab-count">{number(tab.count)}</b>}
                </Link>
            ))}
        </nav>
    );
}

/** The Report Centre's groups: orders, money, partners, stock and staff. */
export function ReportGroupTabs({ groups, active, onSelect }: { groups: ReportGroup[]; active: string; onSelect: (key: string) => void }) {
    const translate = useT();
    const { ref: strip, onScroll, edgeClass } = useScrollEdges<HTMLDivElement>();

    useEffect(() => {
        centreActiveTab(strip.current);
    }, [active, strip]);

    return (
        <div ref={strip} onScroll={onScroll} className={`order-status-tabs${edgeClass}`} role="tablist" aria-label={translate('portal', 'ops_report')}>
            {groups.map((group) => (
                <button key={group.key} type="button" role="tab" aria-selected={group.key === active} className={`order-status-tab${group.key === active ? ' is-active' : ''}`} onClick={() => onSelect(group.key)}>
                    {group.label}
                </button>
            ))}
        </div>
    );
}
