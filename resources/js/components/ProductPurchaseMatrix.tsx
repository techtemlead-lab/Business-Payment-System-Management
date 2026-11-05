import React, { useState, useMemo, useEffect } from 'react';
import { useForm, router, Link } from '@inertiajs/react';
import {
    Plus, Trash2, ShoppingBag, ArrowRight, CheckCircle2,
    AlertCircle, Sparkles, Package, Layers, Info, Search, X
} from 'lucide-react';
import { money, type Row, Table, number } from './UI';
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
        <div className="b2b-purchase-container hub">
            {/* Header with Direct Link to Directory */}
            <div className="ppm-hero">
                <div>
                    <h2 className="ppm-hero-title">
                        {translate('portal', 'b2b_purchase_title')}
                    </h2>
                    <p className="ppm-hero-sub">
                        {translate('portal', 'b2b_purchase_subtitle')}
                    </p>
                </div>
                <Link
                    href="/catalog"
                    className="button small ppm-hero-link"
                >
                    <Package size={15} /> {translate('portal', 'b2b_catalog_list_title')} ↗
                </Link>
            </div>

            {/* Success Toast */}
            {addedBanner && (
                <div className="success ppm-added">
                    <CheckCircle2 size={18} className="ops-shrink-0" />
                    <span>{addedBanner}</span>
                </div>
            )}

            {/* Cascading Matrix Builder Panel */}
            <section className="panel ppm-panel ppm-builder">
                <div className="ppm-head">
                    <Sparkles size={18} />
                    <h3 className="hub-title">
                        {translate('portal', 'b2b_purchase_title')}
                    </h3>
                    <span className="hub-pill tone-ok ops-ml-auto">
                        {translate('portal', 'b2b_cart_saved_badge')}
                    </span>
                </div>

                {/* Instant Product Search */}
                <div className="ppm-search">
                    <div className="ppm-search-box">
                        <Search size={16} className="ops-shrink-0" />
                        <input
                            type="text"
                            placeholder={translate('portal', 'b2b_quick_search_placeholder')}
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => setSearchQuery('')}
                                className="ppm-icon-btn"
                            >
                                <X size={14} />
                            </button>
                        )}
                    </div>
                    {searchResults.length > 0 && (
                        <div className="ppm-results">
                            {searchResults.map(p => (
                                <div
                                    key={p.id}
                                    onClick={() => selectProductFromSearch(p)}
                                    className="ppm-result"
                                    onMouseEnter={e => (e.currentTarget.style.background = '#f0fdf4')}
                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                                >
                                    <div>
                                        <strong className="hub-strong ops-fs-sm">{p.name}</strong>
                                        {p.strength && <span className="ppm-strength">({p.strength})</span>}
                                        <div className="ops-meta">
                                            {p.category} {p.sub_category ? `› ${p.sub_category}` : ''} · SKU: {p.sku}
                                        </div>
                                    </div>
                                    <div className="ops-right">
                                        <div className="ppm-price">
                                            {money(p.price || p.mrp || 0)}
                                        </div>
                                        {Number(p.mrp || 0) > Number(p.price || p.mrp || 0) && (
                                            <div className="ops-fs-2xs ops-faint ppm-struck">
                                                {money(p.mrp)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <form onSubmit={handleAddToCart}>
                    <div className="hub-grid">
                        {/* Step 1: Category */}
                        <div>
                            <label className="ops-field-label">
                                {translate('portal', 'b2b_step_category')}
                            </label>
                            <select
                                required
                                value={selectedCategory}
                                onChange={e => handleCategoryChange(e.target.value)}
                                className="ppm-control"
                            >
                                <option value="">{translate('portal', 'b2b_select_category_first')}</option>
                                {availableCategories.map(cat => (
                                    <option key={cat} value={cat}>{cat}</option>
                                ))}
                            </select>
                        </div>

                        {/* Step 2: Sub-Category */}
                        <div>
                            <label className="ops-field-label">
                                {translate('portal', 'b2b_step_subcategory')}
                            </label>
                            <select
                                value={selectedSubCategory}
                                onChange={e => handleSubCategoryChange(e.target.value)}
                                className="ppm-control"
                            >
                                <option value="">{translate('portal', 'b2b_all_subcategories')}</option>
                                {availableSubCategories.map(sub => (
                                    <option key={sub} value={sub}>{sub}</option>
                                ))}
                            </select>
                        </div>

                        {/* Step 3: Product Name */}
                        <div>
                            <label className="ops-field-label">
                                {translate('portal', 'b2b_step_product')}
                            </label>
                            <select
                                required
                                value={selectedProductName}
                                onChange={e => handleProductNameChange(e.target.value)}
                                disabled={!selectedCategory || availableProductNames.length === 0}
                                className="ppm-control"
                            >
                                <option value="">{translate('portal', 'b2b_select_product_first')}</option>
                                {availableProductNames.map(name => (
                                    <option key={name} value={name}>{name}</option>
                                ))}
                            </select>
                        </div>

                        {/* Step 4: Strength / Measure / Variant */}
                        <div>
                            <label className="ops-field-label">
                                {translate('portal', 'b2b_step_strength')}
                            </label>
                            <select
                                value={activeProduct ? activeProduct.id : ''}
                                onChange={e => handleVariantChange(e.target.value)}
                                disabled={!selectedProductName || availableVariants.length === 0}
                                className="ppm-control"
                            >
                                {availableVariants.map(v => {
                                    const vMrp = Number(v.mrp || 0);
                                    const vPrice = Number(v.price || v.mrp || 0);
                                    const vDisc = vMrp > vPrice ? Math.round(((vMrp - vPrice) / vMrp) * 100) : 0;
                                    const specText = v.strength || v.unit || translate('portal', 'b2b_single_spec');
                                    return (
                                        <option key={v.id} value={v.id}>
                                            {specText} — {money(vPrice)} (MRP {money(vMrp)}{vDisc > 0 ? ` · ${translate('portal', 'pct_off', { pct: String(vDisc) })}` : ''})
                                        </option>
                                    );
                                })}
                            </select>
                        </div>

                        {/* Step 5: Quantity & Add Button */}
                        <div className="ops-stack is-snug">
                            <div className="ppm-qty-row">
                                <div className="ops-grow">
                                    <label className="ops-field-label">
                                        {translate('portal', 'b2b_step_quantity')} {activeProduct?.unit ? `(${activeProduct.unit})` : ''}
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="50000"
                                        required
                                        value={quantity}
                                        onChange={e => setQuantity(Math.max(1, parseInt(e.target.value, 10) || 1))}
                                        className="ppm-control is-qty"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    className="button dark ppm-add"
                                    disabled={!activeProduct || activeProduct.is_stock_paused || Number(activeProduct.stock || 0) <= 0}
                                >
                                    <Plus size={16} /> {translate('portal', 'b2b_add_to_order')}
                                </button>
                            </div>
                            <div className="ops-row is-wrap is-tight">
                                <span className="ops-meta ops-semibold">{translate('portal', 'b2b_quick_qty')}:</span>
                                {[5, 10, 20, 50, 100].map(chip => (
                                    <button
                                        key={chip}
                                        type="button"
                                        onClick={() => setQuantity(q => q + chip)}
                                        className="ppm-chip"
                                    >
                                        +{chip}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => setQuantity(1)}
                                    className="ppm-chip is-reset"
                                >
                                    1
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Active Selection Details Strip */}
                    {activeProduct && (
                        <div className="ppm-selection">
                            <div className="ops-row is-loose is-wrap">
                                <div className="ppm-sel-name">
                                    {activeProduct.name} {activeProduct.strength ? `(${activeProduct.strength})` : ''}
                                    <span className="ppm-sel-sku">
                                        SKU: {activeProduct.sku} · {activeProduct.category}{activeProduct.sub_category ? ` / ${activeProduct.sub_category}` : ''}
                                    </span>
                                </div>
                                <span className={`hub-pill ${activeProduct.stock > 0 && !activeProduct.is_stock_paused ? 'tone-ok' : 'tone-danger'}`}>
                                    {activeProduct.stock > 0 && !activeProduct.is_stock_paused ? `Stock: ${activeProduct.stock} ${activeProduct.unit || 'units'}` : 'Stock Awaiting'}
                                </span>
                            </div>

                            <div className="ops-row is-wide is-wrap">
                                <div>
                                    <small className="ppm-sel-label ops-muted">MRP / Box</small>
                                    <span className={`ops-fs-xs ops-muted${hasDiscount ? ' ppm-struck' : ''}`}>
                                        {money(mrp)}
                                    </span>
                                </div>

                                {hasDiscount && (
                                    <div>
                                        <small className="ppm-sel-label">কমিশন ছাড়</small>
                                        <span className="ppm-save">
                                            {discountPct}% ({money(unitSavings)} সাশ্রয়)
                                        </span>
                                    </div>
                                )}

                                <div>
                                    <small className="ppm-sel-label">ক্রয় দর / বক্স</small>
                                    <strong className="ppm-sel-price">
                                        {money(unitPrice)}
                                    </strong>
                                </div>

                                <div className="ppm-sel-total">
                                    <small className="ppm-sel-label">লাইন মোট ({quantity} বক্স)</small>
                                    <strong className="ppm-sel-price is-lg">
                                        {money(currentLineTotal)}
                                    </strong>
                                </div>
                            </div>

                            <div className="ppm-sel-hint">
                                <Info size={13} className="ops-shrink-0" />
                                <span>{translate('portal', 'b2b_keep_category_hint')}</span>
                            </div>
                        </div>
                    )}
                </form>
            </section>

            {/* Selected Products Order Table Breakdown */}
            <section className="panel ppm-panel">
                <div className="ops-row is-between is-wrap ops-mb-4">
                    <div>
                        <h3 className="hub-title">
                            {translate('portal', 'b2b_order_list_title')}
                        </h3>
                        <p className="hub-sub">
                            {cart.length > 0
                                ? `${cart.length} টি প্রোডাক্ট অর্ডার তালিকায় যোগ করা হয়েছে। পরিমাণ পরিবর্তন করতে পারেন।`
                                : 'এখনো কোনো প্রোডাক্ট যোগ করা হয়নি। উপরের ফর্ম থেকে প্রোডাক্ট যোগ করুন।'}
                        </p>
                    </div>
                    {cart.length > 0 && (
                        <button
                            type="button"
                            className="text-link ppm-clear"
                            onClick={() => setCart([])}
                        >
                            {translate('portal', 'b2b_clear_all')}
                        </button>
                    )}
                </div>

                {cart.length > 0 ? (
                    <div className="table-responsive">
                        <table className="ppm-cart">
                            <thead>
                                <tr>
                                    <th>{translate('portal', 'b2b_col_sl')}</th>
                                    <th>{translate('portal', 'b2b_col_product_desc')}</th>
                                    <th className="ops-right">{translate('portal', 'b2b_col_mrp')}</th>
                                    <th className="ops-center">{translate('portal', 'b2b_col_discount_pct')}</th>
                                    <th className="ops-right">{translate('portal', 'b2b_col_unit_price')}</th>
                                    <th className="ops-center">{translate('portal', 'b2b_col_qty')}</th>
                                    <th className="ops-right">{translate('portal', 'b2b_col_total')}</th>
                                    <th className="ops-center"></th>
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
                                        <tr key={p.id}>
                                            <td className="ppm-sl">{index + 1}</td>
                                            <td>
                                                <div className="ppm-line-name">
                                                    {p.name}
                                                </div>
                                                <div className="cell-sub">
                                                    {p.strength ? <strong className="ppm-line-strength">{p.strength}</strong> : null}
                                                    <span>{p.category}{p.sub_category ? ` · ${p.sub_category}` : ''}</span>
                                                    <span className="ppm-line-unit">({p.unit || 'pack'})</span>
                                                </div>
                                            </td>
                                            <td className="ppm-mrp ops-right">
                                                {money(itemMrp)}
                                            </td>
                                            <td className="ops-center">
                                                {lineDiscount > 0 ? (
                                                    <span className="hub-pill tone-ok">
                                                        {number(lineDiscount)}% ছাড়
                                                    </span>
                                                ) : (
                                                    <span className="ops-faint ops-fs-2xs">—</span>
                                                )}
                                            </td>
                                            <td className="ppm-unit ops-right">
                                                {money(itemPrice)}
                                            </td>
                                            <td className="ops-center">
                                                <div className="ppm-stepper">
                                                    <button
                                                        type="button"
                                                        onClick={() => updateCartQty(p.id, item.quantity - 1)}
                                                    >
                                                        -
                                                    </button>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="50000"
                                                        value={item.quantity}
                                                        onChange={e => updateCartQty(p.id, parseInt(e.target.value, 10) || 1)}
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => updateCartQty(p.id, item.quantity + 1)}
                                                    >
                                                        +
                                                    </button>
                                                </div>
                                            </td>
                                            <td className="ppm-total ops-right">
                                                {money(lineTotal)}
                                            </td>
                                            <td className="ops-center">
                                                <button
                                                    type="button"
                                                    onClick={() => removeFromCart(p.id)}
                                                    className="ppm-icon-btn is-danger"
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
                    <div className="hub-empty ppm-empty">
                        <ShoppingBag size={32} />
                        <p className="ops-semibold">অর্ডারের তালিকা বর্তমানে খালি</p>
                        <small className="ops-muted">উপরে ক্যাটাগরি, সাব-ক্যাটাগরি এবং প্রোডাক্ট সিলেক্ট করে '+ অর্ডারে যোগ করুন' বাটনে ক্লিক করুন।</small>
                    </div>
                )}

                {/* Totals & Checkout Summary Form */}
                {cart.length > 0 && (
                    <div className="ppm-checkout">
                        {/* Summary Metrics Bar */}
                        <div className="ppm-summary">
                            <div>
                                <small className="ppm-sum-label">মোট আইটেম ও ইউনিট</small>
                                <strong className="ppm-sum-value">
                                    {number(cartSummary.itemsCount)} আইটেম ({cartSummary.totalQty} বক্স)
                                </strong>
                            </div>

                            <div>
                                <small className="ppm-sum-label">{translate('portal', 'b2b_total_mrp')}</small>
                                <strong className="ppm-sum-value is-muted ppm-struck">
                                    {money(cartSummary.totalMrp)}
                                </strong>
                            </div>

                            <div>
                                <small className="ppm-sum-label is-ok">{translate('portal', 'b2b_total_savings')}</small>
                                <strong className="ppm-sum-value is-ok">
                                    {money(cartSummary.totalSavings)} ({cartSummary.overallDiscountPct}% কমিশন)
                                </strong>
                            </div>

                            <div className="ppm-sum-net">
                                <small className="ppm-sum-label is-ok ops-bold">{translate('portal', 'b2b_net_payable')}</small>
                                <strong className="ppm-sum-value is-net">
                                    {money(cartSummary.totalPayable)}
                                </strong>
                            </div>
                        </div>

                        {/* Payment & Submission */}
                        <form onSubmit={submitOrder} className="action-form">
                            <h4 className="ppm-pay-title">
                                পেমেন্ট ও পারচেজ রিকোয়েস্ট নিশ্চিতকরণ
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
                                        placeholder={money(cartSummary.totalPayable)}
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
                                <div className="error ppm-error">
                                    {Object.values(f.errors).join(' ')}
                                </div>
                            )}

                            <div className="ops-row is-end ops-mt-3">
                                <button
                                    type="submit"
                                    className="button dark ppm-submit"
                                    disabled={f.processing}
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
