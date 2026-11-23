import { Link, router } from '@inertiajs/react';
import ActionMenu, { type ActionMenuItem } from './ActionMenu';
import { number } from './UI';
import { useT } from '../shared/i18n/t';

export type AdaptiveTab = {
    key: string;
    label: string;
    active: boolean;
    count?: number;
    /** A page address (a hub tab). Leave it out and give onSelect (a status tab). */
    href?: string;
    onSelect?: () => void;
    /** Draw it quiet: a status with nothing in it. */
    empty?: boolean;
};

/** From this many tabs a phone gets one drop-down instead of a strip that scrolls sideways. */
const PHONE_SELECT_FROM = 4;

/**
 * Tabs that stay tidy at any width: a computer shows the first few as a row and keeps the rest under "More"
 * (the open one is named on the button), a phone shows one drop-down. Same words and counts everywhere.
 */
export default function AdaptiveTabs({ tabs, ariaLabel, kind, maxVisible = 7, showZeroCount = false }: {
    tabs: AdaptiveTab[];
    ariaLabel: string;
    /** "underline" for the page tabs of a hub, "pill" for the status tabs above a list. */
    kind: 'underline' | 'pill';
    maxVisible?: number;
    showZeroCount?: boolean;
}) {
    const translate = useT();
    const rowClass = kind === 'underline' ? 'office-tabs' : 'order-status-tabs';
    const tabClass = kind === 'underline' ? 'office-tab' : 'order-status-tab';
    const countClass = kind === 'underline' ? 'office-tab-count' : undefined;
    const hasSelect = tabs.length >= PHONE_SELECT_FROM;
    const overflowing = tabs.length > maxVisible;
    const shown = overflowing ? tabs.slice(0, maxVisible - 1) : tabs;
    const hidden = overflowing ? tabs.slice(maxVisible - 1) : [];
    const hiddenActive = hidden.find((tab) => tab.active);
    const active = tabs.find((tab) => tab.active);
    const countText = (tab: AdaptiveTab): string | undefined => (tab.count !== undefined && (tab.count > 0 || showZeroCount) ? number(tab.count) : undefined);

    const open = (tab: AdaptiveTab) => {
        if (tab.onSelect) {
            tab.onSelect();
        } else if (tab.href) {
            router.visit(tab.href);
        }
    };

    const menuItems: ActionMenuItem[] = hidden.map((tab) => ({ key: tab.key, label: tab.label, href: tab.href, onSelect: tab.onSelect, meta: countText(tab), active: tab.active }));

    return (
        <div className={`adaptive-tabs is-${kind}${hasSelect ? ' has-select' : ''}`}>
            <div className={`${rowClass} adaptive-tabs-row`} role={kind === 'pill' ? 'tablist' : undefined} aria-label={ariaLabel}>
                {shown.map((tab) => {
                    const cls = `${tabClass}${tab.active ? ' is-active' : ''}${tab.empty ? ' is-empty' : ''}`;
                    const count = countText(tab);
                    const inner = <><span>{tab.label}</span>{count !== undefined && (countClass ? <b className={countClass}>{count}</b> : <b>{count}</b>)}</>;

                    return tab.href && !tab.onSelect
                        ? <Link key={tab.key} href={tab.href} className={cls} aria-current={tab.active ? 'page' : undefined}>{inner}</Link>
                        : <button key={tab.key} type="button" role={kind === 'pill' ? 'tab' : undefined} aria-selected={kind === 'pill' ? tab.active : undefined} className={cls} onClick={() => open(tab)}>{inner}</button>;
                })}
                {hidden.length > 0 && (
                    <ActionMenu
                        items={menuItems}
                        align="start"
                        className="adaptive-tabs-more"
                        triggerClassName={`${tabClass}${hiddenActive ? ' is-active' : ''}`}
                        label={hiddenActive ? hiddenActive.label : translate('office_shell', 'more')}
                    />
                )}
            </div>
            {hasSelect && (
                <div className="adaptive-tabs-select fd">
                    <select
                        className="fd-select"
                        aria-label={ariaLabel}
                        value={active?.key ?? ''}
                        onChange={(event) => {
                            const chosen = tabs.find((tab) => tab.key === event.target.value);
                            if (chosen) {
                                open(chosen);
                            }
                        }}
                    >
                        {!active && <option value="" disabled hidden>—</option>}
                        {tabs.map((tab) => {
                            const count = countText(tab);

                            return <option key={tab.key} value={tab.key}>{count === undefined ? tab.label : `${tab.label} (${count})`}</option>;
                        })}
                    </select>
                </div>
            )}
        </div>
    );
}
