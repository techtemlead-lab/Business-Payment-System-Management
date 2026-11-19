import { useEffect, useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import '../../../css/screens-partner-cart.css';

/**
 * A small window for the cart and the chooser. On a computer it is a panel in the middle (the cart, a drawer on the right);
 * on a phone it rises from the bottom as a sheet with large buttons. Escape or a tap outside closes it.
 */
export default function CartSheet({ title, onClose, children, footer, side = false, testId }: {
    title: string;
    onClose: () => void;
    children: ReactNode;
    footer?: ReactNode;
    side?: boolean;
    testId?: string;
}) {
    const translate = useT();
    const panel = useRef<HTMLDivElement>(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        const before = document.activeElement as HTMLElement | null;
        const first = panel.current?.querySelector<HTMLElement>('[data-autofocus], input, button:not(.pcart-x)');
        (first ?? panel.current)?.focus();
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                closeRef.current();
                return;
            }
            // Tab stays inside the window.
            if (event.key === 'Tab' && panel.current) {
                const items = Array.from(panel.current.querySelectorAll<HTMLElement>('a[href], button:not(:disabled), input:not(:disabled), [tabindex="0"]'));
                if (items.length === 0) {
                    return;
                }
                const firstItem = items[0];
                const lastItem = items[items.length - 1];
                if (event.shiftKey && document.activeElement === firstItem) {
                    event.preventDefault();
                    lastItem.focus();
                } else if (!event.shiftKey && document.activeElement === lastItem) {
                    event.preventDefault();
                    firstItem.focus();
                }
            }
        };
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
            before?.focus?.();
        };
    }, []);

    return (
        <div className={`pcart-overlay${side ? ' is-side' : ''}`} onMouseDown={event => { if (event.target === event.currentTarget) onClose(); }}>
            <div ref={panel} className="pcart-sheet" role="dialog" aria-modal="true" aria-label={title} tabIndex={-1} data-testid={testId}>
                <header className="pcart-sheet-head">
                    <h2>{title}</h2>
                    <button type="button" className="pcart-x" onClick={onClose} aria-label={translate('catalog_cart', 'close')} title={translate('catalog_cart', 'close')}>
                        <X size={20} aria-hidden="true" />
                    </button>
                </header>
                <div className="pcart-sheet-body">{children}</div>
                {footer && <footer className="pcart-sheet-foot">{footer}</footer>}
            </div>
        </div>
    );
}
