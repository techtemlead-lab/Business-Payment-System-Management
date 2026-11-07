/**
 * Dashboard figures count up from zero the first time they appear on screen
 * ("৳ 1,400.00", "12", "৪৫"). It only touches the summary figures listed in
 * FIGURES, only text that is purely a number or a taka amount, and only figures
 * already on screen (so nothing jumps back to zero while you scroll). The exact
 * text React rendered is always restored at the end, and if React changes the
 * text meanwhile the animation simply stops. Nothing moves for people who ask
 * for reduced motion.
 */

const FIGURES = [
    '.office-today-lead strong',
    '.office-today-figures strong',
    '.office-today-totals dd',
    '.admin-sales-trend strong',
    '.ph-hero-stats dd',
    '.ph-today-grid strong',
    '.metric > strong',
    '.treasury-kpi-amount',
    '.ops-figure',
    '.ed-stat strong',
    '.admin-work-queue strong',
].join(', ');

const BANGLA_ZERO = 0x09e6;
const AMOUNT = /^(৳[\s ]?)?(-?)([\d০-৯]{1,3}(?:,[\d০-৯]{2,3})*|[\d০-৯]+)(?:\.([\d০-৯]+))?(%?)$/;
const DURATION_MS = 850;
const MAX_PER_PASS = 40;

const started = new WeakSet<Text>();

function toLatin(text: string): string {
    return text.replace(/[০-৯]/g, (digit) => String(digit.charCodeAt(0) - BANGLA_ZERO));
}

function toBangla(text: string): string {
    return text.replace(/\d/g, (digit) => String.fromCharCode(BANGLA_ZERO + Number(digit)));
}

type Figure = { node: Text; final: string; render: (value: number) => string; target: number };

function readFigure(node: Text): Figure | null {
    const final = node.nodeValue ?? '';
    const match = AMOUNT.exec(final.trim());
    if (!match) {
        return null;
    }
    const [, prefix = '', sign, whole, fraction = '', suffix] = match;
    const target = Number(toLatin(whole).replace(/,/g, '') + (fraction ? '.' + toLatin(fraction) : ''));
    if (!Number.isFinite(target) || target <= 0) {
        return null;
    }
    const bangla = /[০-৯]/.test(whole + fraction);
    const grouped = whole.includes(',');
    const format = new Intl.NumberFormat('en-IN', {
        minimumFractionDigits: fraction.length,
        maximumFractionDigits: fraction.length,
        useGrouping: grouped,
    });
    const lead = final.slice(0, final.indexOf(final.trim()));
    const tail = final.slice(lead.length + final.trim().length);
    const render = (value: number): string => {
        const digits = format.format(value);

        return lead + prefix + sign + (bangla ? toBangla(digits) : digits) + suffix + tail;
    };

    return { node, final, render, target };
}

function isOnScreen(element: Element): boolean {
    const box = element.getBoundingClientRect();

    return box.width > 0 && box.bottom > 0 && box.top < window.innerHeight;
}

function animate(figures: Figure[]): void {
    if (figures.length === 0) {
        return;
    }
    const start = performance.now();
    const written = new Map<Text, string>();
    figures.forEach((figure) => {
        const first = figure.render(0);
        figure.node.nodeValue = first;
        written.set(figure.node, first);
    });
    const step = (now: number) => {
        const progress = Math.min(1, (now - start) / DURATION_MS);
        const eased = 1 - Math.pow(1 - progress, 3);
        let running = false;
        figures.forEach((figure) => {
            if (!written.has(figure.node)) {
                return;
            }
            if (figure.node.nodeValue !== written.get(figure.node)) {
                // React rendered something new: leave it alone.
                written.delete(figure.node);

                return;
            }
            const text = progress >= 1 ? figure.final : figure.render(figure.target * eased);
            figure.node.nodeValue = text;
            written.set(figure.node, text);
            running = running || progress < 1;
        });
        if (running) {
            requestAnimationFrame(step);
        }
    };
    requestAnimationFrame(step);
}

function scan(root: ParentNode): void {
    const figures: Figure[] = [];
    const elements = root instanceof Element && root.matches(FIGURES) ? [root] : [];
    elements.push(...Array.from(root.querySelectorAll(FIGURES)));
    for (const element of elements) {
        if (figures.length >= MAX_PER_PASS) {
            break;
        }
        const only = element.childNodes.length === 1 ? element.firstChild : null;
        if (!(only instanceof Text) || started.has(only) || element.closest('[data-keep-digits]')) {
            continue;
        }
        started.add(only);
        if (!isOnScreen(element)) {
            continue;
        }
        const figure = readFigure(only);
        if (figure) {
            figures.push(figure);
        }
    }
    animate(figures);
}

export function installCountUp(root: HTMLElement = document.body): () => void {
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    if (!reduced || reduced.matches || typeof requestAnimationFrame !== 'function') {
        return () => {};
    }
    let queued: ParentNode[] = [];
    let frame = 0;
    const flush = () => {
        frame = 0;
        const batch = queued;
        queued = [];
        batch.forEach(scan);
    };
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    queued.push(node);
                }
            });
        }
        if (queued.length > 0 && frame === 0) {
            // Scan after React has laid the new section out, before it is painted.
            frame = requestAnimationFrame(flush);
        }
    });
    observer.observe(root, { childList: true, subtree: true });
    queued.push(root);
    frame = requestAnimationFrame(flush);

    return () => {
        observer.disconnect();
        if (frame !== 0) {
            cancelAnimationFrame(frame);
        }
    };
}
