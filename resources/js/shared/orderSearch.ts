import type { Row } from '../components/UI';
import { matchesSearch, normalizeSearch } from './search-match.ts';
import { choiceName, oneCardPerGroup, type SizeCard } from './productSizes.ts';

/** Whether a product can be put on an order right now. */
export type StockState = 'in' | 'out' | 'paused';

export function stockState(product: Row): StockState {
    if (product.is_stock_paused) {
        return 'paused';
    }

    return Number(product.stock ?? 0) > 0 ? 'in' : 'out';
}

/** What the partner pays for one unit: his price, else the printed price. */
export function partnerPrice(product: Row): number {
    return Number(product.price || product.mrp || 0);
}

/** Filters the partner may switch on; both are optional and never required to order. */
export interface OrderFilters {
    category?: string;
    subCategory?: string;
}

/** Everything the search compares a typed word with: names, code, pack size, category. */
function haystack(product: Row, groups: Map<number, Row[]>): string {
    return [
        choiceName(product, groups),
        product.name,
        product.sku,
        product.size_label,
        product.strength,
        product.category,
        product.sub_category,
    ].filter(Boolean).join(' ');
}

/** Lower is better: a name that starts with the typed text comes before one that only contains it. */
function rank(product: Row, query: string, groups: Map<number, Row[]>): number {
    const typed = normalizeSearch(query);
    if (!typed) {
        return 3;
    }
    const names = [choiceName(product, groups), String(product.name ?? '')].map(normalizeSearch);
    if (names.some(name => name.startsWith(typed))) {
        return 0;
    }
    if (names.some(name => name.split(' ').some(word => word.startsWith(typed.split(' ')[0])))) {
        return 1;
    }

    return names.some(name => name.includes(typed)) ? 2 : 3;
}

/**
 * Products to show while ordering. One card per product (sizes of a group share a card), best
 * match first, then in-stock before out-of-stock, then by name. With nothing typed and no
 * filter nothing is listed; a filter alone lists that part of the range.
 */
export function searchProducts(
    products: Row[],
    query: string,
    groups: Map<number, Row[]>,
    filters: OrderFilters = {},
    limit = 30,
): SizeCard[] {
    const hasFilter = Boolean(filters.category || filters.subCategory);
    if (!normalizeSearch(query) && !hasFilter) {
        return [];
    }
    const matches = products
        .filter(product => !filters.category || product.category === filters.category)
        .filter(product => !filters.subCategory || product.sub_category === filters.subCategory)
        .filter(product => matchesSearch(haystack(product, groups), query))
        .map(product => ({ product, rank: rank(product, query, groups) }))
        .sort((first, second) => first.rank - second.rank
            || Number(stockState(second.product) === 'in') - Number(stockState(first.product) === 'in')
            || choiceName(first.product, groups).localeCompare(choiceName(second.product, groups))
            || Number(first.product.size_position ?? 0) - Number(second.product.size_position ?? 0))
        .map(entry => entry.product);

    return oneCardPerGroup(matches, groups).slice(0, limit);
}

/** Cheapest and dearest size of a card, for "from ... to ..." on a card with several sizes. */
export function priceRange(card: SizeCard): { low: number; high: number } {
    const prices = card.sizes.map(partnerPrice);

    return { low: Math.min(...prices), high: Math.max(...prices) };
}

/** A card is orderable when at least one of its sizes is in stock. */
export function cardStock(card: SizeCard): StockState {
    const states = card.sizes.map(stockState);
    if (states.includes('in')) {
        return 'in';
    }

    return states.every(state => state === 'paused') ? 'paused' : 'out';
}

/** Categories and sub-categories that have a product the partner may order, A to Z. */
export function filterChoices(products: Row[], category?: string): { categories: string[]; subCategories: string[] } {
    const categories = new Set<string>();
    const subCategories = new Set<string>();
    products.forEach(product => {
        if (product.category) {
            categories.add(String(product.category));
            if (category && product.category === category && product.sub_category) {
                subCategories.add(String(product.sub_category));
            }
        }
    });

    return {
        categories: [...categories].sort((first, second) => first.localeCompare(second)),
        subCategories: [...subCategories].sort((first, second) => first.localeCompare(second)),
    };
}
