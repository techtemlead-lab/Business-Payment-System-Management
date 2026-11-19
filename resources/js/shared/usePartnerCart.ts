import { useCallback, useMemo, useRef, useSyncExternalStore } from 'react';
import { usePage } from '@inertiajs/react';
import type { Row } from '../components/UI';
import { cartStoreFor, linesFromItems, orderLines, type CartLine } from './partnerCart';

/** The signed-in partner's cart lines, shared by every component on every page. */
export function usePartnerCartLines(): { lines: CartLine[]; setLines: (lines: CartLine[]) => void } {
    const userId = usePage<any>().props.auth?.user?.id;
    const store = useMemo(() => cartStoreFor(userId), [userId]);
    const lines = useSyncExternalStore(store.subscribe, store.get, () => []);

    return { lines, setLines: store.set };
}

export interface OrderItem {
    product: Row;
    quantity: number;
}

/**
 * The order page's cart. It is the same list as the cart icon's, shown on the live product rows, so there is one cart:
 * adding on the products page, changing here and the icon's count always agree.
 */
export function useOrderCart(products: Row[]): [OrderItem[], (next: OrderItem[] | ((previous: OrderItem[]) => OrderItem[])) => void] {
    const { lines, setLines } = usePartnerCartLines();
    const items = useMemo(() => orderLines(lines, products), [lines, products]);
    const latest = useRef(items);
    latest.current = items;

    const setItems = useCallback((next: OrderItem[] | ((previous: OrderItem[]) => OrderItem[])) => {
        const value = typeof next === 'function' ? next(latest.current) : next;
        latest.current = value;
        setLines(linesFromItems(value));
    }, [setLines]);

    return [items, setItems];
}
