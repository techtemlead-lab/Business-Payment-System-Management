import { useEffect, type RefObject } from 'react';

const PHONE = '(max-width: 767px)';

type Options = {
    /** Which panels become sections. Default: every `.panel` that sits directly in the container or in a plain div inside it. */
    selector?: string;
    /** The heading of a section: the element that opens and closes it. */
    head?: string;
    /** How many sections start open (the first ones). The rest start closed. */
    open?: number;
    /** Re-attach when this changes (for sections that appear later). */
    watch?: unknown;
};

/**
 * Long office forms on a phone: every panel with a heading becomes a tap-to-open section, so a 9,000 px form
 * reads as a short list of titles. Wider screens are never touched. The fields of a closed section stay in the
 * page, and a closed section that holds an invalid field opens itself so the browser can show the message.
 * Styles: `.msec`, `.msec-head`, `.msec-closed` in mobile-office.css.
 */
export function useMobileSections(container: RefObject<HTMLElement | null>, options: Options = {}): void {
    const { selector = ':scope > .panel, :scope > div > .panel', head: headSelector = ':scope > h2, :scope > .panel-heading', open = 1, watch } = options;

    useEffect(() => {
        const root = container.current;
        if (!root || typeof window === 'undefined' || !window.matchMedia) {
            return undefined;
        }
        const media = window.matchMedia(PHONE);
        let cleanups: Array<() => void> = [];

        const detach = () => {
            cleanups.forEach((undo) => undo());
            cleanups = [];
        };

        const toggle = (section: HTMLElement, head: HTMLElement, force?: boolean) => {
            const closed = force ?? !section.classList.contains('msec-closed');
            section.classList.toggle('msec-closed', closed);
            head.setAttribute('aria-expanded', String(!closed));
        };

        const attach = () => {
            detach();
            if (!media.matches) {
                return;
            }
            const sections = Array.from(root.querySelectorAll<HTMLElement>(selector)).filter((section) => section.dataset.msec !== 'off');
            sections.forEach((section, index) => {
                const head = section.querySelector<HTMLElement>(headSelector);
                if (!head) {
                    return;
                }
                section.classList.add('msec');
                head.classList.add('msec-head');
                head.setAttribute('role', 'button');
                head.tabIndex = 0;
                toggle(section, head, index >= open);
                const onClick = (event: Event) => {
                    // A link or button inside the heading keeps its own job.
                    if ((event.target as HTMLElement).closest('a,button,input,select')) {
                        return;
                    }
                    toggle(section, head);
                };
                const onKey = (event: KeyboardEvent) => {
                    if (event.target === head && (event.key === 'Enter' || event.key === ' ')) {
                        event.preventDefault();
                        toggle(section, head);
                    }
                };
                head.addEventListener('click', onClick);
                head.addEventListener('keydown', onKey);
                cleanups.push(() => {
                    head.removeEventListener('click', onClick);
                    head.removeEventListener('keydown', onKey);
                    section.classList.remove('msec', 'msec-closed');
                    head.classList.remove('msec-head');
                    head.removeAttribute('role');
                    head.removeAttribute('aria-expanded');
                    head.removeAttribute('tabindex');
                });
            });
            const onInvalid = (event: Event) => {
                const section = (event.target as HTMLElement).closest<HTMLElement>('.msec-closed');
                const head = section?.querySelector<HTMLElement>(':scope > .msec-head');
                if (section && head) {
                    toggle(section, head, false);
                }
            };
            root.addEventListener('invalid', onInvalid, true);
            cleanups.push(() => root.removeEventListener('invalid', onInvalid, true));
        };

        attach();
        media.addEventListener('change', attach);

        return () => {
            media.removeEventListener('change', attach);
            detach();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [container, selector, headSelector, open, watch]);
}
