import type { Row } from '../components/UI';

/** One card on a partner list: every size the partner may see, and the one it opens on. */
export interface SizeCard {
    key: string;
    sizes: Row[];
    first: Row;
}

/** Sizes of each group within this list, smallest first. Built only from what the viewer was sent. */
export function sizesByGroup(products: Row[]): Map<number, Row[]> {
    const groups = new Map<number, Row[]>();
    products.forEach(product => {
        if (product.size_group_id) {
            groups.set(product.size_group_id, [...(groups.get(product.size_group_id) ?? []), product]);
        }
    });
    groups.forEach(sizes => sizes.sort((first, second) => Number(first.size_position ?? 0) - Number(second.size_position ?? 0)));
    return groups;
}

/** A group with one size left visible is an ordinary product. */
function isSizeChoice(product: Row, groups: Map<number, Row[]>): boolean {
    return Boolean(product.size_group_id) && (groups.get(product.size_group_id)?.length ?? 0) > 1;
}

/** The name a product goes by when choosing: all sizes of a group share the first size's name. */
export function choiceName(product: Row, groups: Map<number, Row[]>): string {
    return isSizeChoice(product, groups) ? String(groups.get(product.size_group_id)?.[0]?.name ?? '') : String(product.name ?? '');
}

/** Cards for these matches. A group appears once, opening on the first matching size in stock. */
export function oneCardPerGroup(matches: Row[], groups: Map<number, Row[]>): SizeCard[] {
    const groupsDone = new Set<number>();
    const cards: SizeCard[] = [];
    matches.forEach(product => {
        if (!isSizeChoice(product, groups)) {
            cards.push({ key: `p${product.id}`, sizes: [product], first: product });
            return;
        }
        if (groupsDone.has(product.size_group_id)) {
            return;
        }
        groupsDone.add(product.size_group_id);
        const sizes = groups.get(product.size_group_id) ?? [product];
        const matching = sizes.filter(size => matches.includes(size));
        const first = matching.find(size => Number(size.stock ?? 0) > 0 && !size.is_stock_paused) ?? matching[0] ?? product;
        cards.push({ key: `g${product.size_group_id}`, sizes, first });
    });
    return cards;
}
