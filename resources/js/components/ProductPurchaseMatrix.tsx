import React, { useState, useMemo, useEffect } from 'react';
import { useForm, router, Link } from '@inertiajs/react';
import {
    Plus, Trash2, ShoppingBag, ArrowRight, CheckCircle2,
    AlertCircle, Sparkles, Package, Layers, Info, Search, X
} from 'lucide-react';
import { money, type Row, Table } from './UI';
import { useT } from '../shared/i18n/t';

interface Props {
    products: Row[];
    categories: string[];
    subCategories?: string[];
    collectionAccounts: Row[];
    initialProductId?: number | string | null;
}

interface CartItem {
    product: Row;
    quantity: number;
}

export default function ProductPurchaseMatrix({
    products = [],
    categories = [],
    subCategories = [],
    collectionAccounts = [],
    initialProductId = null,
}: Props) {
    const translate = useT();

    // Cascading selection state
    const [selectedCategory, setSelectedCategory] = useState<string>('');
    const [selectedSubCategory, setSelectedSubCategory] = useState<string>('');
    const [selectedProductName, setSelectedProductName] = useState<string>('');
    const [selectedProductId, setSelectedProductId] = useState<number | string>('');
    const [quantity, setQuantity] = useState<number>(1);
    const [addedBanner, setAddedBanner] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState<string>('');

    // Instant search suggestions across name, sku, category, strength
    const searchResults = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        if (!q || q.length < 2) return [];
        return products.filter(p => {
            const name = String(p.name || '').toLowerCase();
            const sku = String(p.sku || '').toLowerCase();
            const cat = String(p.category || '').toLowerCase();
            const sub = String(p.sub_category || '').toLowerCase();
            const str = String(p.strength || '').toLowerCase();
            return name.includes(q) || sku.includes(q) || cat.includes(q) || sub.includes(q) || str.includes(q);
        }).slice(0, 8);
    }, [products, searchQuery]);

    const selectProductFromSearch = (p: Row) => {
        setSelectedCategory(p.category || '');
        setSelectedSubCategory(p.sub_category || '');
        setSelectedProductName(p.name || '');
        setSelectedProductId(p.id);
        setSearchQuery('');
    };

    // Multi-item cart
    const [cart, setCart] = useState<CartItem[]>([]);

    // Filter available categories from active products
    const availableCategories = useMemo(() => {
        const set = new Set<string>();
        products.forEach(p => {
            if (p.category) set.add(p.category);
        });
        const arr = Array.from(set);
        arr.sort((a, b) => a.localeCompare(b));
        return arr.length ? arr : categories;
    }, [products, categories]);

    // Initialize with first category or pre-selected product
    useEffect(() => {
        if (initialProductId) {
            const found = products.find(p => String(p.id) === String(initialProductId));
            if (found) {
                setSelectedCategory(found.category || '');
                setSelectedSubCategory(found.sub_category || '');
                setSelectedProductName(found.name || '');
                setSelectedProductId(found.id);
                return;
            }
        }
        if (!selectedCategory && availableCategories.length > 0) {
            setSelectedCategory(availableCategories[0]);
        }
    }, [availableCategories, initialProductId, products]);

    // Available subcategories under selected category
    const availableSubCategories = useMemo(() => {
        if (!selectedCategory) return [];
        const set = new Set<string>();
        products.filter(p => p.category === selectedCategory).forEach(p => {
            if (p.sub_category) set.add(p.sub_category);
        });
        const arr = Array.from(set);
        arr.sort((a, b) => a.localeCompare(b));
        return arr;
    }, [products, selectedCategory]);

    // Available product names under category + subcategory
    const availableProductNames = useMemo(() => {
        if (!selectedCategory) return [];
        const filtered = products.filter(p => {
            if (p.category !== selectedCategory) return false;
            if (selectedSubCategory && p.sub_category !== selectedSubCategory) return false;
            return true;
        });
        const set = new Set<string>();
        filtered.forEach(p => set.add(p.name));
        const arr = Array.from(set);
        arr.sort((a, b) => a.localeCompare(b));
        return arr;
    }, [products, selectedCategory, selectedSubCategory]);

    // Available variants (strengths/packs) under the selected product name
    const availableVariants = useMemo(() => {
        if (!selectedCategory || !selectedProductName) return [];
        return products.filter(p => {
            if (p.category !== selectedCategory) return false;
            if (selectedSubCategory && p.sub_category !== selectedSubCategory) return false;
            return p.name === selectedProductName;
        });
    }, [products, selectedCategory, selectedSubCategory, selectedProductName]);

    // Active selected product object
    const activeProduct = useMemo(() => {
        if (!selectedProductId) {
            return availableVariants[0] || null;
        }
        return availableVariants.find(p => String(p.id) === String(selectedProductId)) || availableVariants[0] || null;
    }, [availableVariants, selectedProductId]);

    // Handle cascading changes
    const handleCategoryChange = (cat: string) => {
        setSelectedCategory(cat);
        setSelectedSubCategory('');
        setSelectedProductName('');
        setSelectedProductId('');
    };

    const handleSubCategoryChange = (sub: string) => {
        setSelectedSubCategory(sub);
        setSelectedProductName('');
        setSelectedProductId('');
    };

    const handleProductNameChange = (name: string) => {
        setSelectedProductName(name);
        setSelectedProductId('');
    };

    const handleVariantChange = (id: string | number) => {
        setSelectedProductId(id);
    };

    // Add current selection to cart
    const handleAddToCart = (e: React.FormEvent) => {
        e.preventDefault();
        if (!activeProduct) return;
        const qty = Math.max(1, Number(quantity) || 1);

        setCart(prev => {
            const existingIndex = prev.findIndex(item => item.product.id === activeProduct.id);
            if (existingIndex > -1) {
                const next = [...prev];
                next[existingIndex] = {
                    ...next[existingIndex],
                    quantity: next[existingIndex].quantity + qty,
                };
                return next;
            }
            return [...prev, { product: activeProduct, quantity: qty }];
        });

        const strengthLabel = activeProduct.strength ? ` (${activeProduct.strength})` : '';
        setAddedBanner(`${activeProduct.name}${strengthLabel} — ${qty} ${activeProduct.unit || 'Box'} ${translate('portal', 'b2b_added_success')}`);
        setTimeout(() => setAddedBanner(null), 4000);

        // Intentionally keep selectedCategory, selectedSubCategory, and selectedProductName!
        // This allows dealer to immediately add another strength/variant or change quantity.
    };

    // Update quantity in cart
    const updateCartQty = (productId: number, newQty: number) => {
        if (newQty <= 0) {
            removeFromCart(productId);
            return;
        }
        setCart(prev => prev.map(item => item.product.id === productId ? { ...item, quantity: newQty } : item));
    };

    const removeFromCart = (productId: number) => {
        setCart(prev => prev.filter(item => item.product.id !== productId));
    };

    // Calculate live pricing metrics for active selected product
    const mrp = Number(activeProduct?.mrp || 0);
    const unitPrice = Number(activeProduct?.price || activeProduct?.mrp || 0);
    const hasDiscount = mrp > 0 && unitPrice > 0 && unitPrice < mrp;
    const unitSavings = hasDiscount ? mrp - unitPrice : 0;
    const discountPct = hasDiscount ? Math.round((unitSavings / mrp) * 100) : 0;
    const currentLineTotal = unitPrice * (Math.max(1, Number(quantity) || 1));

    // Cart totals
    const cartSummary = useMemo(() => {
        let totalQty = 0;
        let totalMrp = 0;
        let totalPayable = 0;

        cart.forEach(item => {
            const itemMrp = Number(item.product.mrp || 0);
            const itemPrice = Number(item.product.price || item.product.mrp || 0);
            totalQty += item.quantity;
            totalMrp += itemMrp * item.quantity;
            totalPayable += itemPrice * item.quantity;
        });

        const totalSavings = Math.max(0, totalMrp - totalPayable);
        const overallDiscountPct = totalMrp > 0 ? Math.round((totalSavings / totalMrp) * 100) : 0;

        return {
            itemsCount: cart.length,
            totalQty,
            totalMrp,
            totalPayable,
            totalSavings,
            overallDiscountPct,
        };
    }, [cart]);

    // Checkout form
    const f = useForm({
        items: [] as { product_id: number; quantity: number }[],
        idempotency_key: crypto.randomUUID(),
        reference: '',
        payment_account_id: '',
        channel: 'Bank',
        amount: '',
        note: '',
    });

    const submitOrder = (e: React.FormEvent) => {
        e.preventDefault();
        if (cart.length === 0) return;

        f.transform(() => ({
            items: cart.map(item => ({
                product_id: item.product.id,
                quantity: item.quantity,
            })),
            idempotency_key: crypto.randomUUID(),
            reference: f.data.reference,
            payment_account_id: f.data.payment_account_id,
            channel: f.data.channel,
            amount: f.data.amount,
            note: f.data.note,
        }));

        f.post('/commands/orders', {
            onSuccess: () => {
                setCart([]);
                f.reset('reference', 'amount', 'note');
            },
        });
    };

    return (
        <div className="b2b-purchase-container" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Header with Direct Link to Directory */}
            <div style={{
                background: 'linear-gradient(135deg, #022c1b 0%, #006837 100%)',
                color: '#ffffff',
                padding: '20px 24px',
                borderRadius: 12,
                display: 'flex',
                alignItems: 'center',
                justifyContent:'space-between',
                flexWrap: 'wrap',
                gap: 16,
                boxShadow: '0 4px 16px rgba(0, 104, 55, 0.15)'
            }}>
                <div>
                    <h2 style={{ margin: '0 0 6px', fontSize: 20, fontWeight: 800, letterSpacing: '-0.01em', color: '#ffffff' }}>
                        {translate('portal', 'b2b_purchase_title')}
                    </h2>
                    <p style={{ margin: 0, fontSize: 13, color: '#dcfce7', maxWidth: 640, lineHeight: 1.5 }}>
                        {translate('portal', 'b2b_purchase_subtitle')}
                    </p>
                </div>
                <Link
                    href="/catalog"
                    className="button small"
                    style={{ background: '#ffffff', color: '#006837', fontWeight: 700, border: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}
                >
                    <Package size={15} /> {translate('portal', 'b2b_catalog_list_title')} ↗
                </Link>
            </div>

            {/* Success Toast */}
            {addedBanner && (
                <div style={{
                    background: '#ecfdf5',
                    border: '1.5px solid #10b981',
                    color: '#065f46',
                    padding: '12px 18px',
                    borderRadius: 8,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    fontWeight: 600,
                    fontSize: 13,
                    animation: 'fadeIn 0.25s ease-out'
                }}>
                    <CheckCircle2 size={18} style={{ color: '#059669', flexShrink: 0 }} />
                    <span>{addedBanner}</span>
                </div>
            )}

            {/* Cascading Matrix Builder Panel */}
            <section className="panel" style={{ border: '1.5px solid #00683726', background: '#ffffff', borderRadius: 12, padding: '20px 24px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16, borderBottom: '1px solid #f1f5f9', paddingBottom: 12 }}>
                    <Sparkles size={18} style={{ color: '#006837' }} />
                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                        {translate('portal', 'b2b_purchase_title')}
                    </h3>
                    <span style={{ fontSize: 11, background: '#ecfdf5', color: '#047857', fontWeight: 700, padding: '2px 8px', borderRadius: 12, marginLeft: 'auto' }}>
                        Persistent B2B Matrix
                    </span>
                </div>

                {/* Instant Product Search */}
                <div style={{ marginBottom: 16, position: 'relative' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, background: '#f8fafc', padding: '8px 12px', borderRadius: 8, border: '1.5px solid #cbd5e1' }}>
                        <Search size={16} style={{ color: '#64748b', flexShrink: 0 }} />
                        <input
                            type="text"
                            placeholder={translate('portal', 'b2b_quick_search_placeholder')}
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                            style={{ border: 'none', background: 'transparent', outline: 'none', width: '100%', fontSize: 13, fontWeight: 500 }}
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => setSearchQuery('')}
                                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 2 }}
                            >
                                <X size={14} />
                            </button>
                        )}
                    </div>
                    {searchResults.length > 0 && (
                        <div style={{
                            position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 40,
                            background: '#ffffff', borderRadius: 8, marginTop: 4,
                            boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1)',
                            border: '1.5px solid #cbd5e1', maxHeight: 280, overflowY: 'auto'
                        }}>
                            {searchResults.map(p => (
                                <div
                                    key={p.id}
                                    onClick={() => selectProductFromSearch(p)}
                                    style={{
                                        padding: '10px 14px', borderBottom: '1px solid #f1f5f9', cursor: 'pointer',
                                        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                                        transition: 'background 0.15s ease'
                                    }}
                                    onMouseEnter={e => (e.currentTarget.style.background = '#f0fdf4')}
                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                                >
                                    <div>
                                        <strong style={{ fontSize: 13, color: '#0f172a' }}>{p.name}</strong>
                                        {p.strength && <span style={{ fontSize: 12, color: '#047857', marginLeft: 6, fontWeight: 600 }}>({p.strength})</span>}
                                        <div style={{ fontSize: 11, color: '#64748b' }}>
                                            {p.category} {p.sub_category ? `› ${p.sub_category}` : ''} · SKU: {p.sku}
                                        </div>
                                    </div>
                                    <div style={{ textAlign: 'right' }}>
                                        <div style={{ fontSize: 13, fontWeight: 700, color: '#006837' }}>
                                            ৳ {Number(p.price || p.mrp || 0).toFixed(2)}
                                        </div>
                                        {Number(p.mrp || 0) > Number(p.price || p.mrp || 0) && (
                                            <div style={{ fontSize: 10, color: '#94a3b8', textDecoration: 'line-through' }}>
                                                ৳ {Number(p.mrp).toFixed(2)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <form onSubmit={handleAddToCart}>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                        {/* Step 1: Category */}
                        <div>
                            <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 5 }}>
                                {translate('portal', 'b2b_step_category')}
                            </label>
                            <select
                                required
                                value={selectedCategory}
                                onChange={e => handleCategoryChange(e.target.value)}
                                style={{ width: '100%', height: 42, padding: '8px 12px', borderRadius: 8, border: '1.5px solid #cbd5e1', fontSize: 13, background: '#f8fafc', fontWeight: 600 }}
                            >
                                <option value="">{translate('portal', 'b2b_select_category_first')}</option>
                                {availableCategories.map(cat => (
                                    <option key={cat} value={cat}>{cat}</option>
                                ))}
                            </select>
                        </div>

                        {/* Step 2: Sub-Category */}
                        <div>
                            <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 5 }}>
                                {translate('portal', 'b2b_step_subcategory')}
                            </label>
                            <select
                                value={selectedSubCategory}
                                onChange={e => handleSubCategoryChange(e.target.value)}
                                style={{ width: '100%', height: 42, padding: '8px 12px', borderRadius: 8, border: '1.5px solid #cbd5e1', fontSize: 13, background: '#f8fafc', fontWeight: 600 }}
                            >
                                <option value="">{translate('portal', 'b2b_all_subcategories')}</option>
                                {availableSubCategories.map(sub => (
                                    <option key={sub} value={sub}>{sub}</option>
                                ))}
                            </select>
                        </div>

                        {/* Step 3: Product Name */}
                        <div>
                            <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 5 }}>
                                {translate('portal', 'b2b_step_product')}
                            </label>
                            <select
                                required
                                value={selectedProductName}
                                onChange={e => handleProductNameChange(e.target.value)}
                                disabled={!selectedCategory || availableProductNames.length === 0}
                                style={{ width: '100%', height: 42, padding: '8px 12px', borderRadius: 8, border: '1.5px solid #cbd5e1', fontSize: 13, background: '#f8fafc', fontWeight: 600 }}
                            >
                                <option value="">{translate('portal', 'b2b_select_product_first')}</option>
                                {availableProductNames.map(name => (
                                    <option key={name} value={name}>{name}</option>
                                ))}
                            </select>
                        </div>

                        {/* Step 4: Strength / Measure / Variant */}
                        <div>
                            <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 5 }}>
                                {translate('portal', 'b2b_step_strength')}
                            </label>
                            <select
                                value={activeProduct ? activeProduct.id : ''}
                                onChange={e => handleVariantChange(e.target.value)}
                                disabled={!selectedProductName || availableVariants.length === 0}
                                style={{ width: '100%', height: 42, padding: '8px 12px', borderRadius: 8, border: '1.5px solid #cbd5e1', fontSize: 13, background: '#f8fafc', fontWeight: 600 }}
                            >
                                {availableVariants.map(v => {
                                    const vMrp = Number(v.mrp || 0);
                                    const vPrice = Number(v.price || v.mrp || 0);
                                    const vDisc = vMrp > vPrice ? Math.round(((vMrp - vPrice) / vMrp) * 100) : 0;
                                    const specText = v.strength || v.unit || translate('portal', 'b2b_single_spec');
                                    return (
                                        <option key={v.id} value={v.id}>
                                            {specText} — ৳ {vPrice.toFixed(2)} (MRP ৳{vMrp.toFixed(2)}{vDisc > 0 ? ` · ${vDisc}% off` : ''})
                                        </option>
                                    );
                                })}
                            </select>
                        </div>

                        {/* Step 5: Quantity & Add Button */}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                            <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end' }}>
                                <div style={{ flex: 1 }}>
                                    <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 5 }}>
                                        {translate('portal', 'b2b_step_quantity')} {activeProduct?.unit ? `(${activeProduct.unit})` : ''}
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="50000"
                                        required
                                        value={quantity}
                                        onChange={e => setQuantity(Math.max(1, parseInt(e.target.value, 10) || 1))}
                                        style={{ width: '100%', height: 42, padding: '8px 12px', borderRadius: 8, border: '1.5px solid #cbd5e1', fontSize: 14, fontWeight: 700 }}
                                    />
                                </div>
                                <button
                                    type="submit"
                                    className="button dark"
                                    disabled={!activeProduct || activeProduct.is_stock_paused || Number(activeProduct.stock || 0) <= 0}
                                    style={{
                                        height: 42,
                                        padding: '0 20px',
                                        borderRadius: 8,
                                        fontSize: 13,
                                        fontWeight: 700,
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 6,
                                        whiteSpace: 'nowrap',
                                        background: '#006837',
                                        borderColor: '#006837'
                                    }}
                                >
                                    <Plus size={16} /> {translate('portal', 'b2b_add_to_order')}
                                </button>
                            </div>
                            <div style={{ display: 'flex', gap: 5, alignItems: 'center', flexWrap: 'wrap' }}>
                                <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>{translate('portal', 'b2b_quick_qty')}:</span>
                                {[5, 10, 20, 50, 100].map(chip => (
                                    <button
                                        key={chip}
                                        type="button"
                                        onClick={() => setQuantity(q => q + chip)}
                                        style={{
                                            fontSize: 11, fontWeight: 700, padding: '2px 7px', borderRadius: 5,
                                            background: '#f1f5f9', border: '1px solid #cbd5e1', color: '#334155', cursor: 'pointer'
                                        }}
                                    >
                                        +{chip}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => setQuantity(1)}
                                    style={{
                                        fontSize: 11, fontWeight: 600, padding: '2px 7px', borderRadius: 5,
                                        background: '#ffffff', border: '1px solid #e2e8f0', color: '#64748b', cursor: 'pointer'
                                    }}
                                >
                                    1
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Active Selection Details Strip */}
                    {activeProduct && (
                        <div style={{
                            marginTop: 16,
                            padding: '12px 16px',
                            background: '#f0fdf4',
                            border: '1px solid #bbf7d0',
                            borderRadius: 8,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            flexWrap: 'wrap',
                            gap: 12
                        }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                <div style={{ fontSize: 13, fontWeight: 700, color: '#065f46' }}>
                                    {activeProduct.name} {activeProduct.strength ? `(${activeProduct.strength})` : ''}
                                    <span style={{ fontSize: 11, color: '#047857', marginLeft: 8, fontWeight: 500 }}>
                                        SKU: {activeProduct.sku} · {activeProduct.category}{activeProduct.sub_category ? ` / ${activeProduct.sub_category}` : ''}
                                    </span>
                                </div>
                                <span style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    padding: '2px 8px',
                                    borderRadius: 12,
                                    background: activeProduct.stock > 0 && !activeProduct.is_stock_paused ? '#dcfce7' : '#fee2e2',
                                    color: activeProduct.stock > 0 && !activeProduct.is_stock_paused ? '#15803d' : '#b91c1c'
                                }}>
                                    {activeProduct.stock > 0 && !activeProduct.is_stock_paused ? `Stock: ${activeProduct.stock} ${activeProduct.unit || 'units'}` : 'Stock Awaiting'}
                                </span>
                            </div>

                            <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                                <div>
                                    <small style={{ display: 'block', fontSize: 10, color: '#64748b' }}>MRP / Box</small>
                                    <span style={{ fontSize: 12, color: '#64748b', textDecoration: hasDiscount ? 'line-through' : 'none' }}>
                                        {money(mrp)}
                                    </span>
                                </div>

                                {hasDiscount && (
                                    <div>
                                        <small style={{ display: 'block', fontSize: 10, color: '#059669' }}>কমিশন ছাড়</small>
                                        <span style={{ fontSize: 12, fontWeight: 700, color: '#047857', background: '#dcfce7', padding: '1px 6px', borderRadius: 4 }}>
                                            {discountPct}% ({money(unitSavings)} সাশ্রয়)
                                        </span>
                                    </div>
                                )}

                                <div>
                                    <small style={{ display: 'block', fontSize: 10, color: '#065f46' }}>ক্রয় দর / বক্স</small>
                                    <strong style={{ fontSize: 14, color: '#065f46', fontWeight: 800 }}>
                                        {money(unitPrice)}
                                    </strong>
                                </div>

                                <div style={{ borderLeft: '1px solid #bbf7d0', paddingLeft: 16 }}>
                                    <small style={{ display: 'block', fontSize: 10, color: '#065f46' }}>লাইন মোট ({quantity} বক্স)</small>
                                    <strong style={{ fontSize: 16, color: '#006837', fontWeight: 900 }}>
                                        {money(currentLineTotal)}
                                    </strong>
                                </div>
                            </div>

                            <div style={{ width: '100%', borderTop: '1px dashed #bbf7d0', paddingTop: 8, marginTop: 4, display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#047857' }}>
                                <Info size={13} style={{ flexShrink: 0 }} />
                                <span>{translate('portal', 'b2b_keep_category_hint')}</span>
                            </div>
                        </div>
                    )}
                </form>
            </section>

            {/* Selected Products Order Table Breakdown */}
            <section className="panel" style={{ borderRadius: 12, padding: '20px 24px', background: '#ffffff', border: '1px solid #e2e8f0' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                    <div>
                        <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                            {translate('portal', 'b2b_order_list_title')}
                        </h3>
                        <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                            {cart.length > 0
                                ? `${cart.length} টি প্রোডাক্ট অর্ডার তালিকায় যোগ করা হয়েছে। পরিমাণ পরিবর্তন করতে পারেন।`
                                : 'এখনো কোনো প্রোডাক্ট যোগ করা হয়নি। উপরের ফর্ম থেকে প্রোডাক্ট যোগ করুন।'}
                        </p>
                    </div>
                    {cart.length > 0 && (
                        <button
                            type="button"
                            className="text-link"
                            style={{ color: '#ef4444', fontSize: 12, fontWeight: 600 }}
                            onClick={() => setCart([])}
                        >
                            সব মুছুন (Clear All)
                        </button>
                    )}
                </div>

                {cart.length > 0 ? (
                    <div className="table-responsive">
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                            <thead>
                                <tr style={{ background: '#f8fafc', borderBottom: '2px solid #e2e8f0', textAlign: 'left', color: '#475569', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                                    <th style={{ padding: '10px 12px', width: '5%' }}>{translate('portal', 'b2b_col_sl')}</th>
                                    <th style={{ padding: '10px 12px', width: '28%' }}>{translate('portal', 'b2b_col_product_desc')}</th>
                                    <th style={{ padding: '10px 12px', width: '11%', textAlign: 'right' }}>{translate('portal', 'b2b_col_mrp')}</th>
                                    <th style={{ padding: '10px 12px', width: '12%', textAlign: 'center' }}>{translate('portal', 'b2b_col_discount_pct')}</th>
                                    <th style={{ padding: '10px 12px', width: '13%', textAlign: 'right' }}>{translate('portal', 'b2b_col_unit_price')}</th>
                                    <th style={{ padding: '10px 12px', width: '13%', textAlign: 'center' }}>{translate('portal', 'b2b_col_qty')}</th>
                                    <th style={{ padding: '10px 12px', width: '13%', textAlign: 'right' }}>{translate('portal', 'b2b_col_total')}</th>
                                    <th style={{ padding: '10px 12px', width: '5%', textAlign: 'center' }}></th>
                                </tr>
                            </thead>
                            <tbody>
                                {cart.map((item, index) => {
                                    const p = item.product;
                                    const itemMrp = Number(p.mrp || 0);
                                    const itemPrice = Number(p.price || p.mrp || 0);
                                    const lineDiscount = itemMrp > itemPrice ? Math.round(((itemMrp - itemPrice) / itemMrp) * 100) : 0;
                                    const lineTotal = itemPrice * item.quantity;

                                    return (
                                        <tr key={p.id} style={{ borderBottom: '1px solid #f1f5f9', background: index % 2 === 0 ? '#ffffff' : '#fafafa' }}>
                                            <td style={{ padding: '12px', fontWeight: 600, color: '#64748b' }}>{index + 1}</td>
                                            <td style={{ padding: '12px' }}>
                                                <div style={{ fontWeight: 700, color: '#0f172a', fontSize: 14 }}>
                                                    {p.name}
                                                </div>
                                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                                    {p.strength ? <strong style={{ color: '#006837', marginRight: 6 }}>{p.strength}</strong> : null}
                                                    <span>{p.category}{p.sub_category ? ` · ${p.sub_category}` : ''}</span>
                                                    <span style={{ marginLeft: 6 }}>({p.unit || 'pack'})</span>
                                                </div>
                                            </td>
                                            <td style={{ padding: '12px', textAlign: 'right', color: '#64748b' }}>
                                                {money(itemMrp)}
                                            </td>
                                            <td style={{ padding: '12px', textAlign: 'center' }}>
                                                {lineDiscount > 0 ? (
                                                    <span style={{ background: '#ecfdf5', color: '#047857', fontWeight: 700, fontSize: 11, padding: '2px 8px', borderRadius: 4, border: '1px solid #a7f3d0' }}>
                                                        {lineDiscount}% ছাড়
                                                    </span>
                                                ) : (
                                                    <span style={{ color: '#94a3b8', fontSize: 11 }}>—</span>
                                                )}
                                            </td>
                                            <td style={{ padding: '12px', textAlign: 'right', fontWeight: 700, color: '#065f46' }}>
                                                {money(itemPrice)}
                                            </td>
                                            <td style={{ padding: '12px', textAlign: 'center' }}>
                                                <div style={{ display: 'inline-flex', alignItems: 'center', border: '1px solid #cbd5e1', borderRadius: 6, overflow: 'hidden' }}>
                                                    <button
                                                        type="button"
                                                        onClick={() => updateCartQty(p.id, item.quantity - 1)}
                                                        style={{ width: 28, height: 28, background: '#f8fafc', border: 'none', cursor: 'pointer', fontWeight: 700 }}
                                                    >
                                                        -
                                                    </button>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="50000"
                                                        value={item.quantity}
                                                        onChange={e => updateCartQty(p.id, parseInt(e.target.value, 10) || 1)}
                                                        style={{ width: 44, height: 28, border: 'none', textAlign: 'center', fontWeight: 700, fontSize: 12 }}
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => updateCartQty(p.id, item.quantity + 1)}
                                                        style={{ width: 28, height: 28, background: '#f8fafc', border: 'none', cursor: 'pointer', fontWeight: 700 }}
                                                    >
                                                        +
                                                    </button>
                                                </div>
                                            </td>
                                            <td style={{ padding: '12px', textAlign: 'right', fontWeight: 800, color: '#006837', fontSize: 14 }}>
                                                {money(lineTotal)}
                                            </td>
                                            <td style={{ padding: '12px', textAlign: 'center' }}>
                                                <button
                                                    type="button"
                                                    onClick={() => removeFromCart(p.id)}
                                                    style={{ background: 'transparent', border: 'none', color: '#ef4444', cursor: 'pointer', padding: 4 }}
                                                    title="Remove Item"
                                                >
                                                    <Trash2 size={16} />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div style={{ padding: '36px 16px', textAlign: 'center', color: '#94a3b8', background: '#f8fafc', borderRadius: 8, border: '1px dashed #cbd5e1' }}>
                        <ShoppingBag size={32} style={{ opacity: 0.5, marginBottom: 8 }} />
                        <p style={{ margin: 0, fontWeight: 600 }}>অর্ডারের তালিকা বর্তমানে খালি</p>
                        <small style={{ color: '#64748b' }}>উপরে ক্যাটাগরি, সাব-ক্যাটাগরি এবং প্রোডাক্ট সিলেক্ট করে '+ অর্ডারে যোগ করুন' বাটনে ক্লিক করুন।</small>
                    </div>
                )}

                {/* Totals & Checkout Summary Form */}
                {cart.length > 0 && (
                    <div style={{ marginTop: 24, paddingTop: 20, borderTop: '2px solid #e2e8f0' }}>
                        {/* Summary Metrics Bar */}
                        <div style={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
                            gap: 12,
                            background: '#f8fafc',
                            padding: 16,
                            borderRadius: 10,
                            border: '1px solid #e2e8f0',
                            marginBottom: 20
                        }}>
                            <div>
                                <small style={{ color: '#64748b', fontSize: 11, display: 'block' }}>মোট আইটেম ও ইউনিট</small>
                                <strong style={{ fontSize: 15, color: '#334155' }}>
                                    {cartSummary.itemsCount} আইটেম ({cartSummary.totalQty} বক্স)
                                </strong>
                            </div>

                            <div>
                                <small style={{ color: '#64748b', fontSize: 11, display: 'block' }}>{translate('portal', 'b2b_total_mrp')}</small>
                                <strong style={{ fontSize: 15, color: '#64748b', textDecoration: 'line-through' }}>
                                    {money(cartSummary.totalMrp)}
                                </strong>
                            </div>

                            <div>
                                <small style={{ color: '#059669', fontSize: 11, display: 'block' }}>{translate('portal', 'b2b_total_savings')}</small>
                                <strong style={{ fontSize: 15, color: '#059669' }}>
                                    {money(cartSummary.totalSavings)} ({cartSummary.overallDiscountPct}% কমিশন)
                                </strong>
                            </div>

                            <div style={{ background: '#ecfdf5', padding: '8px 12px', borderRadius: 6, border: '1.5px solid #a7f3d0' }}>
                                <small style={{ color: '#065f46', fontSize: 11, fontWeight: 700, display: 'block' }}>{translate('portal', 'b2b_net_payable')}</small>
                                <strong style={{ fontSize: 18, color: '#006837', fontWeight: 900 }}>
                                    {money(cartSummary.totalPayable)}
                                </strong>
                            </div>
                        </div>

                        {/* Payment & Submission */}
                        <form onSubmit={submitOrder} className="action-form">
                            <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                                পেমেন্ট ও পারচেজ রিকোয়েস্ট নিশ্চিতকরণ
                            </h4>

                            <div className="form-grid">
                                <label>
                                    {translate('portal', 'payment_method')}
                                    <select
                                        value={f.data.channel}
                                        onChange={e => {
                                            f.setData('channel', e.target.value);
                                            f.setData('payment_account_id', '');
                                        }}
                                    >
                                        {['Bank', 'bKash', 'Nagad', 'Rocket'].map(x => (
                                            <option key={x} value={x}>{x}</option>
                                        ))}
                                    </select>
                                </label>

                                <label>
                                    {translate('portal', 'collection_account')}
                                    <select
                                        value={f.data.payment_account_id}
                                        onChange={e => f.setData('payment_account_id', e.target.value)}
                                    >
                                        <option value="">{translate('portal', 'select_collection_account')}</option>
                                        {collectionAccounts
                                            .filter(a => a.channel === f.data.channel)
                                            .map(a => (
                                                <option key={a.id} value={a.id}>
                                                    {a.holder} · {a.account_number}
                                                </option>
                                            ))}
                                    </select>
                                </label>

                                <label>
                                    {translate('portal', 'transaction_reference')}
                                    <input
                                        placeholder="ট্রানজেকশন আইডি / রেফারেন্স"
                                        value={f.data.reference}
                                        onChange={e => f.setData('reference', e.target.value)}
                                    />
                                </label>

                                <label>
                                    {translate('portal', 'claimed_payment_amount')} (ঐচ্ছিক)
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder={`৳ ${cartSummary.totalPayable.toFixed(2)}`}
                                        value={f.data.amount}
                                        onChange={e => f.setData('amount', e.target.value)}
                                    />
                                </label>
                            </div>

                            <label>
                                {translate('portal', 'note')}
                                <textarea
                                    rows={2}
                                    placeholder="ডেলিভারি বা বিশেষ কোনো নির্দেশনা থাকলে লিখুন..."
                                    value={f.data.note}
                                    onChange={e => f.setData('note', e.target.value)}
                                />
                            </label>

                            {Object.keys(f.errors).length > 0 && (
                                <div className="error" style={{ marginBottom: 12 }}>
                                    {Object.values(f.errors).join(' ')}
                                </div>
                            )}

                            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}>
                                <button
                                    type="submit"
                                    className="button dark"
                                    disabled={f.processing}
                                    style={{
                                        padding: '12px 28px',
                                        fontSize: 14,
                                        fontWeight: 800,
                                        background: '#006837',
                                        borderColor: '#006837',
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 8,
                                        boxShadow: '0 4px 12px rgba(0, 104, 55, 0.25)'
                                    }}
                                >
                                    {f.processing ? translate('portal', 'submitting') : translate('portal', 'submit_purchase_request')}
                                    <ArrowRight size={17} />
                                </button>
                            </div>
                        </form>
                    </div>
                )}
            </section>
        </div>
    );
}
