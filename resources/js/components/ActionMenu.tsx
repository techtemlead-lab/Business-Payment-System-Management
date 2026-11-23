import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, MoreHorizontal } from 'lucide-react';
import { useT } from '../shared/i18n/t';

/** Opens and closes a small panel next to its button: a click outside or the Escape key closes it. */
export function usePopover() {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onPointer = (event: MouseEvent | TouchEvent) => {
            if (!box.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                box.current?.querySelector<HTMLElement>('[aria-haspopup]')?.focus();
            }
        };
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('touchstart', onPointer, { passive: true });
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('touchstart', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return { open, setOpen, box };
}

export type ActionMenuItem = {
    key: string;
    label: string;
    /** A page address: the item is a link. Leave it out and give onSelect for a button. */
    href?: string;
    /** Open the link in a new tab, as for a download or a printout. */
    external?: boolean;
    onSelect?: () => void;
    /** Draw it as a risky action (delete, reject). */
    danger?: boolean;
    icon?: ReactNode;
    disabled?: boolean;
    /** A small figure on the right, such as a count. */
    meta?: string;
    /** The choice that is open now (a tab menu). */
    active?: boolean;
};

/**
 * The page's "More" menu: one quiet button that holds the secondary actions, so a header or a toolbar
 * keeps one primary action in view and nothing wraps into several uneven lines.
 */
export default function ActionMenu({ items, label, align = 'end', iconOnly = false, className = '', triggerClassName = '' }: {
    items: ActionMenuItem[];
    /** The button's words; "More" when left out. */
    label?: string;
    align?: 'start' | 'end';
    /** Show only the dots (phones), keeping the words for screen readers. */
    iconOnly?: boolean;
    className?: string;
    /** Extra classes for the button, for example "is-active" when one of the hidden choices is open. */
    triggerClassName?: string;
}) {
    const translate = useT();
    const { open, setOpen, box } = usePopover();
    const menuId = useId();
    const shown = items.filter(Boolean);

    if (shown.length === 0) {
        return null;
    }

    const text = label ?? translate('office_shell', 'more');
    const close = () => setOpen(false);

    return (
        <div className={`action-menu${className ? ` ${className}` : ''}`} ref={box}>
            <button
                type="button"
                className={`action-menu-trigger${open ? ' is-open' : ''}${iconOnly ? ' is-icon' : ''}${triggerClassName ? ` ${triggerClassName}` : ''}`}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                aria-label={iconOnly ? text : undefined}
                title={iconOnly ? text : undefined}
                onClick={() => setOpen((value) => !value)}
            >
                {iconOnly ? <MoreHorizontal size={18} aria-hidden="true" /> : <><span>{text}</span><ChevronDown size={15} aria-hidden="true" /></>}
            </button>
            {open && (
                <div id={menuId} className={`action-menu-panel is-${align}`} role="menu">
                    {shown.map((item) => {
                        const cls = `action-menu-item${item.danger ? ' is-danger' : ''}${item.active ? ' is-active' : ''}`;
                        const inner = <>{item.icon}<span>{item.label}</span>{item.meta !== undefined && <b className="action-menu-meta">{item.meta}</b>}</>;
                        if (item.href && !item.disabled) {
                            return item.external
                                ? <a key={item.key} role="menuitem" className={cls} href={item.href} target="_blank" rel="noopener noreferrer" onClick={close}>{inner}</a>
                                : <Link key={item.key} role="menuitem" className={cls} href={item.href} aria-current={item.active ? 'page' : undefined} onClick={close}>{inner}</Link>;
                        }
                        return (
                            <button key={item.key} type="button" role="menuitem" className={cls} disabled={item.disabled} onClick={() => { close(); item.onSelect?.(); }}>
                                {inner}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
