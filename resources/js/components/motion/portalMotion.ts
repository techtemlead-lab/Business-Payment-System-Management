import { router } from '@inertiajs/react';

/**
 * Motion helpers for the logged-in portals. Styles live in
 * resources/css/portal-motion.css; this file only feeds them:
 *
 * - Page transition: when a visit opens another section, the page body
 *   fades and rises in.
 * - Sidebar marker: the "you are here" bar slides from the old menu item to
 *   the new one.
 *
 * Nothing here causes a React render, and nothing runs for people who ask
 * for reduced motion.
 */

const SELECTED_NAV = '.app-shell .sidebar a.selected';

function prefersReducedMotion(): boolean {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? true;
}

const EASE_OUT = 'cubic-bezier(.16,1,.3,1)';

/** True when the element is already running its own (CSS) entrance animation. */
function isAnimating(element: Element): boolean {
    return element.getAnimations?.().some((animation) => animation.playState === 'running') ?? false;
}

function installPageTransitions(): () => void {
    let lastPath = window.location.pathname;
    let markerTop: number | null = null;

    const offStart = router.on('start', (event) => {
        const visit = event.detail.visit;
        if (visit.method !== 'get' || visit.prefetch || visit.async || visit.only.length > 0) {
            markerTop = null;

            return;
        }
        const marker = document.querySelector<HTMLElement>(SELECTED_NAV);
        const box = marker?.getBoundingClientRect();
        markerTop = box && box.height > 0 ? box.top : null;
    });

    const offNavigate = router.on('navigate', () => {
        const path = window.location.pathname;
        const changedSection = path !== lastPath;
        lastPath = path;
        const fromTop = markerTop;
        markerTop = null;
        if (!changedSection || prefersReducedMotion() || typeof requestAnimationFrame !== 'function') {
            return;
        }
        // Wait one frame so React has put the new page on screen.
        requestAnimationFrame(() => {
            const body = document.getElementById('domain-panel');
            if (body && !isAnimating(body)) {
                body.animate(
                    [
                        { opacity: 0, transform: 'translateY(6px)' },
                        { opacity: 1, transform: 'none' },
                    ],
                    { duration: 260, easing: EASE_OUT },
                );
            }
            slideSidebarMarker(fromTop);
        });
    });

    return () => {
        offStart();
        offNavigate();
    };
}

/** Moves the new item's marker bar from where the old one was, so it reads as one bar sliding. */
function slideSidebarMarker(fromTop: number | null): void {
    if (fromTop === null) {
        return;
    }
    const marker = document.querySelector<HTMLElement>(SELECTED_NAV);
    const box = marker?.getBoundingClientRect();
    if (!marker || !box || box.height === 0) {
        return;
    }
    const distance = fromTop - box.top;
    if (Math.abs(distance) < 2 || Math.abs(distance) > window.innerHeight) {
        return;
    }
    try {
        marker.animate(
            [
                { transform: `translateY(${distance}px)`, opacity: 0.85 },
                { transform: 'none', opacity: 1 },
            ],
            { duration: 320, easing: EASE_OUT, pseudoElement: '::before' },
        );
    } catch {
        // Older browsers without pseudo-element animation simply show the marker in place.
    }
}

/** Starts every portal motion helper once for the whole app. */
export function installPortalMotion(): () => void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return () => {};
    }
    return installPageTransitions();
}
