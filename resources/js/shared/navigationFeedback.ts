import { router } from '@inertiajs/react';

/** Wait this long before dimming, so quick visits never flicker. */
const DIM_AFTER_MS = 180;

/**
 * While a page visit is on its way (opening another section, a filter, the
 * next page of a list) the current page dims a little, so a tap always gets a
 * visible answer even on a slow phone connection. Background refreshes,
 * prefetches and partial reloads are left alone.
 */
export function installNavigationFeedback(): () => void {
    const root = document.documentElement;
    let timer = 0;
    const clear = () => {
        window.clearTimeout(timer);
        timer = 0;
        root.classList.remove('portal-navigating');
    };
    const offStart = router.on('start', (event) => {
        const visit = event.detail.visit;
        if (visit.method !== 'get' || visit.prefetch || visit.async || visit.only.length > 0 || !visit.showProgress) {
            return;
        }
        clear();
        timer = window.setTimeout(() => root.classList.add('portal-navigating'), DIM_AFTER_MS);
    });
    const offFinish = router.on('finish', clear);

    return () => {
        clear();
        offStart();
        offFinish();
    };
}
