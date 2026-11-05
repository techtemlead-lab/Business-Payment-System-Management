/**
 * Suggested product SKUs. The server still enforces uniqueness; this only makes
 * a clash very unlikely (36^4 ≈ 1.7 million codes per month and prefix) and
 * keeps codes readable for stock staff, e.g. "MNHC-2611-7KQ2".
 */
const ALPHABET = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';

function randomPart(length: number): string {
    const values = new Uint32Array(length);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(values);
    } else {
        for (let index = 0; index < length; index++) {
            values[index] = Math.floor(Math.random() * 0xffffffff);
        }
    }

    return Array.from(values, (value) => ALPHABET[value % ALPHABET.length]).join('');
}

function monthCode(date = new Date()): string {
    return `${String(date.getFullYear()).slice(-2)}${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** Clean a free-text prefix to at most `max` upper-case letters/digits. */
export function skuPrefix(text: string | null | undefined, fallback: string, max = 4): string {
    const cleaned = String(text ?? '').replace(/[^A-Za-z0-9]/g, '').slice(0, max).toUpperCase();

    return cleaned || fallback;
}

export function suggestSku(prefix = 'MNHC'): string {
    return `${prefix}-${monthCode()}-${randomPart(4)}`;
}
