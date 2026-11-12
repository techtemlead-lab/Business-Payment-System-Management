/**
 * Matching for the search box inside a drop-down. A person may type Bangla or English, in any
 * letter case, with Bangla or English digits, and several words in any order.
 */

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Lower case, English digits, single spaces: the form both the list and the typed words are compared in. */
export function normalizeSearch(text: string): string {
    return String(text ?? '')
        .normalize('NFC')
        .toLowerCase()
        .replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit)))
        .replace(/\s+/g, ' ')
        .trim();
}

/** True when every typed word appears somewhere in the text. Nothing typed matches everything. */
export function matchesSearch(text: string, query: string): boolean {
    const words = normalizeSearch(query).split(' ').filter(Boolean);
    if (words.length === 0) {
        return true;
    }
    const haystack = normalizeSearch(text);

    return words.every((word) => haystack.includes(word));
}
