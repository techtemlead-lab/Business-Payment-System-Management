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
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {/* Header Action Banner */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                padding: '18px 22px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 16,
                boxShadow: '0 2px 8px rgba(0,0,0,0.04)'
            }}>
                <div>
                    <h2 style={{ margin: '0 0 4px', fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                        {translate('portal', 'b2b_catalog_list_title')}
                    </h2>
                    <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>
                        উপলব্ধ সকল পণ্যের বিবরণ, মাত্রা ও অনুমোদিত ক্রয় মূল্য। দ্রুত অর্ডার করতে 'প্রোডাক্ট পারচেজ' পেজ ব্যবহার করুন।
                    </p>
                </div>
                <Link
                    href={`${base}/purchase`}
                    className="button dark"
                    style={{
                        padding: '10px 22px',
                        fontSize: 13,
                        fontWeight: 700,
                        background: '#006837',
                        borderColor: '#006837',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                        boxShadow: '0 4px 12px rgba(0, 104, 55, 0.2)'
                    }}
                >
                    <ShoppingBag size={16} /> {translate('portal', 'b2b_goto_purchase_btn')} <ArrowRight size={15} />
                </Link>
            </div>

            {/* Filter and Search Bar */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                padding: '12px 16px',
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                flexWrap: 'wrap'
            }}>
                <div style={{ position: 'relative', flex: 1, minWidth: 200 }}>
                    <Search size={16} style={{ position: 'absolute', left: 12, top: 12, color: '#94a3b8' }} />
                    <input
                        type="text"
                        placeholder="পণ্য বা ব্র্যান্ডের নাম, মাত্রা, SKU দিয়ে খুঁজুন..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        style={{ width: '100%', height: 38, paddingLeft: 36, paddingRight: 12, borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 13 }}
                    />
                </div>

                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                    <button
                        type="button"
                        onClick={() => setSelectedCat('')}
                        style={{
                            padding: '6px 12px',
                            borderRadius: 20,
                            fontSize: 12,
                            fontWeight: 600,
                            border: !selectedCat ? '1.5px solid #006837' : '1px solid #cbd5e1',
                            background: !selectedCat ? '#ecfdf5' : '#ffffff',
                            color: !selectedCat ? '#047857' : '#475569',
                            cursor: 'pointer'
                        }}
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
                                style={{
                                    padding: '6px 12px',
                                    borderRadius: 20,
                                    fontSize: 12,
                                    fontWeight: 600,
                                    border: active ? '1.5px solid #006837' : '1px solid #cbd5e1',
                                    background: active ? '#ecfdf5' : '#ffffff',
                                    color: active ? '#047857' : '#475569',
                                    cursor: 'pointer'
                                }}
                            >
                                {cat} ({count})
                            </button>
                        );
                    })}
                </div>

                {/* View Mode Toggle */}
                <div style={{ display: 'inline-flex', border: '1px solid #cbd5e1', borderRadius: 8, overflow: 'hidden', background: '#f8fafc', marginLeft: 'auto' }}>
                    <button
                        type="button"
                        onClick={() => setViewMode('grid')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            border: 0,
                            background: viewMode === 'grid' ? '#006837' : 'transparent',
                            color: viewMode === 'grid' ? '#ffffff' : '#64748b',
                            cursor: 'pointer',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <LayoutGrid size={14} /> গ্রিড ভিউ (Cards)
                    </button>
                    <button
                        type="button"
                        onClick={() => setViewMode('table')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            border: 0,
                            background: viewMode === 'table' ? '#006837' : 'transparent',
                            color: viewMode === 'table' ? '#ffffff' : '#64748b',
                            cursor: 'pointer',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <List size={14} /> টেবিল ভিউ (Table)
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
                    <div style={{ padding: '50px 20px', textAlign: 'center', color: '#64748b', background: '#ffffff', borderRadius: 12, border: '1px solid #e2e8f0' }}>
                        <Package size={40} style={{ opacity: 0.4, marginBottom: 8 }} />
                        <p style={{ margin: '0 0 6px', fontWeight: 700, fontSize: 16 }}>কোনো পণ্য পাওয়া যায়নি</p>
                        <small>অনুসন্ধান পরিবর্তন করে পুনরায় চেষ্টা করুন।</small>
                    </div>
                )
            ) : (
                /* Product Directory Table */
                <section className="panel" style={{ padding: 16, overflow: 'hidden', borderRadius: 10, border: '1px solid #e2e8f0', background: '#ffffff' }}>
                    <Table
                        columns={[
                            {
                                key: 'name',
                                title: 'পণ্যের বিবরণ ও মাত্রা (Specification)',
                                sortable: true,
                                sortValue: (p: Row) => p.name || '',
                                render: (p: Row) => (
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                        {p.image_url ? (
                                            <img
                                                src={p.image_url}
                                                alt={p.name}
                                                style={{ width: 38, height: 38, borderRadius: 6, objectFit: 'contain', background: '#f8fafc', border: '1px solid #e2e8f0', flexShrink: 0 }}
                                            />
                                        ) : (
                                            <div style={{ width: 38, height: 38, borderRadius: 6, background: '#f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#006837', fontWeight: 800, fontSize: 11, flexShrink: 0 }}>
                                                MH
                                            </div>
                                        )}
                                        <div>
                                            <div style={{ fontWeight: 700, color: '#0f172a', fontSize: 14 }}>
                                                <a href={`${publicBase}/products/${p.id}`} target="_blank" rel="noreferrer" style={{ color: 'inherit', textDecoration: 'none' }}>
                                                    {p.name}
                                                </a>
                                                {p.strength && (
                                                    <span style={{ marginLeft: 6, background: '#e0f2fe', color: '#0369a1', fontSize: 11, fontWeight: 700, padding: '1px 6px', borderRadius: 4 }}>
                                                        {p.strength}
                                                    </span>
                                                )}
                                            </div>
                                            <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                                SKU: <code>{p.sku}</code>
                                            </div>
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'category',
                                title: 'ক্যাটাগরি ও সাব-ক্যাটাগরি',
                                sortable: true,
                                sortValue: (p: Row) => p.category || '',
                                render: (p: Row) => (
                                    <div>
                                        <div style={{ fontWeight: 600, color: '#334155' }}>{p.category}</div>
                                        {p.sub_category && (
                                            <div style={{ fontSize: 11, color: '#64748b' }}>{p.sub_category}</div>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'unit',
                                title: 'একক (Pack)',
                                sortable: true,
                                sortValue: (p: Row) => p.unit || '',
                                render: (p: Row) => <span>{p.unit || 'Pack'}</span>,
                            },
                            {
                                key: 'mrp',
                                title: 'MRP (BDT)',
                                sortable: true,
                                align: 'right',
                                sortValue: (p: Row) => Number(p.mrp || 0),
                                render: (p: Row) => <span style={{ color: '#64748b' }}>{money(Number(p.mrp || 0))}</span>,
                            },
                            {
                                key: 'price',
                                title: 'ক্রয় মূল্য (BDT)',
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
                                            <div style={{ fontWeight: 800, color: '#065f46', fontSize: 14 }}>
                                                {money(priceVal)}
                                            </div>
                                            {hasDiscount && (
                                                <span style={{ fontSize: 10, fontWeight: 700, color: '#047857', background: '#ecfdf5', padding: '1px 5px', borderRadius: 3 }}>
                                                    {discountPct}% ছাড়
                                                </span>
                                            )}
                                        </div>
                                    );
                                },
                            },
                            {
                                key: 'stock',
                                title: 'স্টক স্থিতি',
                                sortable: true,
                                align: 'center',
                                sortValue: (p: Row) => Number(p.stock || 0),
                                render: (p: Row) => {
                                    const inStock = p.stock > 0 && !p.is_stock_paused;
                                    return (
                                        <span style={{
                                            fontSize: 11,
                                            fontWeight: 700,
                                            padding: '3px 8px',
                                            borderRadius: 12,
                                            background: inStock ? '#dcfce7' : '#fee2e2',
                                            color: inStock ? '#15803d' : '#b91c1c'
                                        }}>
                                            {inStock ? `${p.stock} Units` : 'Stock Awaiting'}
                                        </span>
                                    );
                                },
                            },
                            {
                                key: 'action',
                                title: 'অ্যাকশন',
                                align: 'center',
                                render: (p: Row) => (
                                    <Link
                                        href={`${base}/purchase?product_id=${p.id}`}
                                        className="button small"
                                        style={{
                                            padding: '4px 10px',
                                            fontSize: 11,
                                            fontWeight: 700,
                                            background: '#006837',
                                            color: '#ffffff',
                                            border: 'none',
                                            whiteSpace: 'nowrap'
                                        }}
                                    >
                                        {translate('portal', 'b2b_order_item_btn')}
                                    </Link>
                                ),
                            },
                        ]}
                        data={filteredProducts}
                        pageSize={15}
                        emptyMessage="কোনো পণ্য পাওয়া যায়নি"
                    />
                </section>
            )}
        </div>
    );
}
