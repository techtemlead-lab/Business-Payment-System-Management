import { useEffect, type RefObject } from 'react';

const PHONE_QUERY = '(max-width: 767px)';

/**
 * Phones: keeps a bottom sheet above the on-screen keyboard. The keyboard's height is written to the
 * sheet as `--sheet-keyboard` (the CSS lifts the sheet by it), and a box that gets focus is scrolled
 * into view inside the sheet once the keyboard has opened. Computers are left alone.
 */
export function useKeyboardSafeSheet(ref: RefObject<HTMLElement | null>): void {
    useEffect(() => {
        const sheet = ref.current;
        if (!sheet || typeof window === 'undefined') {
            return undefined;
        }
        const viewport = window.visualViewport;
        const onPhone = () => window.matchMedia?.(PHONE_QUERY).matches ?? false;
        const update = () => {
            const covered = viewport && onPhone() ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop) : 0;
            sheet.style.setProperty('--sheet-keyboard', `${Math.round(covered)}px`);
        };
        let timer: number | undefined;
        const onFocus = (event: FocusEvent) => {
            const target = event.target;
            if (!(target instanceof HTMLElement) || !onPhone() || !target.matches('input, select, textarea')) {
                return;
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(() => target.scrollIntoView({ block: 'nearest' }), 280);
        };

        update();
        viewport?.addEventListener('resize', update);
        viewport?.addEventListener('scroll', update);
        sheet.addEventListener('focusin', onFocus);

        return () => {
            window.clearTimeout(timer);
            viewport?.removeEventListener('resize', update);
            viewport?.removeEventListener('scroll', update);
            sheet.removeEventListener('focusin', onFocus);
        };
    }, [ref]);
}
