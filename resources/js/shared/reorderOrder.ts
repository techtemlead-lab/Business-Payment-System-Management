import type { Row } from '../components/UI';
import { cartStoreFor, lineFromProduct } from './partnerCart';

/** One past order from the server, with today's prices: what can go in the cart and what was left out. */
export interface ReorderOption {
    number: string;
    items: { product: Row; quantity: number; price_changed: boolean }[];
    skipped: { name: string; reason: string }[];
}

const NOTES_KEY = 'madina.reorder-notes.v1';

/** Puts the order's products in the cart (replacing it, as "put in cart" on the order page does). Returns false when nothing could be added. */
export function fillCartFromReorder(userId: unknown, option: ReorderOption): boolean {
    const usable = option.items.filter(item => item.quantity > 0);
    if (usable.length === 0) {
        return false;
    }
    cartStoreFor(userId).set(usable.map(item => lineFromProduct(item.product, item.quantity)));

    return true;
}

/** Keeps the notices (skipped or re-priced products) for the order page, which shows them once. */
export function keepReorderNotes(notes: string[]): void {
    try {
        if (notes.length > 0) {
            window.sessionStorage.setItem(NOTES_KEY, JSON.stringify(notes));
        }
    } catch {
        // Storage switched off: the order page just shows no notice.
    }
}

export function takeReorderNotes(): string[] {
    try {
        const text = typeof window !== 'undefined' ? window.sessionStorage.getItem(NOTES_KEY) : null;
        if (!text) {
            return [];
        }
        window.sessionStorage.removeItem(NOTES_KEY);
        const data = JSON.parse(text);

        return Array.isArray(data) ? data.map(String) : [];
    } catch {
        return [];
    }
}
