import { useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { ChevronDown, MoreHorizontal } from 'lucide-react';
import { useT } from '../../shared/i18n/t';

export type MoreMenuItem = {
    key: string;
    label: string;
    /** A file or page; the row is a link. */
    href?: string;
    /** Runs on click. Return false to keep the menu open. */
    onSelect?: (event: MouseEvent) => boolean | void;
};

export type MoreMenuGroup = { key: string; label?: string; items: MoreMenuItem[] };

/**
 * The one small menu beside a table: download, print, saved filters, select.
 * Put a panel such as SavedFilterPanel in children; it shows under the rows.
 */
export default function MoreMenu({ label, icon, groups = [], note, onClosed, children }: {
    /** Defaults to "আরও". */
    label?: string;
    icon?: ReactNode;
    groups?: MoreMenuGroup[];
    /** A short message at the top of the open menu. */
    note?: string;
    onClosed?: () => void;
    children?: ReactNode;
}) {
    const translate = useT();
    const [open, setOpen] = useState(false);
    const holder = useRef<HTMLDivElement>(null);
    const notifyClosed = useRef(onClosed);
    notifyClosed.current = onClosed;

    useEffect(() => {
        if (!open) {
            notifyClosed.current?.();

            return undefined;
        }
        const closeOutside = (event: globalThis.MouseEvent) => {
            if (!holder.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', closeOutside);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    const choose = (event: MouseEvent, item: MoreMenuItem) => {
        if (item.onSelect?.(event) !== false) {
            setOpen(false);
        }
    };

    const visibleGroups = groups.filter((group) => group.items.length > 0);
    if (visibleGroups.length === 0 && !children) {
        return null;
    }

    return (
        <div className="office-menu" ref={holder}>
            <button type="button" className="button small" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((current) => !current)}>
                {icon ?? <MoreHorizontal size={14} aria-hidden="true" />} {label ?? translate('office_shell', 'more')} <ChevronDown size={14} aria-hidden="true" />
            </button>
            {open && (
                <div className="office-menu-list" role={children ? undefined : 'menu'}>
                    {note && <p className="office-menu-note" role="alert">{note}</p>}
                    {visibleGroups.map((group) => (
                        <div key={group.key} className="office-menu-group" role="group" aria-label={group.label}>
                            {group.label && <h3>{group.label}</h3>}
                            {group.items.map((item) => (item.href
                                ? <a key={item.key} role="menuitem" href={item.href} onClick={(event) => choose(event, item)}>{item.label}</a>
                                : <button key={item.key} type="button" role="menuitem" onClick={(event) => choose(event, item)}>{item.label}</button>))}
                        </div>
                    ))}
                    {children && <div className="office-menu-panel">{children}</div>}
                </div>
            )}
        </div>
    );
}
