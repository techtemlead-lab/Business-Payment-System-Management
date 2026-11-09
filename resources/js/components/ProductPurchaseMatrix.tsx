import React, { useState, useMemo, useEffect, useRef } from 'react';
import { useForm, usePage, Link } from '@inertiajs/react';
import {
    Trash2, ShoppingBag, ArrowRight, CheckCircle2, RotateCcw,
    AlertCircle, Sparkles, Package, Layers, Info, Search, X
} from 'lucide-react';
import { money, type Row, Table, number, FormErrors } from './UI';
import { useT } from '../shared/i18n/t';
import { randomUuid } from '../shared/randomUuid';
import RepeatOrderPanel, { repeatChangeNotes, type RepeatOption } from './dealertools/RepeatOrderPanel';
import SchemeNotices, { type RunningScheme } from './dealertools/SchemeNotices';

interface Props {
    products: Row[];
    categories: string[];
    subCategories?: string[];
    collectionAccounts: Row[];
    initialProductId?: number | string | null;
    /** Owner setting "Full payment required when ordering": the whole total and a screenshot go with the order. */
    fullPaymentRequired?: boolean;
    /** Couriers the owner switched on, plus "company decides" (key "company"). */
    courierChoices?: { key: string; label: string }[];
    /** Past orders that can go back in the cart with one tap, at today's prices and stock. */
    repeatOrders?: RepeatOption[];
    /** Schemes running today, shown as information only. */
    runningSchemes?: RunningScheme[];
}

/** The most the server accepts on one order line. */
const MAX_ORDER_QUANTITY = 10000;

/** By Office and From My Account: the partner enters nothing, the server sets the payment details. */
const OFFICE_CHANNEL = 'Office';
const ACCOUNT_CHANNEL = 'Account';

interface PayOptions {
    account_available: string;
    account_usable: boolean;
    account_block: null | 'debt' | 'off';
}

/** Whole paisa, so a balance and a total compare exactly. */
const paisa = (amount: number): number => Math.round(amount * 100);

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
    fullPaymentRequired = false,
    courierChoices = [],
    repeatOrders = [],
    runningSchemes = [],
}: Props) {
    const translate = useT();
    const { url, props: pageProps } = usePage<any>();
    const payOptions: PayOptions = pageProps.data?.pay_options ?? { account_available: '0', account_usable: false, account_block: 'off' };
    // Opened from "order with this money" on the withdraw form: /purchase?pay=account&budget=5000
    const openedWithAccount = useMemo(() => new URLSearchParams(url.split('?')[1] ?? '').get('pay') === 'account', [url]);
    const budget = useMemo(() => {
        const planned = Number(new URLSearchParams(url.split('?')[1] ?? '').get('budget'));
        return openedWithAccount && Number.isFinite(planned) && planned > 0 ? planned : null;
    }, [url, openedWithAccount]);

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
        setAddedBanner(`${activeProduct.name}${strengthLabel} — ${number(qty)} ${activeProduct.unit || translate('portal', 'ppm_box_unit')} ${translate('portal', 'b2b_added_success')}`);
        setTimeout(() => setAddedBanner(null), 4000);

        // Intentionally keep selectedCategory, selectedSubCategory, and selectedProductName!
        // This allows dealer to immediately add another strength/variant or change quantity.
    };

    // One tap: the cart becomes this past order, at today's prices and stock.
    const [repeatNotes, setRepeatNotes] = useState<string[]>([]);
    const fillCartFromOrder = (option: RepeatOption) => {
        setCart(option.items.filter(item => item.quantity > 0).map(item => ({ product: item.product, quantity: item.quantity })));
        setRepeatNotes(repeatChangeNotes(option, translate));
        setAddedBanner(translate('dealer_tools', 'repeat_done', { number: option.number }));
        setTimeout(() => setAddedBanner(null), 4000);
    };

    // Update quantity in cart
    const updateCartQty = (productId: number, newQty: number) => {
        if (newQty <= 0) {
            removeFromCart(productId);
            return;
        }
        const quantityAllowed = Math.min(newQty, MAX_ORDER_QUANTITY);
        setCart(prev => prev.map(item => item.product.id === productId ? { ...item, quantity: quantityAllowed } : item));
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
        reference: '',
        payment_account_id: '',
        channel: openedWithAccount && payOptions.account_usable ? ACCOUNT_CHANNEL : 'Bank',
        amount: '',
        note: '',
        courier_choice: 'company',
        proof: null as File | null,
    });

    /** Company accounts that take the chosen payment method. */
    const channelAccounts = useMemo(
        () => collectionAccounts.filter(account => account.channel === f.data.channel),
        [collectionAccounts, f.data.channel],
    );
    // Only one account takes this method: pick it, so the dealer cannot miss the step.
    useEffect(() => {
        if (f.data.payment_account_id === '' && channelAccounts.length === 1) {
            f.setData('payment_account_id', String(channelAccounts[0].id));
        }
    }, [channelAccounts, f.data.payment_account_id]);

    const paysFromAccount = f.data.channel === ACCOUNT_CHANNEL;
    const paysAtOffice = f.data.channel === OFFICE_CHANNEL;
    const serverSetsPayment = paysFromAccount || paysAtOffice;
    const accountBalance = Number(payOptions.account_available);
    const accountAfter = accountBalance - cartSummary.totalPayable;
    const accountIsShort = paisa(cartSummary.totalPayable) > paisa(accountBalance);
    const budgetLeft = budget === null ? 0 : budget - cartSummary.totalPayable;

    // One key for one order: a retry of the same order is charged once, and only a changed order gets a new key.
    const retryKey = useRef<{ key: string; order: string } | null>(null);
    const orderRetryKey = (order: Record<string, unknown>): string => {
        const asText = JSON.stringify(order, (_name, value) => value instanceof File ? `${value.name}:${value.size}:${value.lastModified}` : value);
        if (retryKey.current?.order !== asText) {
            retryKey.current = { key: randomUuid(), order: asText };
        }
        return retryKey.current.key;
    };

    const submitOrder = (e: React.FormEvent) => {
        e.preventDefault();
        if (cart.length === 0) return;

        const order = {
            items: cart.map(item => ({
                product_id: item.product.id,
                quantity: item.quantity,
            })),
            channel: f.data.channel,
            note: f.data.note,
            courier_choice: courierChoices.length ? f.data.courier_choice : undefined,
            ...(serverSetsPayment ? {} : {
                reference: f.data.reference,
                payment_account_id: f.data.payment_account_id,
                amount: fullPaymentRequired ? cartSummary.totalPayable.toFixed(2) : f.data.amount,
                proof: f.data.proof,
            }),
        };
        const withKey = { ...order, idempotency_key: orderRetryKey(order) };
        f.transform(() => withKey as never);

        f.post('/commands/orders', {
            // Stay where the dealer is when something needs fixing (the page used to jump to the top).
            preserveScroll: 'errors',
            onSuccess: () => {
                retryKey.current = null;
                setCart([]);
                f.reset('reference', 'amount', 'note', 'proof');
            },
            // On a phone the problem list sits below the fold; bring it into view so the
            // dealer sees what to fix instead of a button that seems to do nothing.
            onError: () => {
                requestAnimationFrame(() => {
                    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
                    document.querySelector('.ppm-error')?.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
                });
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

            {budget !== null && (
                <div className="notice" role="status" data-testid="order-with-money">
                    <p>
                        <b>{translate('order_payment', 'account_balance')}: {money(accountBalance)}</b>
                        {' · '}
                        {budgetLeft >= 0
                            ? translate('order_payment', 'budget_line', { budget: money(budget), left: money(budgetLeft) })
                            : translate('order_payment', 'budget_over', { budget: money(budget), over: money(-budgetLeft) })}
                    </p>
                </div>
            )}

            <RepeatOrderPanel orders={repeatOrders} onFill={fillCartFromOrder} />
            <SchemeNotices schemes={runningSchemes} cartQuantities={Object.fromEntries(cart.map(item => [Number(item.product.id), item.quantity]))} />
            {repeatNotes.length > 0 && (
                <div className="notice warning" role="status">
                    <ul>{repeatNotes.map(note => <li key={note}>{note}</li>)}</ul>
                </div>
            )}

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
                                            {p.category} {p.sub_category ? `› ${p.sub_category}` : ''} · {translate('portal', 'product_code_value', { sku: String(p.sku) })}
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
                                            {specText} — {money(vPrice)} ({translate('portal', 'mrp_label', { amount: money(vMrp) })}{vDisc > 0 ? ` · ${translate('portal', 'pct_off', { pct: String(vDisc) })}` : ''})
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
                                        max={MAX_ORDER_QUANTITY}
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
                                    {/* The label already starts with "+", so no extra plus icon. */}
                                    {translate('portal', 'b2b_add_to_order')}
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
                                    title={translate('portal', 'b2b_qty_reset')}
                                    aria-label={translate('portal', 'b2b_qty_reset')}
                                >
                                    <RotateCcw size={12} aria-hidden="true" /> {number(1)}
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
                                        {translate('portal', 'product_code_value', { sku: String(activeProduct.sku) })} · {activeProduct.category}{activeProduct.sub_category ? ` / ${activeProduct.sub_category}` : ''}
                                    </span>
                                </div>
                                <span className={`hub-pill ${activeProduct.stock > 0 && !activeProduct.is_stock_paused ? 'tone-ok' : 'tone-danger'}`}>
                                    {activeProduct.stock > 0 && !activeProduct.is_stock_paused
                                        ? translate('portal', 'ppm_stock_left', { qty: number(activeProduct.stock), unit: String(activeProduct.unit || translate('portal', 'ppm_unit_fallback')) })
                                        : translate('portal', 'ppm_stock_awaiting')}
                                </span>
                            </div>

                            <div className="ops-row is-wide is-wrap">
                                <div>
                                    <small className="ppm-sel-label ops-muted">{translate('portal', 'ppm_mrp_per_box')}</small>
                                    <span className={`ops-fs-xs ops-muted${hasDiscount ? ' ppm-struck' : ''}`}>
                                        {money(mrp)}
                                    </span>
                                </div>

                                {hasDiscount && (
                                    <div>
                                        <small className="ppm-sel-label">{translate('portal', 'ppm_commission_discount')}</small>
                                        <span className="ppm-save">
                                            {translate('portal', 'ppm_discount_saving', { pct: String(discountPct), amount: money(unitSavings) })}
                                        </span>
                                    </div>
                                )}

                                <div>
                                    <small className="ppm-sel-label">{translate('portal', 'ppm_price_per_box')}</small>
                                    <strong className="ppm-sel-price">
                                        {money(unitPrice)}
                                    </strong>
                                </div>

                                <div className="ppm-sel-total">
                                    <small className="ppm-sel-label">{translate('portal', 'ppm_line_total', { qty: String(quantity) })}</small>
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
                                ? translate('portal', 'ppm_cart_count', { count: String(cart.length) })
                                : translate('portal', 'ppm_cart_empty_hint')}
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
                                            <td className="ppm-line">
                                                <div className="ppm-line-name">
                                                    {p.name}
                                                </div>
                                                <div className="cell-sub">
                                                    {p.strength ? <strong className="ppm-line-strength">{p.strength}</strong> : null}
                                                    <span>{p.category}{p.sub_category ? ` · ${p.sub_category}` : ''}</span>
                                                    <span className="ppm-line-unit">({p.unit || translate('portal', 'ppm_box_unit')})</span>
                                                </div>
                                            </td>
                                            <td className="ppm-mrp ops-right" data-label={translate('portal', 'b2b_col_mrp')}>
                                                {money(itemMrp)}
                                            </td>
                                            <td className="ppm-discount ops-center" data-label={translate('portal', 'b2b_col_discount_pct')}>
                                                {lineDiscount > 0 ? (
                                                    <span className="hub-pill tone-ok">
                                                        {translate('portal', 'pct_off', { pct: String(lineDiscount) })}
                                                    </span>
                                                ) : (
                                                    <span className="ops-faint ops-fs-2xs">—</span>
                                                )}
                                            </td>
                                            <td className="ppm-unit ops-right" data-label={translate('portal', 'b2b_col_unit_price')}>
                                                {money(itemPrice)}
                                            </td>
                                            <td className="ppm-qty ops-center" data-label={translate('portal', 'b2b_col_qty')}>
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
                                                        max={MAX_ORDER_QUANTITY}
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
                                            <td className="ppm-total ops-right" data-label={translate('portal', 'b2b_col_total')}>
                                                {money(lineTotal)}
                                            </td>
                                            <td className="ppm-remove ops-center">
                                                <button
                                                    type="button"
                                                    onClick={() => removeFromCart(p.id)}
                                                    className="ppm-icon-btn is-danger"
                                                    title={translate('portal', 'cart_remove_item')}
                                                    aria-label={translate('portal', 'cart_remove_item')}
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
                        <p className="ops-semibold">{translate('portal', 'ppm_cart_empty_title')}</p>
                        <small className="ops-muted">{translate('portal', 'ppm_cart_empty_body')}</small>
                    </div>
                )}

                {/* Totals & Checkout Summary Form */}
                {cart.length > 0 && (
                    <div className="ppm-checkout">
                        {/* Summary Metrics Bar */}
                        <div className="ppm-summary">
                            <div>
                                <small className="ppm-sum-label">{translate('portal', 'ppm_items_and_units')}</small>
                                <strong className="ppm-sum-value">
                                    {translate('portal', 'ppm_items_boxes', { items: String(cartSummary.itemsCount), qty: String(cartSummary.totalQty) })}
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
                                    {translate('portal', 'ppm_total_saving', { amount: money(cartSummary.totalSavings), pct: String(cartSummary.overallDiscountPct) })}
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
                                {translate('portal', 'ppm_confirm_title')}
                            </h4>

                            {fullPaymentRequired && !serverSetsPayment && (
                                <div className="notice" role="note">
                                    <Info size={18} className="ops-shrink-0" />
                                    <p>{translate('portal', 'ppm_full_payment_notice', { amount: money(cartSummary.totalPayable) })}</p>
                                </div>
                            )}

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
                                        {['Bank', 'bKash', 'Nagad', 'Rocket', OFFICE_CHANNEL].map(x => (
                                            <option key={x} value={x}>{translate('portal', `pay_channel_${x.toLowerCase()}`)}</option>
                                        ))}
                                        {payOptions.account_block !== 'off' && (
                                            <option value={ACCOUNT_CHANNEL} disabled={!payOptions.account_usable}>{translate('portal', 'pay_channel_account')}</option>
                                        )}
                                    </select>
                                    {paysAtOffice && <small className="field-hint">{translate('order_payment', 'method_office_hint')}</small>}
                                    {paysFromAccount && <small className="field-hint">{translate('order_payment', 'method_account_hint')}</small>}
                                    {payOptions.account_block === 'debt' && <small className="field-hint is-warn" role="status">{translate('order_payment', 'account_block_debt')}</small>}
                                </label>

                                {!serverSetsPayment && <>
                                <label>
                                    {translate('portal', 'collection_account')}
                                    <select
                                        value={f.data.payment_account_id}
                                        onChange={e => f.setData('payment_account_id', e.target.value)}
                                    >
                                        <option value="">{translate('portal', 'select_collection_account')}</option>
                                        {channelAccounts.map(a => (
                                            <option key={a.id} value={a.id}>
                                                {a.holder} · {a.account_number}
                                            </option>
                                        ))}
                                    </select>
                                    {channelAccounts.length === 0 && (
                                        <small className="field-hint is-warn" role="status">{translate('portal', 'ppm_no_collection_account')}</small>
                                    )}
                                    {f.errors.payment_account_id && <span className="field-error" role="alert">{f.errors.payment_account_id}</span>}
                                </label>

                                <label>
                                    {translate('portal', 'transaction_reference')}
                                    <input
                                        placeholder={translate('portal', 'transaction_reference_placeholder')}
                                        required={fullPaymentRequired}
                                        value={f.data.reference}
                                        onChange={e => f.setData('reference', e.target.value)}
                                    />
                                </label>

                                {fullPaymentRequired ? (
                                    <label>
                                        {translate('portal', 'ppm_full_amount_label')}
                                        <input
                                            type="text"
                                            readOnly
                                            aria-readonly="true"
                                            value={money(cartSummary.totalPayable)}
                                        />
                                        <small className="field-hint">{translate('portal', 'ppm_full_amount_hint')}</small>
                                    </label>
                                ) : (
                                    <label>
                                        {translate('portal', 'claimed_payment_amount')} ({translate('portal', 'optional_label')})
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder={money(cartSummary.totalPayable)}
                                            value={f.data.amount}
                                            onChange={e => f.setData('amount', e.target.value)}
                                        />
                                    </label>
                                )}

                                <label>
                                    {translate('portal', 'payment_proof_label')}
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        required={fullPaymentRequired || f.data.reference.trim() !== ''}
                                        onChange={e => f.setData('proof', e.target.files?.[0] ?? null)}
                                    />
                                    <small className="field-hint">{translate('portal', 'payment_proof_hint')}</small>
                                    {f.errors.proof && <span className="field-error" role="alert">{f.errors.proof}</span>}
                                </label>
                                </>}
                            </div>

                            {paysFromAccount && (
                                <div className="ppm-summary" data-testid="account-pay-summary">
                                    <div>
                                        <small className="ppm-sum-label">{translate('order_payment', 'account_balance')}</small>
                                        <strong className="ppm-sum-value">{money(accountBalance)}</strong>
                                    </div>
                                    <div>
                                        <small className="ppm-sum-label">{translate('order_payment', 'account_cut')}</small>
                                        <strong className="ppm-sum-value">{money(cartSummary.totalPayable)}</strong>
                                    </div>
                                    <div className="ppm-sum-net">
                                        <small className="ppm-sum-label">{translate('order_payment', 'account_after')}</small>
                                        <strong className="ppm-sum-value is-net">{money(Math.max(0, accountAfter))}</strong>
                                    </div>
                                </div>
                            )}
                            {paysFromAccount && accountIsShort && (
                                <p className="field-error" role="alert" data-testid="account-short">
                                    {translate('order_payment', 'account_short', { amount: money(-accountAfter) })}
                                </p>
                            )}

                            {courierChoices.length > 0 && (
                                <label>
                                    {translate('courier', 'order_form_label')}
                                    <select
                                        value={f.data.courier_choice}
                                        onChange={e => f.setData('courier_choice', e.target.value)}
                                    >
                                        {courierChoices.map(choice => (
                                            <option key={choice.key} value={choice.key}>{choice.label}</option>
                                        ))}
                                    </select>
                                    <small className="field-hint">{translate('courier', 'order_form_hint')}</small>
                                    {f.errors.courier_choice && <span className="field-error" role="alert">{f.errors.courier_choice}</span>}
                                </label>
                            )}

                            <label>
                                {translate('portal', 'note')}
                                <textarea
                                    rows={2}
                                    placeholder={translate('portal', 'order_note_placeholder')}
                                    value={f.data.note}
                                    onChange={e => f.setData('note', e.target.value)}
                                />
                            </label>

                            {/* FormErrors also keeps the page-top message quiet, so errors are not shown twice. */}
                            <FormErrors errors={f.errors} className="error ppm-error" besideFields={['payment_account_id', 'proof', 'courier_choice']} />

                            <div className="ops-row is-end ops-mt-3">
                                <button
                                    type="submit"
                                    className="button dark ppm-submit"
                                    disabled={f.processing || (paysFromAccount && accountIsShort)}
                                >
                                    {f.processing
                                        ? translate('portal', 'submitting')
                                        : paysFromAccount
                                            ? translate('order_payment', 'account_submit', { amount: money(cartSummary.totalPayable) })
                                            : translate('portal', 'submit_purchase_request')}
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
