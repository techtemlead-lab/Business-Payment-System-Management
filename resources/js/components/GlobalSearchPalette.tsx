import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { router, usePage } from '@inertiajs/react';
import { Package, Search, ShoppingBag, Users, X, type LucideIcon } from 'lucide-react';
import { label } from './UI';
import { useT } from '../shared/i18n/t';
import { jsonRequest, JsonRequestError } from '../shared/jsonRequest';

type Results = {
    orders: { id: string; number: string; status: string; partner: string | null; partner_code: string | null }[];
    partners: { id: number; name: string; code: string | null; role: string; status: string; phone: string }[];
    products: { id: number; name: string; sku: string; category: string | null; active: boolean }[];
};

type Hit = { key: string; title: string; detail: string; href: string };

const MINIMUM_LENGTH = 2;
const TYPING_PAUSE_MS = 250;

export default function GlobalSearchPalette({ onClose }: { onClose: () => void }) {
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const translate = useT();
    const input = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Results | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [active, setActive] = useState(0);

    useEffect(() => {
        input.current?.focus();
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = previousOverflow;
        };
    }, []);

    useEffect(() => {
        const term = query.trim();
        if (term.length < MINIMUM_LENGTH) {
            setResults(null);
            setLoading(false);
            setError(null);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(() => {
            jsonRequest<{ data: Results }>(`/commands/search?${new URLSearchParams({ q: term }).toString()}`, { signal: controller.signal })
                .then(({ data }) => {
                    setResults(data);
                    setActive(0);
                    setError(null);
                    setLoading(false);
                })
                .catch((failure: unknown) => {
                    if (failure instanceof DOMException && failure.name === 'AbortError') {
                        return;
                    }
                    setResults(null);
                    setError(translate('productivity', failure instanceof JsonRequestError && failure.status === 429 ? 'search_slow_down' : 'search_failed'));
                    setLoading(false);
                });
        }, TYPING_PAUSE_MS);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, translate]);

    const groups = useMemo((): { title: string; icon: LucideIcon; hits: Hit[] }[] => {
        if (!results) {
            return [];
        }
        return [
            {
                title: translate('productivity', 'search_group_orders'),
                icon: ShoppingBag,
                hits: results.orders.map((order) => ({
                    key: `order-${order.id}`,
                    title: order.number,
                    detail: [order.partner, order.partner_code, label(order.status, translate)].filter(Boolean).join(' · '),
                    href: `${base}/orders/${order.id}`,
                })),
            },
            {
                title: translate('productivity', 'search_group_partners'),
                icon: Users,
                hits: results.partners.map((partner) => ({
                    key: `partner-${partner.id}`,
                    title: partner.name,
                    detail: [partner.code, label(partner.role, translate), partner.phone].filter(Boolean).join(' · '),
                    href: `${base}/${partner.role === 'sub_dealer' ? 'sub-dealers' : 'accounts'}?${new URLSearchParams({ q: partner.code || partner.name }).toString()}`,
                })),
            },
            {
                title: translate('productivity', 'search_group_products'),
                icon: Package,
                hits: results.products.map((product) => ({
                    key: `product-${product.id}`,
                    title: product.name,
                    detail: [product.sku, product.category].filter(Boolean).join(' · '),
                    href: `${base}/catalog?${new URLSearchParams({ q: product.sku }).toString()}`,
                })),
            },
        ].filter((group) => group.hits.length > 0);
    }, [base, results, translate]);

    const hits = useMemo(() => groups.flatMap((group) => group.hits), [groups]);

    const go = (hit: Hit | undefined) => {
        if (!hit) {
            return;
        }
        onClose();
        router.visit(hit.href);
    };

    const onKeyDown = (event: ReactKeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
        } else if (event.key === 'ArrowDown' && hits.length > 0) {
            event.preventDefault();
            setActive((index) => (index + 1) % hits.length);
        } else if (event.key === 'ArrowUp' && hits.length > 0) {
            event.preventDefault();
            setActive((index) => (index - 1 + hits.length) % hits.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(hits[active]);
        }
    };

    const term = query.trim();
    let position = -1;

    return createPortal(
        <div className="global-search-layer" onMouseDown={(event) => { if (event.target === event.currentTarget) { onClose(); } }}>
            <div className="global-search-dialog" role="dialog" aria-modal="true" aria-label={translate('productivity', 'search_label')} onKeyDown={onKeyDown}>
                <div className="global-search-input">
                    <Search size={18} aria-hidden="true" />
                    <input
                        ref={input}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={translate('productivity', 'search_placeholder')}
                        aria-label={translate('productivity', 'search_placeholder')}
                        autoComplete="off"
                    />
                    <button type="button" className="icon-button" aria-label={translate('productivity', 'close')} onClick={onClose}><X size={16} /></button>
                </div>
                <div className="global-search-results" aria-live="polite">
                    {term.length < MINIMUM_LENGTH && <p className="global-search-hint">{translate('productivity', 'search_hint')}</p>}
                    {error && <p className="global-search-hint" role="alert">{error}</p>}
                    {loading && <p className="global-search-hint">{translate('productivity', 'search_loading')}</p>}
                    {!loading && !error && results && hits.length === 0 && <p className="global-search-hint">{translate('productivity', 'search_empty')}</p>}
                    {!loading && groups.map((group) => (
                        <section key={group.title}>
                            <h3><group.icon size={14} aria-hidden="true" />{group.title}</h3>
                            <ul>
                                {group.hits.map((hit) => {
                                    position += 1;
                                    const index = position;
                                    return (
                                        <li key={hit.key}>
                                            <button type="button" className={index === active ? 'is-active' : ''} onMouseEnter={() => setActive(index)} onClick={() => go(hit)}>
                                                <b>{hit.title}</b>
                                                <small>{hit.detail}</small>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                </div>
            </div>
        </div>,
        document.body,
    );
}
