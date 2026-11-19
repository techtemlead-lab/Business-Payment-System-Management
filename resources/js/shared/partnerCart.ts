import type { Row } from '../components/UI';

/**
 * The one shopping cart of a dealer or sub-dealer.
 *
 * The products page, the cart icon in the top bar and the order page all read and write this same list, so there is
 * never a second cart to keep in step. It lives in the browser (local storage, one list per person), so it survives
 * a refresh and moving between pages, and it is emptied after an order is sent.
 *
 * A line keeps a small snapshot of the product (name, size, price) so the cart can be shown on any page, even where
 * the product list was not loaded. The order page always works from the live product row, never from the snapshot.
 */
export interface CartLine {
    productId: number;
    quantity: number;
    name: string;
    sizeLabel: string;
    unit: string;
    mrp: number;
    price: number;
    imageUrl: string;
}

/** The most the server accepts on one order line. */
export const MAX_CART_QUANTITY = 10000;

/** Only what the cart needs to read and write storage; the browser's `localStorage` fits. */
export interface CartStorage {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

/** A quantity the server will take: a whole number from 1 to the limit. */
export function clampQuantity(value: unknown): number {
    const whole = Math.floor(Number(value));
    if (!Number.isFinite(whole) || whole < 1) {
        return 1;
    }

    return Math.min(MAX_CART_QUANTITY, whole);
}

/** What the partner pays for one unit: their price, else the printed price. */
function unitPriceOf(product: Row): number {
    return Number(product.price || product.mrp || 0);
}

/** A line from a product row, as it looks now. */
export function lineFromProduct(product: Row, quantity: number): CartLine {
    return {
        productId: Number(product.id),
        quantity: clampQuantity(quantity),
        name: String(product.name ?? ''),
        sizeLabel: String(product.size_label || product.strength || ''),
        unit: String(product.unit ?? ''),
        mrp: Number(product.mrp || 0),
        price: unitPriceOf(product),
        imageUrl: String(product.image_url || product.image_fallback?.url || ''),
    };
}

/** Adds a line. The same product (each size is its own product) is not listed twice: the quantities are added up. */
export function addLine(lines: CartLine[], line: CartLine): CartLine[] {
    const index = lines.findIndex(existing => existing.productId === line.productId);
    if (index === -1) {
        return [...lines, { ...line, quantity: clampQuantity(line.quantity) }];
    }

    return lines.map((existing, at) => at === index
        ? { ...line, quantity: clampQuantity(existing.quantity + clampQuantity(line.quantity)) }
        : existing);
}

/** Sets the quantity of one line; zero or less takes the line out. */
export function setLineQuantity(lines: CartLine[], productId: number, quantity: number): CartLine[] {
    if (!(Number(quantity) >= 1)) {
        return removeLine(lines, productId);
    }

    return lines.map(line => line.productId === productId ? { ...line, quantity: clampQuantity(quantity) } : line);
}

export function removeLine(lines: CartLine[], productId: number): CartLine[] {
    return lines.filter(line => line.productId !== productId);
}

/** Refreshes the names and prices on the lines from the product rows the page has just loaded. */
export function refreshLines(lines: CartLine[], products: Row[]): CartLine[] {
    const byId = new Map(products.map(product => [Number(product.id), product]));

    return lines.map(line => {
        const product = byId.get(line.productId);

        return product ? { ...lineFromProduct(product, line.quantity) } : line;
    });
}

/** How many different products are in the cart (the number on the cart icon). */
export function lineCount(lines: CartLine[]): number {
    return lines.length;
}

export function unitCount(lines: CartLine[]): number {
    return lines.reduce((sum, line) => sum + line.quantity, 0);
}

/** The partner total of the cart, in taka, at the prices on the lines. */
export function cartTotal(lines: CartLine[]): number {
    // Whole paisa per line, so the total never drifts by a fraction.
    return lines.reduce((sum, line) => sum + Math.round(line.price * 100) * line.quantity, 0) / 100;
}

/**
 * The hand-off to the order page: each line becomes an order line on the live product row.
 * A product that is no longer on the order page's list (switched off, archived) is left out, so it can never be ordered.
 */
export function orderLines(lines: CartLine[], products: Row[]): { product: Row; quantity: number }[] {
    const byId = new Map(products.map(product => [Number(product.id), product]));
    const items: { product: Row; quantity: number }[] = [];
    lines.forEach(line => {
        const product = byId.get(line.productId);
        if (product) {
            items.push({ product, quantity: clampQuantity(line.quantity) });
        }
    });

    return items;
}

/** The reverse of {@link orderLines}: the order page's items as cart lines. */
export function linesFromItems(items: { product: Row; quantity: number }[]): CartLine[] {
    return items.map(item => lineFromProduct(item.product, item.quantity));
}

/** The storage key of one person's cart, so two people on one computer never share a cart. */
export function cartKey(userId: unknown): string {
    return `madina.partner-cart.v1.${String(userId ?? 'guest')}`;
}

/** Reads a saved cart. Anything damaged or hand-edited is dropped line by line, never trusted. */
export function parseLines(text: string | null): CartLine[] {
    if (!text) {
        return [];
    }
    let data: unknown;
    try {
        data = JSON.parse(text);
    } catch {
        return [];
    }
    if (!Array.isArray(data)) {
        return [];
    }
    const lines: CartLine[] = [];
    data.forEach(entry => {
        const id = Number(entry?.productId);
        if (!Number.isInteger(id) || id < 1 || !(Number(entry?.quantity) >= 1)) {
            return;
        }
        lines.push({
            productId: id,
            quantity: clampQuantity(entry.quantity),
            name: String(entry.name ?? ''),
            sizeLabel: String(entry.sizeLabel ?? ''),
            unit: String(entry.unit ?? ''),
            mrp: Number(entry.mrp) || 0,
            price: Number(entry.price) || 0,
            imageUrl: String(entry.imageUrl ?? ''),
        });
    });

    return lines.filter((line, at) => lines.findIndex(other => other.productId === line.productId) === at);
}

export function loadLines(storage: CartStorage | null, key: string): CartLine[] {
    try {
        return parseLines(storage?.getItem(key) ?? null);
    } catch {
        return [];
    }
}

export function saveLines(storage: CartStorage | null, key: string, lines: CartLine[]): void {
    try {
        if (lines.length === 0) {
            storage?.removeItem(key);
        } else {
            storage?.setItem(key, JSON.stringify(lines));
        }
    } catch {
        // Storage can be full or switched off (private window): the cart then lives until the page is closed.
    }
}

/** The page's cart as a small store that components can subscribe to. */
export interface CartStore {
    get(): CartLine[];
    set(lines: CartLine[]): void;
    subscribe(listener: () => void): () => void;
}

export function createCartStore(storage: CartStorage | null, key: string): CartStore {
    let current = loadLines(storage, key);
    const listeners = new Set<() => void>();
    const tell = () => listeners.forEach(listener => listener());

    return {
        get: () => current,
        set(lines) {
            current = lines;
            saveLines(storage, key, lines);
            tell();
        },
        subscribe(listener) {
            listeners.add(listener);

            return () => { listeners.delete(listener); };
        },
    };
}

/** One store per key for the page's lifetime, so every component sees the same cart. */
const stores = new Map<string, CartStore>();

export function cartStoreFor(userId: unknown): CartStore {
    const key = cartKey(userId);
    let store = stores.get(key);
    if (!store) {
        let storage: CartStorage | null = null;
        try {
            storage = typeof window !== 'undefined' ? window.localStorage : null;
        } catch {
            storage = null;
        }
        store = createCartStore(storage, key);
        stores.set(key, store);
        // Another tab changed the cart: pick it up.
        if (typeof window !== 'undefined') {
            window.addEventListener('storage', event => {
                if (event.key === key) {
                    store?.set(loadLines(storage, key));
                }
            });
        }
    }

    return store;
}
