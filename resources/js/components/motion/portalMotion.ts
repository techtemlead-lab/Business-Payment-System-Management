import { router } from '@inertiajs/react';

/**
 * Motion helpers for the logged-in portals. Styles live in
 * resources/css/portal-motion.css; this file only feeds them:
 *
 * - Pointer glow and tilt: ONE shared pointermove listener (rAF-throttled)
 *   writes the pointer position into CSS variables on the card under the
 *   pointer. Mouse / trackpad only; never on touch screens.
 * - Page transition: when a visit opens another section, the page body
 *   fades and rises in.
 * - Sidebar marker: the "you are here" bar slides from the old menu item to
 *   the new one.
 *
 * Nothing here causes a React render, and nothing runs for people who ask
 * for reduced motion.
 */

/** Cards that get the pointer glow (and lift, in CSS). */
const GLOW_CARDS = '.metric, .treasury-kpi-card, .admin-kpi, .ph-today-grid > div, .office-today-figures a, .office-today-lead, .office-tile';

/** The KPI cards among them that also tilt in 3D. */
const TILT_CARDS = '.metric, .treasury-kpi-card, .admin-kpi, .ph-today-grid > div';

/** Largest tilt in degrees, measured along the pointer direction. */
const MAX_TILT_DEG = 4;

const SELECTED_NAV = '.app-shell .sidebar a.selected';

function prefersReducedMotion(): boolean {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? true;
}

function installPointerEffects(): () => void {
    const finePointer = window.matchMedia?.('(hover: hover) and (pointer: fine)');
    if (!finePointer || typeof requestAnimationFrame !== 'function') {
        return () => {};
    }
    let frame = 0;
    let pointerX = 0;
    let pointerY = 0;
    let target: Element | null = null;
    let current: HTMLElement | null = null;

    const update = () => {
        frame = 0;
        const card = target?.closest<HTMLElement>(GLOW_CARDS) ?? null;
        if (current && current !== card) {
            current.style.removeProperty('--pm-tx');
            current.style.removeProperty('--pm-ty');
        }
        current = card && card.closest('.app-shell') ? card : null;
        if (!current) {
            return;
        }
        const box = current.getBoundingClientRect();
        if (box.width === 0 || box.height === 0) {
            return;
        }
        const x = pointerX - box.left;
        const y = pointerY - box.top;
        current.style.setProperty('--pm-x', `${Math.round(x)}px`);
        current.style.setProperty('--pm-y', `${Math.round(y)}px`);
        if (!current.matches(TILT_CARDS)) {
            return;
        }
        // -0.5 .. 0.5 from the card centre, then scaled so the combined tilt never passes MAX_TILT_DEG.
        const offsetX = Math.max(-0.5, Math.min(0.5, x / box.width - 0.5));
        const offsetY = Math.max(-0.5, Math.min(0.5, y / box.height - 0.5));
        const length = Math.hypot(offsetX, offsetY) * 2;
        const scale = length > 1 ? 1 / length : 1;
        current.style.setProperty('--pm-tx', `${(offsetX * 2 * MAX_TILT_DEG * scale).toFixed(2)}deg`);
        current.style.setProperty('--pm-ty', `${(-offsetY * 2 * MAX_TILT_DEG * scale).toFixed(2)}deg`);
    };

    const onPointerMove = (event: PointerEvent) => {
        if (event.pointerType !== 'mouse' && event.pointerType !== 'pen') {
            return;
        }
        if (!finePointer.matches || prefersReducedMotion()) {
            return;
        }
        pointerX = event.clientX;
        pointerY = event.clientY;
        target = event.target instanceof Element ? event.target : null;
        if (frame === 0) {
            frame = requestAnimationFrame(update);
        }
    };

    document.addEventListener('pointermove', onPointerMove, { passive: true });

    return () => {
        document.removeEventListener('pointermove', onPointerMove);
        if (frame !== 0) {
            cancelAnimationFrame(frame);
        }
    };
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
    const stopPointer = installPointerEffects();
    const stopPages = installPageTransitions();

    return () => {
        stopPointer();
        stopPages();
    };
}
