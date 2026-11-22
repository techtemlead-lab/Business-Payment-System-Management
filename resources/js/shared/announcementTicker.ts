/**
 * Home page news line (the moving strip under the banners).
 *
 * The strip shows the text twice side by side and slides the pair left by exactly one copy, so the
 * whole line passes through and then repeats without a jump, at any screen width. The only thing the
 * browser cannot work out in CSS is the time one pass should take; it is set here from the real
 * width of one copy so the speed stays the same for a short or a long text.
 */
export const TICKER_SPEED_PX_PER_SECOND = 45;
const TICKER_MIN_SECONDS = 4;

/** Seconds one full pass takes when a copy is `copyWidthPx` wide; NaN when the width is not usable. */
export function tickerSeconds(copyWidthPx: number, speedPxPerSecond: number = TICKER_SPEED_PX_PER_SECOND): number {
    if (!Number.isFinite(copyWidthPx) || copyWidthPx <= 0 || !Number.isFinite(speedPxPerSecond) || speedPxPerSecond <= 0) {
        return Number.NaN;
    }

    return Math.max(TICKER_MIN_SECONDS, Math.round((copyWidthPx / speedPxPerSecond) * 10) / 10);
}

export function initAnnouncementTickers(root: ParentNode = document): void {
    root.querySelectorAll<HTMLElement>('[data-ref-ticker]').forEach((track) => {
        const item = track.querySelector<HTMLElement>('.ref-announcement-item');
        if (!item) {
            return;
        }

        let lastWidth = 0;
        const fit = (): void => {
            const width = item.getBoundingClientRect().width;
            // Fonts loading or a rotated phone change the width; a height-only change (the phone's
            // address bar sliding away) must not restart the speed calculation.
            if (Math.abs(width - lastWidth) < 1) {
                return;
            }
            const seconds = tickerSeconds(width);
            if (Number.isNaN(seconds)) {
                return;
            }
            lastWidth = width;
            track.style.setProperty('--ref-ticker-seconds', `${seconds}s`);
        };

        fit();
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(fit).observe(item);
        }

        // Touch and hold stops the line so it can be read; mouse users get the same from CSS hover.
        const strip = track.closest<HTMLElement>('.ref-announcement') ?? track;
        const hold = (event: PointerEvent): void => {
            if (event.pointerType !== 'mouse') {
                track.classList.add('is-paused');
            }
        };
        const release = (): void => track.classList.remove('is-paused');
        strip.addEventListener('pointerdown', hold);
        strip.addEventListener('pointerup', release);
        strip.addEventListener('pointercancel', release);
        strip.addEventListener('pointerleave', release);
    });
}
