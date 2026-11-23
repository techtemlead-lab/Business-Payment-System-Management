import { useCallback, useEffect, useRef, useState } from 'react';
import AdaptiveTabs from './AdaptiveTabs';
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

/** Tabs across pages the office uses as one. Each tab is the page's own address, so old links keep working. */
export default function OfficeHubTabs({ hub }: { hub: OfficeHub }) {
    if (hub.tabs.length < 2) {
        return null;
    }

    return (
        <AdaptiveTabs
            kind="underline"
            ariaLabel={hub.label}
            tabs={hub.tabs.map((tab) => ({ key: tab.key, label: tab.label, href: tab.href, active: tab.active, count: tab.count }))}
        />
    );
}

/** The Report Centre's groups: orders, money, partners, stock and staff. */
export function ReportGroupTabs({ groups, active, onSelect }: { groups: ReportGroup[]; active: string; onSelect: (key: string) => void }) {
    const translate = useT();

    return (
        <AdaptiveTabs
            kind="pill"
            ariaLabel={translate('portal', 'ops_report')}
            tabs={groups.map((group) => ({ key: group.key, label: group.label, active: group.key === active, onSelect: () => onSelect(group.key) }))}
        />
    );
}
