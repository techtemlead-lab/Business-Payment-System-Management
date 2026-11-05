import React, { useState, useMemo } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Package, Search, ShoppingBag, ArrowRight, CheckCircle2, AlertTriangle, LayoutGrid, List } from 'lucide-react';
import { money, Table, type TableColumn, type Row } from './UI';
import { useT } from '../shared/i18n/t';
import LuxuryProductCard from './LuxuryProductCard';

interface Props {
    products: Row[];
    categories?: string[];
    filters?: Row;
}

export default function PartnerCatalogDirectory({ products = [], categories = [], filters = {} }: Props) {
    const translate = useT();
    const pageProps = usePage<any>().props;
    const { publicBase = '', portalBase: base = '', portal = '', auth = {} } = pageProps;
    const isSubDealer = portal === 'subdealer' || auth?.user?.role === 'sub_dealer';
    const partnerRole: 'dealer' | 'subdealer' = isSubDealer ? 'subdealer' : 'dealer';

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

    return (
        <div className="ops-stack is-wide">
            {/* Header Action Banner */}
            <div className="ops-card ops-row is-between is-wrap is-wide">
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

                <div className="ops-row is-wrap is-snug">
                    <button
                        type="button"
                        onClick={() => setSelectedCat('')}
                        className={`pcd-chip${!selectedCat ? ' is-active' : ''}`}
                    >
                        {translate('portal', 'b2b_all_categories')} ({products.length})
                    </button>
                    {allCategories.map(cat => {
                        const count = products.filter(p => p.category === cat).length;
                        const active = selectedCat === cat;
                        return (
                            <button
                                key={cat}
                                type="button"
                                onClick={() => setSelectedCat(cat)}
                                className={`pcd-chip${active ? ' is-active' : ''}`}
                            >
                                {cat} ({count})
                            </button>
                        );
                    })}
                </div>

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
                    <div className="luxury-product-grid">
                        {filteredProducts.map(p => (
                            <LuxuryProductCard
                                key={p.id}
                                product={p}
                                role={partnerRole}
                                detailsUrl={`${publicBase}/products/${p.id}`}
                                orderUrl={`${base}/purchase?product_id=${p.id}`}
                            />
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
                                            </div>
                                            <div className="cell-sub">
                                                SKU: <code>{p.sku}</code>
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
                                        <div className="ops-semibold ops-text-2">{p.category}</div>
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
                                render: (p: Row) => <span>{p.unit || 'Pack'}</span>,
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
                                key: 'stock',
                                title: translate('portal', 'pcd_col_stock'),
                                sortable: true,
                                align: 'center',
                                sortValue: (p: Row) => Number(p.stock || 0),
                                render: (p: Row) => {
                                    const inStock = p.stock > 0 && !p.is_stock_paused;
                                    return (
                                        <span className={`hub-pill ${inStock ? 'tone-ok' : 'tone-danger'}`}>
                                            {inStock ? `${p.stock} Units` : 'Stock Awaiting'}
                                        </span>
                                    );
                                },
                            },
                            {
                                key: 'action',
                                title: translate('portal', 'pcd_col_action'),
                                align: 'center',
                                render: (p: Row) => (
                                    <Link
                                        href={`${base}/purchase?product_id=${p.id}`}
                                        className="button small dark"
                                    >
                                        {translate('portal', 'b2b_order_item_btn')}
                                    </Link>
                                ),
                            },
                        ]}
                        data={filteredProducts}
                        pageSize={15}
                        emptyMessage={translate('portal', 'pcd_empty_title')}
                    />
                </section>
            )}
        </div>
    );
}
