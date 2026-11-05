import { isBanglaDocument, localizeDigits } from '../business-date';

/**
 * A text node that is only a number: a count, amount or percent ("3", "1,250",
 * "40.00%", "-5"). Anything with letters — order numbers, SKUs, codes — never
 * matches, and 8+ digit runs (phone numbers, IDs) are left alone.
 */
const PURE_NUMBER = /^[-+]?\d{1,7}(?:[,.]\d+)*%?$/;

/** Text the user types, copies as code, or that must stay machine-readable. */
const SKIP = 'input, textarea, select, option, code, pre, kbd, samp, script, style, [contenteditable], [data-keep-digits]';

function shape(node: Text): void {
    const raw = node.nodeValue ?? '';
    const text = raw.trim();
    if (text === '' || !PURE_NUMBER.test(text)) {
        return;
    }
    const parent = node.parentElement;
    if (!parent || parent.closest(SKIP)) {
        return;
    }
    const shaped = localizeDigits(raw);
    if (shaped !== raw) {
        node.nodeValue = shaped;
    }
}

function shapeTree(root: Node): void {
    if (root.nodeType === Node.TEXT_NODE) {
        shape(root as Text);

        return;
    }
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        shape(node as Text);
    }
}

/**
 * Safety net for Bangla pages: components should format numbers with
 * number()/money(), but a bare `{count}` still slips through now and then
 * (sidebar badges, figure tiles, table cells). This shows any text that is
 * purely a number in Bangla digits so a Bangla page never mixes "3" and "৩".
 */
export function installBanglaDigitShaping(root: HTMLElement = document.body): () => void {
    // Always observe: the page language can change without a full reload.
    // Each pass checks it, so English pages are never touched.
    const run = (work: () => void) => {
        if (isBanglaDocument()) {
            work();
        }
    };
    run(() => shapeTree(root));
    const observer = new MutationObserver((mutations) => run(() => {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData') {
                shape(mutation.target as Text);
            } else {
                mutation.addedNodes.forEach(shapeTree);
            }
        }
    }));
    observer.observe(root, { childList: true, subtree: true, characterData: true });
    const html = new MutationObserver(() => run(() => shapeTree(root)));
    html.observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });

    return () => {
        observer.disconnect();
        html.disconnect();
    };
}
