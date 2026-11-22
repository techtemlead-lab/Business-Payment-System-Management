import { useEffect, useState } from 'react';

/** The phone breakpoint every mobile-only stylesheet in the partner portal uses. */
const PHONE_QUERY = '(max-width: 767px)';

/**
 * True on a phone-width screen. Mobile-only controls (section toggles, the sticky order bar) render only
 * when it is true, so a desktop page keeps exactly the markup it had before.
 */
export function useIsPhone(): boolean {
    const [phone, setPhone] = useState<boolean>(() => typeof window !== 'undefined' && Boolean(window.matchMedia?.(PHONE_QUERY).matches));

    useEffect(() => {
        const query = window.matchMedia?.(PHONE_QUERY);
        if (!query) {
            return undefined;
        }
        const update = () => setPhone(query.matches);
        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return phone;
}
