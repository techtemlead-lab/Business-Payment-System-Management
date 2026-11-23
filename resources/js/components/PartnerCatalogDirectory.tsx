import React, { useState, useMemo, useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Package, Search, ShoppingBag, ArrowRight, ShoppingCart, LayoutGrid, List } from 'lucide-react';
import { money, number, Table, type Row } from './UI';
import { useT } from '../shared/i18n/t';
import { FilterDropdown } from './FilterDropdown';
import { catalogWord } from '../shared/catalogWords';
import PartnerCartCard from './partnercart/PartnerCartCard';
import CartChooser from './partnercart/CartChooser';
import { oneCardPerGroup, sizesByGroup, type SizeCard } from '../shared/productSizes';
import { addLine, lineFromProduct, refreshLines } from '../shared/partnerCart';
import { usePartnerCartLines } from '../shared/usePartnerCart';

interface Props {
    products: Row[];
    categories?: string[];
    filters?: Row;
}

export default function PartnerCatalogDirectory({ products = [], categories = [], filters = {} }: Props) {
    const translate = useT();
    const pageProps = usePage<any>().props;
    const { publicBase = '', portalBase: base = '' } = pageProps;
    // Units free to promise now, by product id: a warning on the card and in the chooser, never a block.
    const availableMap: Record<string, number> = pageProps.data?.available_now ?? {};
    const availableOf = (product: Row): number | null => {
        const known = availableMap[String(product.id)];
        return known === undefined || known === null ? null : Number(known);
    };
    const { lines, setLines } = usePartnerCartLines();
    const [chooser, setChooser] = useState<null | { card: SizeCard; startId: number | string; mode: 'cart' | 'now' }>(null);
    const [added, setAdded] = useState<string | null>(null);
    const inCartOf = (product: Row) => lines.find(line => line.productId === Number(product.id))?.quantity ?? 0;

    // Names and prices in the cart follow today's list whenever the page is loaded.
    useEffect(() => {
        if (lines.length > 0) {
            setLines(refreshLines(lines, products));
        }
        // Only when the product list arrives: a quantity change must not loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [products]);

    const confirmChoice = (product: Row, quantity: number) => {
        setLines(addLine(lines, lineFromProduct(product, quantity)));
        if (chooser?.mode === 'now') {
            setChooser(null);
            router.visit(`${base}/purchase`);
            return;
        }
        setChooser(null);
        setAdded(translate('catalog_cart', 'added', { name: `${product.name}${product.size_label ? ` (${product.size_label})` : ''}`, qty: number(quantity) }));
        window.setTimeout(() => setAdded(null), 4000);
    };

    const [viewMode, setViewMode] = useState<'grid' | 'table'>('grid');
    const [search, setSearch] = useState<string>((filters?.q as string) || '');
    const [selectedCat, setSelectedCat] = useState<string>('');

    // Available categories
    const allCategories = useMemo(() => {
        const set = new Set<string>();
        products.forEach(p => {
            if (p.category) set.add(p.category);
        });
        const arr = Array.from(set);
        arr.sort((a, b) => a.localeCompare(b));
        return arr.length ? arr : categories;
    }, [products, categories]);

    // Filter products
    const filteredProducts = useMemo(() => {
        return products.filter(p => {
            if (selectedCat && p.category !== selectedCat) return false;
            if (search.trim()) {
                const q = search.toLowerCase();
                const matchName = (p.name || '').toLowerCase().includes(q);
                const matchSku = (p.sku || '').toLowerCase().includes(q);
                const matchCat = (p.category || '').toLowerCase().includes(q);
                const matchSub = (p.sub_category || '').toLowerCase().includes(q);
                const matchStrength = (p.strength || '').toLowerCase().includes(q);
                if (!matchName && !matchSku && !matchCat && !matchSub && !matchStrength) return false;
            }
            return true;
        });
    }, [products, selectedCat, search]);

    // A size group is one card, so it also counts as one on the category buttons.
    const groups = useMemo(() => sizesByGroup(products), [products]);
    const cards = useMemo(() => oneCardPerGroup(filteredProducts, groups), [filteredProducts, groups]);
    const cardCount = (list: Row[]) => oneCardPerGroup(list, groups).length;

    return (
        <div className="ops-stack is-wide">
            {/* Header Action Banner */}
            <div className="ops-card ops-row is-between is-wrap is-wide pcd-intro">
                <div>
                    <h2 className="hub-title is-lg">
                        {translate('portal', 'b2b_catalog_list_title')}
                    </h2>
                    <p className="hub-sub">
                        {translate('portal', 'pcd_intro')}
                    </p>
                </div>
                <Link
                    href={`${base}/purchase`}
                    className="button dark pcd-cta"
                >
                    <ShoppingBag size={16} /> {translate('portal', 'b2b_goto_purchase_btn')} <ArrowRight size={15} />
                </Link>
            </div>

            {/* Filter and Search Bar */}
            <div className="ops-card is-tight ops-row is-wrap is-loose">
                <div className="pcd-search">
                    <Search size={16} />
                    <input
                        type="text"
                        placeholder={translate('portal', 'pcd_search_placeholder')}
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                    />
                </div>

                <FilterDropdown
                    className="pcd-category"
                    label={translate('portal', 'b2b_all_categories')}
                    allLabel={`${translate('portal', 'b2b_all_categories')} (${cardCount(products)})`}
                    value={selectedCat}
                    onChange={setSelectedCat}
                    options={allCategories.map(cat => ({ value: cat, label: catalogWord(translate, 'category', cat), count: cardCount(products.filter(p => p.category === cat)) }))}
                />

                {/* View Mode Toggle */}
                <div className="hub-seg ops-ml-auto">
                    <button
                        type="button"
                        onClick={() => setViewMode('grid')}
                        className={viewMode === 'grid' ? 'is-active' : undefined}
                    >
                        <LayoutGrid size={14} /> {translate('portal', 'catalog_view_grid')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setViewMode('table')}
                        className={viewMode === 'table' ? 'is-active' : undefined}
                    >
                        <List size={14} /> {translate('portal', 'catalog_view_table')}
                    </button>
                </div>
            </div>

            {/* Product Directory: Grid View vs Table View */}
            {viewMode === 'grid' ? (
                filteredProducts.length > 0 ? (
                    <div className="pcart-grid">
                        {cards.map(card => (
                            <PartnerCartCard key={card.key} card={card} detailsUrl={product => `${publicBase}/products/${product.id}`} availableOf={availableOf} onChoose={(chosen, startId, mode) => setChooser({ card: chosen, startId, mode })} />
                        ))}
                    </div>
                ) : (
                    <div className="ops-card ops-center pcd-empty">
                        <Package size={40} />
                        <p className="pcd-empty-title">{translate('portal', 'pcd_empty_title')}</p>
                        <small>{translate('portal', 'pcd_empty_body')}</small>
                    </div>
                )
            ) : (
                /* Product Directory Table */
                <section className="panel pcd-table-panel">
                    <Table
                        columns={[
                            {
                                key: 'name',
                                title: translate('portal', 'pcd_col_specification'),
                                sortable: true,
                                sortValue: (p: Row) => p.name || '',
                                render: (p: Row) => (
                                    <div className="ops-row is-loose">
                                        {p.image_url ? (
                                            <img
                                                src={p.image_url}
                                                alt={p.name}
                                                className="pcd-thumb"
                                            />
                                        ) : p.image_fallback ? (
                                            <img
                                                src={p.image_fallback.url}
                                                alt={p.image_fallback.alt}
                                                title={p.image_fallback.caption}
                                                width={38}
                                                height={38}
                                                loading="lazy"
                                                decoding="async"
                                                className="pcd-thumb"
                                                style={{ objectFit: 'cover' }}
                                            />
                                        ) : (
                                            <div className="pcd-thumb is-placeholder">
                                                MH
                                            </div>
                                        )}
                                        <div>
                                            <div className="pcd-name">
                                                <a href={`${publicBase}/products/${p.id}`} target="_blank" rel="noreferrer">
                                                    {p.name}
                                                </a>
                                                {p.strength && (
                                                    <span className="pcd-strength">
                                                        {p.strength}
                                                    </span>
                                                )}
                                                {p.size_label && <span className="pcd-strength">{p.size_label}</span>}
                                            </div>
                                            <div className="cell-sub">
                                                SKU: <code>{p.sku}</code>
                                                {!p.image_url && p.image_fallback ? ` · ${p.image_fallback.caption}` : null}
                                            </div>
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'category',
                                title: translate('portal', 'pcd_col_category'),
                                sortable: true,
                                sortValue: (p: Row) => p.category || '',
                                render: (p: Row) => (
                                    <div>
                                        <div className="ops-semibold ops-text-2">{catalogWord(translate, 'category', p.category)}</div>
                                        {p.sub_category && (
                                            <div className="ops-meta">{p.sub_category}</div>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'unit',
                                title: translate('portal', 'pcd_col_pack'),
                                sortable: true,
                                sortValue: (p: Row) => p.unit || '',
                                render: (p: Row) => <span>{catalogWord(translate, 'unit', p.unit || 'Pack')}</span>,
                            },
                            {
                                key: 'mrp',
                                title: 'MRP (৳)',
                                sortable: true,
                                align: 'right',
                                sortValue: (p: Row) => Number(p.mrp || 0),
                                render: (p: Row) => <span className="ops-muted">{money(Number(p.mrp || 0))}</span>,
                            },
                            {
                                key: 'price',
                                title: translate('portal', 'pcd_col_price'),
                                sortable: true,
                                align: 'right',
                                sortValue: (p: Row) => Number(p.price || p.mrp || 0),
                                render: (p: Row) => {
                                    const mrpVal = Number(p.mrp || 0);
                                    const priceVal = Number(p.price || p.mrp || 0);
                                    const hasDiscount = mrpVal > 0 && priceVal > 0 && priceVal < mrpVal;
                                    const discountPct = hasDiscount ? Math.round(((mrpVal - priceVal) / mrpVal) * 100) : 0;
                                    return (
                                        <div>
                                            <div className="pcd-price">
                                                {money(priceVal)}
                                            </div>
                                            {hasDiscount && (
                                                <span className="pcd-off">
                                                    {translate('portal', 'pct_off', { pct: String(discountPct) })}
                                                </span>
                                            )}
                                        </div>
                                    );
                                },
                            },
                            {
                                key: 'action',
                                title: translate('portal', 'pcd_col_action'),
                                align: 'center',
                                render: (p: Row) => (
                                    <button type="button" className="button small dark" onClick={() => setChooser({ card: oneCardPerGroup([p], groups)[0], startId: p.id, mode: 'cart' })}>
                                        <ShoppingCart size={14} aria-hidden="true" /> {translate('catalog_cart', 'add_to_cart')}
                                    </button>
                                ),
                            },
                        ]}
                        data={filteredProducts}
                        pageSize={15}
                        emptyMessage={translate('portal', 'pcd_empty_title')}
                    />
                </section>
            )}

            {added && <p className="pcart-toast" role="status" data-testid="cart-added">{added}</p>}
            {chooser && (
                <CartChooser
                    card={chooser.card}
                    startId={chooser.startId}
                    mode={chooser.mode}
                    availableOf={availableOf}
                    inCartOf={inCartOf}
                    onClose={() => setChooser(null)}
                    onConfirm={confirmChoice}
                />
            )}
        </div>
    );
}
