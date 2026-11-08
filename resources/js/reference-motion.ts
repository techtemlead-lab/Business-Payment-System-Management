/**
 * Public site motion layer: drifting leaves, scroll parallax, card tilt, a soft cursor ring,
 * header glass on scroll and number count-ups.
 *
 * Rules kept here on purpose:
 * - one requestAnimationFrame loop for everything; it stops when nothing needs drawing,
 *   and the leaves pause while the tab is hidden;
 * - transform and opacity only, and DOM reads happen before writes in each frame;
 * - visitors who ask for reduced motion get none of this (counters show their final value);
 * - leaves, tilt and the cursor ring only run on wide screens with a fine pointer.
 */

const reducedMotionQuery = matchMedia('(prefers-reduced-motion: reduce)');
const finePointerQuery = matchMedia('(hover: hover) and (pointer: fine)');
const wideScreenQuery = matchMedia('(min-width: 768px)');

const prefersReducedMotion = (): boolean => reducedMotionQuery.matches;

type Leaf = {
    x: number;
    y: number;
    size: number;
    depth: number;
    angle: number;
    spin: number;
    speed: number;
    sway: number;
    phase: number;
    hue: number;
};

type FrameTask = (time: number) => boolean;

/** The single shared animation loop. A task returns true while it still needs frames. */
const frameTasks = new Set<FrameTask>();
let frameHandle = 0;

function runFrame(time: number): void {
    frameHandle = 0;
    frameTasks.forEach((task) => {
        if (!task(time)) {
            frameTasks.delete(task);
        }
    });
    if (frameTasks.size > 0 && !document.hidden) {
        frameHandle = requestAnimationFrame(runFrame);
    }
}

function scheduleTask(task: FrameTask): void {
    frameTasks.add(task);
    if (!frameHandle && !document.hidden) {
        frameHandle = requestAnimationFrame(runFrame);
    }
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && frameTasks.size > 0 && !frameHandle) {
        frameHandle = requestAnimationFrame(runFrame);
    }
});

/** Soft leaves drifting behind the page, drawn on one small canvas (desktop and tablet only). */
function initLeafCanvas(): void {
    const host = document.querySelector<HTMLElement>('.ref-ambient-canvas');
    if (!host || prefersReducedMotion() || host.querySelector('.ref-leaf-canvas')) {
        return;
    }
    if (!wideScreenQuery.matches) {
        wideScreenQuery.addEventListener('change', initLeafCanvas, { once: true });

        return;
    }
    const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection;
    if (connection?.saveData) {
        return;
    }

    const canvas = document.createElement('canvas');
    canvas.className = 'ref-leaf-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    host.appendChild(canvas);
    const context = canvas.getContext('2d');
    if (!context) {
        canvas.remove();

        return;
    }

    const isDark = (): boolean => document.documentElement.dataset.theme === 'dark';
    const pixelRatio = Math.min(window.devicePixelRatio || 1, 1.5);
    let width = 0;
    let height = 0;
    let leaves: Leaf[] = [];
    let lastDraw = 0;
    let scrollY = window.scrollY;

    const makeLeaf = (startAnywhere: boolean): Leaf => ({
        x: Math.random() * width,
        y: startAnywhere ? Math.random() * height : -30,
        size: 7 + Math.random() * 9,
        depth: 0.35 + Math.random() * 0.65,
        angle: Math.random() * Math.PI * 2,
        spin: (Math.random() - 0.5) * 0.012,
        speed: 0.12 + Math.random() * 0.22,
        sway: 14 + Math.random() * 26,
        phase: Math.random() * Math.PI * 2,
        hue: 125 + Math.random() * 35,
    });

    const resize = (): void => {
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = Math.round(width * pixelRatio);
        canvas.height = Math.round(height * pixelRatio);
        context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        const count = Math.round(Math.min(18, Math.max(8, width / 90)));
        leaves = Array.from({ length: count }, () => makeLeaf(true));
    };

    const drawLeaf = (leaf: Leaf, drawX: number, drawY: number, dark: boolean): void => {
        context.save();
        context.translate(drawX, drawY);
        context.rotate(leaf.angle);
        const alpha = (dark ? 0.16 : 0.13) * leaf.depth + 0.04;
        context.fillStyle = `hsla(${leaf.hue}, ${dark ? 45 : 55}%, ${dark ? 55 : 32}%, ${alpha})`;
        context.beginPath();
        context.moveTo(0, -leaf.size);
        context.quadraticCurveTo(leaf.size * 0.75, -leaf.size * 0.15, 0, leaf.size);
        context.quadraticCurveTo(-leaf.size * 0.75, -leaf.size * 0.15, 0, -leaf.size);
        context.fill();
        context.strokeStyle = `hsla(${leaf.hue}, 40%, ${dark ? 70 : 24}%, ${alpha * 0.9})`;
        context.lineWidth = 0.7;
        context.beginPath();
        context.moveTo(0, -leaf.size * 0.8);
        context.lineTo(0, leaf.size * 0.85);
        context.stroke();
        context.restore();
    };

    const draw: FrameTask = (time) => {
        if (!wideScreenQuery.matches) {
            lastDraw = 0;

            return false;
        }
        if (time - lastDraw < 33) {
            return true;
        }
        const step = lastDraw ? Math.min((time - lastDraw) / 16.7, 3) : 1;
        lastDraw = time;
        const dark = isDark();
        context.clearRect(0, 0, width, height);
        for (const leaf of leaves) {
            leaf.y += leaf.speed * leaf.depth * step;
            leaf.angle += leaf.spin * step;
            leaf.phase += 0.008 * step;
            const span = height + 60;
            if (leaf.y > span * 20) {
                leaf.y %= span;
            }
            const swayX = Math.sin(leaf.phase) * leaf.sway * leaf.depth;
            const shiftedY = leaf.y - scrollY * 0.12 * leaf.depth;
            const drawY = (((shiftedY % span) + span) % span) - 30;
            drawLeaf(leaf, leaf.x + swayX, drawY, dark);
        }

        return true;
    };

    resize();
    window.addEventListener('resize', resize, { passive: true });
    window.addEventListener('scroll', () => {
        scrollY = window.scrollY;
    }, { passive: true });
    document.addEventListener('visibilitychange', () => {
        lastDraw = 0;
    });
    wideScreenQuery.addEventListener('change', () => {
        if (wideScreenQuery.matches) {
            scheduleTask(draw);
        }
    });
    scheduleTask(draw);
}

/** Gentle parallax for the decorative glow layers while the page scrolls. */
function initParallax(): void {
    if (prefersReducedMotion()) {
        return;
    }
    const layers = [...document.querySelectorAll<HTMLElement>('.ref-ambient-orb, [data-parallax]')];
    if (!layers.length) {
        return;
    }
    let pending = false;
    const apply: FrameTask = () => {
        pending = false;
        const offset = window.scrollY;
        layers.forEach((layer, index) => {
            const factor = Number(layer.dataset.parallax ?? (0.04 + (index % 3) * 0.025));
            layer.style.translate = `0 ${(-offset * factor).toFixed(1)}px`;
        });

        return false;
    };
    window.addEventListener('scroll', () => {
        if (!pending) {
            pending = true;
            scheduleTask(apply);
        }
    }, { passive: true });
    scheduleTask(apply);
}

/** Header turns to frosted glass and the logo settles a little smaller once the page scrolls. */
function initHeaderGlass(): void {
    const chrome = document.querySelector<HTMLElement>('.ref-sticky-chrome');
    if (!chrome) {
        return;
    }
    const sentinel = document.createElement('div');
    sentinel.className = 'ref-scroll-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    document.body.prepend(sentinel);
    new IntersectionObserver(([entry]) => {
        chrome.classList.toggle('is-scrolled', !entry.isIntersecting);
    }).observe(sentinel);
}

/** 3D tilt that follows the pointer on product and category cards (fine pointers only). */
function initCardTilt(): void {
    if (prefersReducedMotion() || !finePointerQuery.matches) {
        return;
    }
    const selector = '.ref-product-card, .ref-category-grid > a, .ref-about-sections-grid > .ref-content-card, .ref-directory-grid > *, .ref-contact-grid > *, .ref-testimonial-card, .ref-video-card, .ref-trust-pillar-card, .ref-role-card';
    const maxTilt = 6;
    let activeCard: HTMLElement | null = null;
    let pointerX = 0;
    let pointerY = 0;
    let queued = false;

    const update: FrameTask = () => {
        queued = false;
        if (!activeCard) {
            return false;
        }
        const rect = activeCard.getBoundingClientRect();
        const relativeX = (pointerX - rect.left) / rect.width - 0.5;
        const relativeY = (pointerY - rect.top) / rect.height - 0.5;
        activeCard.style.setProperty('--ref-tilt-x', `${(-relativeY * maxTilt).toFixed(2)}deg`);
        activeCard.style.setProperty('--ref-tilt-y', `${(relativeX * maxTilt).toFixed(2)}deg`);
        activeCard.style.setProperty('--ref-glare-x', `${((relativeX + 0.5) * 100).toFixed(1)}%`);
        activeCard.style.setProperty('--ref-glare-y', `${((relativeY + 0.5) * 100).toFixed(1)}%`);

        return false;
    };

    const release = (card: HTMLElement): void => {
        card.classList.remove('is-tilting');
        card.style.setProperty('--ref-tilt-x', '0deg');
        card.style.setProperty('--ref-tilt-y', '0deg');
    };

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType !== 'mouse') {
            return;
        }
        const card = (event.target as Element | null)?.closest<HTMLElement>(selector) ?? null;
        if (card === activeCard) {
            return;
        }
        if (activeCard) {
            release(activeCard);
        }
        activeCard = card;
        card?.classList.add('ref-tilt', 'is-tilting');
    });

    document.addEventListener('pointermove', (event) => {
        if (!activeCard || event.pointerType !== 'mouse') {
            return;
        }
        pointerX = event.clientX;
        pointerY = event.clientY;
        if (!queued) {
            queued = true;
            scheduleTask(update);
        }
    }, { passive: true });

    document.addEventListener('pointerout', (event) => {
        if (!activeCard) {
            return;
        }
        const next = (event.relatedTarget as Element | null)?.closest<HTMLElement>(selector) ?? null;
        if (next !== activeCard) {
            release(activeCard);
            activeCard = null;
        }
    });
}

/** A small ring that softly follows the mouse and grows over things you can click. Decoration only. */
function initCursorRing(): void {
    if (prefersReducedMotion() || !finePointerQuery.matches) {
        return;
    }
    const ring = document.createElement('div');
    ring.className = 'ref-cursor-ring';
    ring.setAttribute('aria-hidden', 'true');
    document.body.appendChild(ring);

    const interactive = 'a, button, [role="button"], summary, label, select, input[type="submit"], input[type="checkbox"], input[type="radio"]';
    let targetX = -100;
    let targetY = -100;
    let ringX = -100;
    let ringY = -100;
    let running = false;

    const follow: FrameTask = () => {
        ringX += (targetX - ringX) * 0.2;
        ringY += (targetY - ringY) * 0.2;
        ring.style.transform = `translate3d(${ringX.toFixed(1)}px, ${ringY.toFixed(1)}px, 0)`;
        const settled = Math.abs(targetX - ringX) < 0.3 && Math.abs(targetY - ringY) < 0.3;
        running = !settled;

        return running;
    };

    document.addEventListener('pointermove', (event) => {
        if (event.pointerType !== 'mouse') {
            ring.classList.remove('is-visible');

            return;
        }
        targetX = event.clientX;
        targetY = event.clientY;
        if (!ring.classList.contains('is-visible')) {
            ringX = targetX;
            ringY = targetY;
            ring.classList.add('is-visible');
        }
        if (!running) {
            running = true;
            scheduleTask(follow);
        }
    }, { passive: true });

    document.addEventListener('pointerover', (event) => {
        const overInteractive = Boolean((event.target as Element | null)?.closest(interactive));
        ring.classList.toggle('is-active', overInteractive);
    });

    document.addEventListener('pointerdown', () => ring.classList.add('is-pressed'));
    document.addEventListener('pointerup', () => ring.classList.remove('is-pressed'));
    document.documentElement.addEventListener('pointerleave', () => ring.classList.remove('is-visible'));
}

/** Western and Bangla digits, so counters keep the digit style the page was written in. */
const banglaDigits = '০১২৩৪৫৬৭৮৯';

function toWesternDigits(text: string): string {
    return text.replace(/[০-৯]/g, (digit) => String(banglaDigits.indexOf(digit)));
}

function toDigitStyle(value: number, useBangla: boolean): string {
    const text = String(value);

    return useBangla ? text.replace(/[0-9]/g, (digit) => banglaDigits[Number(digit)]) : text;
}

/** Plain whole numbers count up once when they come into view. */
function initCounters(): void {
    const candidates = [...document.querySelectorAll<HTMLElement>('[data-count-up], .ref-tab-count, .ref-trust-pill strong, .ref-stat-value')];
    const counters = candidates.filter((node) => node.children.length === 0 && /^[0-9০-৯]{1,6}$/.test((node.textContent ?? '').trim()));
    if (!counters.length || prefersReducedMotion() || !('IntersectionObserver' in window)) {
        return;
    }

    const animate = (node: HTMLElement): void => {
        const original = (node.textContent ?? '').trim();
        const useBangla = /[০-৯]/.test(original);
        const target = Number(toWesternDigits(original));
        if (!Number.isFinite(target) || target < 2) {
            return;
        }
        node.classList.add('ref-counting');
        const duration = 900;
        let start = 0;
        const tick: FrameTask = (time) => {
            if (!start) {
                start = time;
            }
            const progress = Math.min((time - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            node.textContent = progress < 1 ? toDigitStyle(Math.round(target * eased), useBangla) : original;

            return progress < 1;
        };
        scheduleTask(tick);
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                observer.unobserve(entry.target);
                animate(entry.target as HTMLElement);
            }
        });
    }, { threshold: 0.6 });
    counters.forEach((node) => observer.observe(node));
}

/**
 * Tag more grids for the staggered card reveal that reference-home.ts already runs.
 * This module is imported first, so the tags are in place before that code reads them.
 */
function tagRevealGroups(): void {
    const groups = ['.ref-product-grid', '.ref-testimonials-grid:not(.is-marquee)', '.ref-video-gallery-grid', '.ref-product-highlights-grid', '.ref-trust-pillars-inner', '.ref-role-selector'];
    groups.forEach((selector) => {
        document.querySelectorAll<HTMLElement>(selector).forEach((group) => {
            [...group.children].forEach((child, index) => {
                if (!(child instanceof HTMLElement) || child.classList.contains('ref-reveal-card')) {
                    return;
                }
                child.style.setProperty('--reveal-index', String(index % 12));
                child.classList.add('ref-reveal-card');
            });
        });
    });
    document.querySelectorAll<HTMLElement>('.ref-md-message, .ref-testimonials-section, .ref-video-gallery-section, .ref-trust-pillars, .ref-legal-document, .ref-feedback-form, .ref-about-objectives').forEach((section) => {
        if (!section.hasAttribute('data-reveal')) {
            section.setAttribute('data-reveal', '');
        }
    });
}

/** Ripple from the click point on primary buttons (the sheen on hover is CSS only). */
function initButtonRipple(): void {
    if (prefersReducedMotion()) {
        return;
    }
    const selector = '.ref-primary-button, .ref-buy-now, .ref-add-cart, .ref-dealer-banner-btn, .ref-partner-submit-btn, .ref-location-btn';
    document.addEventListener('pointerdown', (event) => {
        const button = (event.target as Element | null)?.closest<HTMLElement>(selector);
        if (!button) {
            return;
        }
        const rect = button.getBoundingClientRect();
        const ripple = document.createElement('span');
        ripple.className = 'ref-ripple';
        ripple.setAttribute('aria-hidden', 'true');
        const size = Math.max(rect.width, rect.height) * 2;
        ripple.style.width = `${size}px`;
        ripple.style.height = `${size}px`;
        ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
        ripple.style.top = `${event.clientY - rect.top - size / 2}px`;
        button.classList.add('ref-has-ripple');
        button.appendChild(ripple);
        ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
    });
}

tagRevealGroups();
document.documentElement.classList.add('ref-motion-ready');
initHeaderGlass();
initLeafCanvas();
initParallax();
initCardTilt();
initCursorRing();
initButtonRipple();
initCounters();
