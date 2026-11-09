import { isBanglaDocument, localizeDigits } from '../business-date';

/** Bangla letters: the sentence is Bangla, so its numbers should be too. */
const BANGLA_LETTER = /[\u0980-\u09FF]/;

const LATIN_LETTER = /[A-Za-z]/;

/** Phone numbers and ID numbers (8 or more digits in a row), and masked or machine values, stay as they are. */
const NOT_A_FIGURE = /\d{8,}|[*@_]/;

/** The word before ends with a Latin letter, as in "Lobon 30 Tablets": the number belongs to that name. */
const ENDS_LATIN = /[A-Za-z][)\]]?$/;

const STARTS_LATIN = /^[([]?[A-Za-z]/;

/**
 * Text the user types, copies as code, or that must stay machine-readable.
 * An option's words can be shaped when it has its own value, because the value is what gets sent.
 */
const SKIP = 'input, textarea, option:not([value]), code, pre, kbd, samp, script, style, [contenteditable], [data-keep-digits]';

/**
 * Shows the numbers in one piece of text in Bangla digits. A number written against Latin
 * letters is part of a code or a stored name ("MNHC-100", "Ajmer Lobon 30 Tablets"), so it is
 * left alone. English text is untouched.
 */
function shapeWords(text: string): string {
    if (LATIN_LETTER.test(text) && !BANGLA_LETTER.test(text)) {
        return text;
    }
    const parts = text.split(/(\s+)/);
    const word = (index: number): string => parts[index] ?? '';

    return parts.map((part, index) => {
        const isFigure = /\d/.test(part) && !LATIN_LETTER.test(part) && !NOT_A_FIGURE.test(part);
        if (!isFigure || ENDS_LATIN.test(word(index - 2)) || STARTS_LATIN.test(word(index + 2))) {
            return part;
        }

        return localizeDigits(part);
    }).join('');
}

function shape(node: Text): void {
    const raw = node.nodeValue ?? '';
    if (!/\d/.test(raw)) {
        return;
    }
    const parent = node.parentElement;
    if (!parent || parent.closest(SKIP)) {
        return;
    }
    const shaped = shapeWords(raw);
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
 * Safety net for Bangla pages: components format numbers with number()/money(), but
 * numbers also arrive inside sentences written elsewhere (server refusals, validation
 * messages, saved notices), and those never pass through a formatter. This is the one
 * place that shows them in Bangla digits, so a Bangla page never mixes "3" and "৩".
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
