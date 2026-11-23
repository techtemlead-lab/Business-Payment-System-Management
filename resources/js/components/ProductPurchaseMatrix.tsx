import React, { useState, useMemo, useEffect, useRef } from 'react';
import { router, useForm, usePage, Link } from '@inertiajs/react';
import {
    Trash2, ShoppingBag, ArrowRight, CheckCircle2, RotateCcw,
    AlertCircle, Sparkles, Package, Info, Search, X
} from 'lucide-react';
import { money, type Row, Table, number, FormErrors, Modal } from './UI';
import { useT } from '../shared/i18n/t';
import { catalogWord } from '../shared/catalogWords';
import FilePicker from './FilePicker';
import { takaWords } from '../shared/takaText';
import { useReasonField } from '../shared/reasonFields';
import { randomUuid } from '../shared/randomUuid';
import RepeatOrderPanel, { repeatChangeNotes, type RepeatOption } from './dealertools/RepeatOrderPanel';
import SchemeNotices, { type RunningScheme } from './dealertools/SchemeNotices';
import { choiceName, oneCardPerGroup, sizesByGroup, type SizeCard } from '../shared/productSizes';
import { amountInWords } from '../shared/orderAmountWords';
import { useOrderCart } from '../shared/usePartnerCart';
import { useIsPhone } from '../shared/useIsPhone';
import { FilterDropdown } from './FilterDropdown';
import { takeReorderNotes } from '../shared/reorderOrder';
import { cardStock, filterChoices, partnerPrice, priceRange, searchProducts, stockKnown, stockState } from '../shared/orderSearch';

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

/** From this total (taka) the confirm step adds a plain "check the amount" reminder. */
const BIG_ORDER_TAKA = 1000000;

/** Most products listed under the search box at once. */
const RESULT_LIMIT = 30;

/** By Office and Account balance: the partner enters nothing, the server sets the payment details. */
const OFFICE_CHANNEL = 'Office';
const ACCOUNT_CHANNEL = 'Account';
/** Paid by sending money to a company account, so each needs one set up by the owner. */
const MOBILE_CHANNELS = ['Bank', 'bKash', 'Nagad', 'Rocket'];

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
    const unitWord = (value: unknown) => catalogWord(translate, 'unit', value as string | null | undefined);
    const categoryWord = (value: unknown) => catalogWord(translate, 'category', value as string | null | undefined);
    const { url, props: pageProps } = usePage<any>();
    const payOptions: PayOptions = pageProps.data?.pay_options ?? { account_available: '0', account_usable: false, account_block: 'off' };
    const page = (key: string, replace?: Record<string, string | number>) => translate('purchase_page', key, replace);
    // Units free to promise now (stock minus what approved orders already hold), by product id; a warning only, never a block.
    const availableMap: Record<string, number> = pageProps.data?.available_now ?? {};
    const availableOf = (product: Row | null | undefined): number | null => {
        const known = product ? availableMap[String(product.id)] : undefined;
        return known === undefined || known === null ? null : Number(known);
    };
    // Opened from "order with this money" on the withdraw form: /purchase?pay=account&budget=5000
    const openedWithAccount = useMemo(() => new URLSearchParams(url.split('?')[1] ?? '').get('pay') === 'account', [url]);
    // /purchase?pay=office (the big "pay at the office" button on the home screen) starts with that method chosen.
    const openedAtOffice = useMemo(() => new URLSearchParams(url.split('?')[1] ?? '').get('pay') === 'office', [url]);
    const budget = useMemo(() => {
        const planned = Number(new URLSearchParams(url.split('?')[1] ?? '').get('budget'));
        return openedWithAccount && Number.isFinite(planned) && planned > 0 ? planned : null;
    }, [url, openedWithAccount]);

    // Search-first ordering: one box over every product the partner may order. Categories are only
    // optional filters. The picked card shows its sizes (if several), then the quantity.
    const [pickedKey, setPickedKey] = useState<string>('');
    const [activeId, setActiveId] = useState<number | string>('');
    const [quantity, setQuantity] = useState<number>(1);
    const [addedBanner, setAddedBanner] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [listOpen, setListOpen] = useState<boolean>(false);
    const [highlight, setHighlight] = useState<number>(0);
    const [filterCategory, setFilterCategory] = useState<string>('');
    const [filterSub, setFilterSub] = useState<string>('');
    const searchRef = useRef<HTMLInputElement>(null);
    const quantityRef = useRef<HTMLInputElement>(null);
    const sizesRef = useRef<HTMLFieldSetElement>(null);

    // Sizes of one product are one card; the size step comes after the product is found.
    const sizeGroups = useMemo(() => sizesByGroup(products), [products]);

    const filterOptions = useMemo(() => filterChoices(products, filterCategory), [products, filterCategory]);
    const availableCategories = filterOptions.categories.length ? filterOptions.categories : categories;
    const searchResults = useMemo(
        () => searchProducts(products, searchQuery, sizeGroups, { category: filterCategory, subCategory: filterSub }, RESULT_LIMIT),
        [products, searchQuery, sizeGroups, filterCategory, filterSub],
    );
    const hasSearch = searchQuery.trim() !== '' || filterCategory !== '';
    const listVisible = listOpen && hasSearch;

    // The card the dealer picked, and the size on it.
    const pickedCard = useMemo<SizeCard | null>(() => {
        if (!pickedKey) return null;
        const product = products.find(p => (p.size_group_id && (sizeGroups.get(p.size_group_id)?.length ?? 0) > 1 ? `g${p.size_group_id}` : `p${p.id}`) === pickedKey);
        if (!product) return null;
        const sizes = product.size_group_id && (sizeGroups.get(product.size_group_id)?.length ?? 0) > 1 ? (sizeGroups.get(product.size_group_id) ?? [product]) : [product];
        return { key: pickedKey, sizes, first: sizes[0] };
    }, [pickedKey, products, sizeGroups]);
    const availableVariants = pickedCard?.sizes ?? [];
    const activeProduct = useMemo(
        () => availableVariants.find(p => String(p.id) === String(activeId)) ?? availableVariants[0] ?? null,
        [availableVariants, activeId],
    );

    // Multi-item cart
    // The same cart as the cart icon and the products page: one list, kept in the browser until the order is sent.
    const [cart, setCart] = useOrderCart(products);

    /** Picks a card: one size goes straight to quantity; several sizes ask for the size first. */
    const pickCard = (card: SizeCard) => {
        setPickedKey(card.key);
        setActiveId(card.first.id);
        setQuantity(1);
        setSearchQuery('');
        setListOpen(false);
        setHighlight(0);
        window.setTimeout(() => {
            if (card.sizes.length > 1) {
                sizesRef.current?.querySelector<HTMLInputElement>('input:checked')?.focus();
            } else {
                quantityRef.current?.focus();
                quantityRef.current?.select();
            }
        }, 0);
    };

    // Opened with ?product_id=: that product is ready for quantity. Once only: a refresh of the figures must not pick it again.
    const initialApplied = useRef(false);
    useEffect(() => {
        if (!initialProductId || initialApplied.current) return;
        const found = products.find(p => String(p.id) === String(initialProductId));
        if (found) {
            initialApplied.current = true;
            const card = oneCardPerGroup([found], sizeGroups)[0];
            setPickedKey(card.key);
            setActiveId(found.id);
        }
    }, [initialProductId, products]);

    const onSearchKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Escape') {
            setListOpen(false);
            return;
        }
        if (!searchResults.length) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setListOpen(true);
            setHighlight(index => Math.min(index + 1, searchResults.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlight(index => Math.max(index - 1, 0));
        } else if (event.key === 'Enter' && listVisible) {
            event.preventDefault();
            pickCard(searchResults[Math.min(highlight, searchResults.length - 1)]);
        }
    };

    // Add current selection to cart
    const handleAddToCart = (e: React.FormEvent) => {
        e.preventDefault();
        if (!activeProduct || stockState(activeProduct) !== 'in') return;
        const qty = Math.min(MAX_ORDER_QUANTITY, Math.max(1, Number(quantity) || 1));

        setCart(prev => {
            const existingIndex = prev.findIndex(item => item.product.id === activeProduct.id);
            if (existingIndex > -1) {
                const next = [...prev];
                next[existingIndex] = {
                    ...next[existingIndex],
                    quantity: Math.min(MAX_ORDER_QUANTITY, next[existingIndex].quantity + qty),
                };
                return next;
            }
            return [...prev, { product: activeProduct, quantity: qty }];
        });

        const sizeText = activeProduct.size_label || activeProduct.strength;
        const strengthLabel = sizeText ? ` (${sizeText})` : '';
        setAddedBanner(`${activeProduct.name}${strengthLabel} — ${number(qty)} ${unitWord(activeProduct.unit) || translate('portal', 'ppm_box_unit')} ${translate('portal', 'b2b_added_success')}`);
        setTimeout(() => setAddedBanner(null), 4000);

        // The box is ready for the next product at once.
        setPickedKey('');
        setActiveId('');
        setQuantity(1);
        setSearchQuery('');
        searchRef.current?.focus();
    };

    // One tap: the cart becomes this past order, at today's prices.
    // Notes left by "Order this again" on the My orders page (skipped products) are shown once here.
    const [repeatNotes, setRepeatNotes] = useState<string[]>(() => takeReorderNotes());
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
    // Back-order warning for the picked product: what is already in the cart plus what is typed, against what is free now.
    const activeAvailable = availableOf(activeProduct);
    const activeWanted = (cart.find(item => item.product.id === activeProduct?.id)?.quantity ?? 0) + Math.max(1, Number(quantity) || 1);
    const activeOver = activeAvailable === null ? 0 : Math.max(0, activeWanted - activeAvailable);

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

    // Bank, bKash, Nagad and Rocket are always listed. One with no company account behind it yet shows as
    // "not set up yet" and cannot be picked; paying at the office always can.
    const payChannels = useMemo(
        () => [...MOBILE_CHANNELS.filter(x => collectionAccounts.some(account => account.channel === x)), OFFICE_CHANNEL],
        [collectionAccounts],
    );

    // The account balance can change while this page stays open (a payment is verified, a withdrawal is paid):
    // read it again whenever the person comes back to the page, and keep the cart as it is.
    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['data'], headers: { 'X-Background-Refresh': '1' } });
            }
        };
        document.addEventListener('visibilitychange', refresh);

        return () => document.removeEventListener('visibilitychange', refresh);
    }, []);

    // Checkout form
    const f = useForm({
        items: [] as { product_id: number; quantity: number }[],
        reference: '',
        payment_account_id: '',
        channel: openedWithAccount && payOptions.account_usable && Number(payOptions.account_available) > 0 ? ACCOUNT_CHANNEL : openedAtOffice ? OFFICE_CHANNEL : payChannels[0],
        amount: '',
        note: '',
        courier_choice: 'company',
        // 'courier', or 'office' when the partner collects the goods at the office (no courier part then).
        delivery_method: 'courier' as 'courier' | 'office',
        proof: null as File | null,
    });
    const collectsAtOffice = f.data.delivery_method === 'office';
    const orderNote = useReasonField('order.note');

    // "Account balance" short of the order total: the balance pays its part and this second method pays the rest.
    const [restChannel, setRestChannel] = useState<string>(payChannels[0]);
    const accountChosen = f.data.channel === ACCOUNT_CHANNEL;
    /** The method that moves money outside the account: the rest method of a split, else the chosen one. */
    const moneyChannel = accountChosen ? restChannel : f.data.channel;

    /** Company accounts that take the chosen payment method. */
    const channelAccounts = useMemo(
        () => collectionAccounts.filter(account => account.channel === moneyChannel),
        [collectionAccounts, moneyChannel],
    );
    // Only one account takes this method: pick it, so the dealer cannot miss the step.
    useEffect(() => {
        if (f.data.payment_account_id === '' && channelAccounts.length === 1) {
            f.setData('payment_account_id', String(channelAccounts[0].id));
        }
    }, [channelAccounts, f.data.payment_account_id]);

    const accountBalance = Number(payOptions.account_available);
    // The wallet is always listed; it can be picked only when it can be spent and holds something.
    const accountOffered = payOptions.account_usable && accountBalance > 0;
    const accountOptionNote = accountOffered ? '' : translate('order_payment', payOptions.account_block === 'off'
        ? 'wallet_option_off'
        : payOptions.account_block === 'debt' ? 'wallet_option_debt' : 'wallet_option_empty');
    // The most the wallet can pay: all it holds, or the whole order when it holds more.
    const walletMax = Math.min(paisa(accountBalance), paisa(cartSummary.totalPayable)) / 100;
    // The partner may use less than the most; until they type a figure the most is used.
    const [walletPartText, setWalletPartText] = useState<string | null>(null);
    const walletPart = walletPartText === null ? walletMax : Number(walletPartText);
    const walletPartProblem = !accountChosen || walletPartText === null ? null
        : !Number.isFinite(walletPart) || paisa(walletPart) <= 0 ? translate('order_payment', 'wallet_part_too_little')
        : paisa(walletPart) > paisa(walletMax) ? translate('order_payment', 'wallet_part_too_much', { max: money(walletMax) })
        : null;
    /** The server's word on the wallet part (for example the balance dropped meanwhile). */
    const walletPartError = (f.errors as Record<string, string | undefined>).account_amount;
    const accountAfter = accountBalance - walletPart;
    // The wallet pays less than the order (it holds less, or the partner chose less): a second method pays the rest.
    const splitOn = accountChosen && accountOffered && paisa(walletPart) < paisa(cartSummary.totalPayable);
    const paysFromAccount = accountChosen && !splitOn;
    const paysAtOffice = moneyChannel === OFFICE_CHANNEL;
    const serverSetsPayment = paysFromAccount || paysAtOffice;
    const splitRest = (paisa(cartSummary.totalPayable) - paisa(walletPart)) / 100;
    const budgetLeft = budget === null ? 0 : budget - cartSummary.totalPayable;

    // The balance can drop to nothing while this page stays open: step back to a normal method, not a dead choice.
    useEffect(() => {
        if (f.data.channel === ACCOUNT_CHANNEL && !accountOffered) {
            f.setData('channel', payChannels[0]);
        }
    }, [accountOffered, f.data.channel, payChannels]);
    useEffect(() => {
        if (!payChannels.includes(restChannel)) {
            setRestChannel(payChannels[0]);
        }
    }, [payChannels, restChannel]);

    // One key for one order: a retry of the same order is charged once, and only a changed order gets a new key.
    const retryKey = useRef<{ key: string; order: string } | null>(null);
    const orderRetryKey = (order: Record<string, unknown>): string => {
        const asText = JSON.stringify(order, (_name, value) => value instanceof File ? `${value.name}:${value.size}:${value.lastModified}` : value);
        if (retryKey.current?.order !== asText) {
            retryKey.current = { key: randomUuid(), order: asText };
        }
        return retryKey.current.key;
    };

    // The confirm step: refresh the account figure first, so the split shown is the one the server will use.
    // R2: emptying the whole cart asks first, so one slip of the finger does not lose a long order.
    const [clearAsk, setClearAsk] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const isPhone = useIsPhone();
    const [confirm, setConfirm] = useState<null | { before: number; status: 'checking' | 'ready' | 'failed' }>(null);
    const askToConfirm = (e: React.FormEvent) => {
        e.preventDefault();
        if (cart.length === 0 || walletPartProblem !== null) return;
        setConfirm({ before: accountBalance, status: 'checking' });
        let refreshed = false;
        router.reload({
            only: ['data'],
            onSuccess: () => { refreshed = true; },
            onFinish: () => setConfirm(current => current ? { ...current, status: refreshed ? 'ready' : 'failed' } : current),
        });
    };

    const sendOrder = () => {
        if (cart.length === 0) return;
        setConfirm(null);

        const order = {
            items: cart.map(item => ({
                product_id: item.product.id,
                quantity: item.quantity,
            })),
            channel: paysFromAccount ? ACCOUNT_CHANNEL : moneyChannel,
            // The wallet part is sent as shown, so the server takes exactly that or refuses.
            ...(splitOn ? { use_account: true, account_amount: walletPart.toFixed(2) } : {}),
            note: orderNote.shown ? f.data.note : undefined,
            delivery_method: f.data.delivery_method,
            // Collected at the office: nothing about a courier is sent.
            courier_choice: !collectsAtOffice && courierChoices.length ? f.data.courier_choice : undefined,
            ...(serverSetsPayment ? {} : {
                reference: f.data.reference,
                payment_account_id: f.data.payment_account_id,
                amount: splitOn ? splitRest.toFixed(2) : fullPaymentRequired ? cartSummary.totalPayable.toFixed(2) : f.data.amount,
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
                {/* The product list page is switched off for partners (CR-01); the link returns with it. */}
                {pageProps.dashboardControls?.partner_catalog_off !== true && (
                    <Link
                        href="/catalog"
                        className="button small ppm-hero-link"
                    >
                        <Package size={15} /> {translate('portal', 'b2b_catalog_list_title')} ↗
                    </Link>
                )}
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

            {/* On a phone the running schemes fold under one row, so the order form comes first. */}
            <div className={`ppm-more${moreOpen ? ' is-open' : ''}`}>
                {isPhone && runningSchemes.length > 0 && (
                    <button type="button" className="button ppm-more-toggle" aria-expanded={moreOpen} onClick={() => setMoreOpen(open => !open)}>
                        {page(moreOpen ? 'mobile_more_hide' : 'mobile_more_show')}
                    </button>
                )}
                <div className="ppm-more-body">
                    {/* The last orders show at once on a computer; a phone keeps the order form uncluttered, so no card there. */}
                    {!isPhone && <RepeatOrderPanel orders={repeatOrders} onFill={fillCartFromOrder} />}
                    <SchemeNotices schemes={runningSchemes} cartQuantities={Object.fromEntries(cart.map(item => [Number(item.product.id), item.quantity]))} />
                </div>
            </div>
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

            {/* Search-first order builder: one box over every product, no category step. */}
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

                <div
                    className="ppm-search"
                    onBlur={event => {
                        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setListOpen(false);
                    }}
                >
                    <label className="ops-field-label" htmlFor="ppm-search-input">{translate('order_search', 'search_label')}</label>
                    <div className="ppm-search-box">
                        <Search size={16} className="ops-shrink-0" aria-hidden="true" />
                        <input
                            id="ppm-search-input"
                            ref={searchRef}
                            type="search"
                            role="combobox"
                            aria-expanded={listVisible}
                            aria-controls="ppm-results"
                            aria-autocomplete="list"
                            aria-activedescendant={listVisible && searchResults.length ? `ppm-opt-${Math.min(highlight, searchResults.length - 1)}` : undefined}
                            autoComplete="off"
                            autoCapitalize="none"
                            enterKeyHint="go"
                            placeholder={translate('order_search', 'search_placeholder')}
                            value={searchQuery}
                            onFocus={() => setListOpen(true)}
                            onChange={e => {
                                setSearchQuery(e.target.value);
                                setListOpen(true);
                                setHighlight(0);
                                // Typing searches every product: a chip clicked earlier must not narrow it without being seen.
                                if (e.target.value.trim() !== '' && filterCategory !== '') {
                                    setFilterCategory('');
                                    setFilterSub('');
                                }
                            }}
                            onKeyDown={onSearchKeyDown}
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => { setSearchQuery(''); searchRef.current?.focus(); }}
                                className="ppm-icon-btn"
                                title={translate('order_search', 'clear')}
                                aria-label={translate('order_search', 'clear')}
                            >
                                <X size={14} />
                            </button>
                        )}
                    </div>
                    {!hasSearch && <small className="field-hint">{translate('order_search', 'search_hint')}</small>}

                    {listVisible && (
                        <div className="ppm-results" id="ppm-results" role="listbox" aria-label={translate('order_search', 'listbox_label')}>
                            {searchResults.length === 0 && (
                                <p className="ppm-result-empty" role="status">
                                    {searchQuery.trim()
                                        ? translate('order_search', 'no_matches', { query: searchQuery.trim() })
                                        : translate('order_search', 'no_matches_filter')}
                                </p>
                            )}
                            {searchResults.map((card, index) => {
                                const product = card.first;
                                const several = card.sizes.length > 1;
                                const state = cardStock(card);
                                const range = priceRange(card);
                                return (
                                    <div
                                        key={card.key}
                                        id={`ppm-opt-${index}`}
                                        role="option"
                                        aria-selected={index === Math.min(highlight, searchResults.length - 1)}
                                        onMouseDown={event => event.preventDefault()}
                                        onClick={() => pickCard(card)}
                                        onMouseEnter={() => setHighlight(index)}
                                        className={`ppm-result${index === Math.min(highlight, searchResults.length - 1) ? ' is-active' : ''}${state === 'in' ? '' : ' is-off'}`}
                                    >
                                        <div>
                                            <strong className="hub-strong ops-fs-sm">{choiceName(product, sizeGroups)}</strong>
                                            {several
                                                ? <span className="ppm-strength">{card.sizes.map(size => size.size_label || size.strength).filter(Boolean).join(' · ') || translate('order_search', 'sizes_count', { count: String(card.sizes.length) })}</span>
                                                : (product.size_label || product.strength) && <span className="ppm-strength">({product.size_label || product.strength})</span>}
                                            {product.group ? <div className="ops-meta ppm-group">{String(product.group)}</div> : null}
                                            <div className="ops-meta">
                                                {categoryWord(product.category)} {product.sub_category ? `› ${product.sub_category}` : ''}
                                            </div>
                                        </div>
                                        <div className="ops-right">
                                            <div className="ppm-price">
                                                {several && range.low !== range.high
                                                    ? translate('order_search', 'price_range', { low: money(range.low), high: money(range.high) })
                                                    : money(range.low)}
                                            </div>
                                            {stockKnown(product) && (
                                                <span className={`hub-pill ${state === 'in' ? 'tone-ok' : 'tone-danger'}`}>
                                                    {translate('order_search', `stock_${state}`)}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                            {searchResults.length >= RESULT_LIMIT && (
                                <p className="ppm-result-empty">{translate('order_search', 'more_hint', { count: String(RESULT_LIMIT) })}</p>
                            )}
                        </div>
                    )}
                </div>

                {/* Optional filters: they only narrow the list, ordering never needs them. Two drop-downs, not a strip of chips. */}
                {availableCategories.length > 1 && (
                    <div className="ppm-filters" role="group" aria-label={translate('order_search', 'filters_label')}>
                        <FilterDropdown
                            label={translate('order_search', 'filters_label')}
                            allLabel={translate('order_search', 'filter_all')}
                            value={filterCategory}
                            options={availableCategories.map(category => ({ value: category, label: categoryWord(category) }))}
                            onChange={category => {
                                setFilterCategory(category);
                                setFilterSub('');
                                if (category !== '') {
                                    setListOpen(true);
                                    searchRef.current?.focus();
                                }
                            }}
                        />
                        {filterCategory !== '' && filterOptions.subCategories.length > 1 && (
                            <FilterDropdown
                                label={translate('order_search', 'filter_sub_label')}
                                allLabel={translate('order_search', 'filter_all')}
                                value={filterSub}
                                options={filterOptions.subCategories.map(sub => ({ value: sub, label: sub }))}
                                onChange={sub => { setFilterSub(sub); setListOpen(true); searchRef.current?.focus(); }}
                            />
                        )}
                    </div>
                )}
                {searchQuery.trim() !== '' && filterCategory !== '' && (
                    <p className="field-hint" role="status">
                        {translate('partner_ux', 'search_only_in', { category: categoryWord(filterCategory) })}{' '}
                        <button type="button" className="text-link" onClick={() => { setFilterCategory(''); setFilterSub(''); }}>{translate('partner_ux', 'search_all')}</button>
                    </p>
                )}
                {!pickedCard && <p className="ppm-pick-prompt">{translate('order_search', 'pick_prompt')}</p>}

                {pickedCard && activeProduct && (
                    <form onSubmit={handleAddToCart} className="ppm-pick">
                        <div className="ppm-pick-name">{choiceName(pickedCard.first, sizeGroups)}</div>
                        {activeProduct.group ? <div className="ops-meta ppm-group">{String(activeProduct.group)}</div> : null}

                        {availableVariants.length > 1 && (
                            <fieldset className="ppm-sizes" ref={sizesRef}>
                                <legend className="ops-field-label">{translate('order_search', 'choose_size')}</legend>
                                {availableVariants.map(v => {
                                    const vMrp = Number(v.mrp || 0);
                                    const vPrice = partnerPrice(v);
                                    const vDisc = vMrp > vPrice ? Math.round(((vMrp - vPrice) / vMrp) * 100) : 0;
                                    const state = stockState(v);
                                    return (
                                        <label key={v.id} className={`ppm-size${String(v.id) === String(activeProduct.id) ? ' is-on' : ''}${state === 'in' ? '' : ' is-off'}`}>
                                            <input
                                                type="radio"
                                                name="ppm-size"
                                                value={v.id}
                                                checked={String(v.id) === String(activeProduct.id)}
                                                onChange={() => setActiveId(v.id)}
                                            />
                                            <span className="ppm-size-label">{v.size_label || v.strength || unitWord(v.unit) || translate('portal', 'b2b_single_spec')}</span>
                                            <span className="ppm-size-price">{money(vPrice)}{vDisc > 0 ? ` · ${translate('portal', 'pct_off', { pct: String(vDisc) })}` : ''}</span>
                                            {stockKnown(v) && <span className="ppm-size-stock">{translate('order_search', `stock_${state}`)}</span>}
                                        </label>
                                    );
                                })}
                            </fieldset>
                        )}

                        <div className="ppm-qty-row">
                            <div className="ops-grow">
                                <label className="ops-field-label" htmlFor="ppm-qty-input">
                                    {translate('order_search', 'quantity_for')} {activeProduct?.unit ? `(${unitWord(activeProduct.unit)})` : ''}
                                </label>
                                <input
                                    id="ppm-qty-input"
                                    ref={quantityRef}
                                    type="number"
                                    inputMode="numeric"
                                    min="1"
                                    max={MAX_ORDER_QUANTITY}
                                    required
                                    value={quantity}
                                    onChange={e => setQuantity(Math.min(MAX_ORDER_QUANTITY, Math.max(1, parseInt(e.target.value, 10) || 1)))}
                                    className="ppm-control is-qty"
                                />
                            </div>
                            <button
                                type="submit"
                                className="button dark ppm-add"
                                disabled={stockState(activeProduct) !== 'in'}
                            >
                                {/* The label already starts with "+", so no extra plus icon. */}
                                {translate('portal', 'b2b_add_to_order')}
                            </button>
                        </div>
                        {stockState(activeProduct) !== 'in' && (
                            <p className="field-hint is-warn" role="status">{translate('order_search', `blocked_${stockState(activeProduct)}`)}</p>
                        )}
                        {activeOver > 0 && activeAvailable !== null && (
                            <p className="field-hint is-warn" role="status" data-testid="back-order-warning">
                                {page('over_warning', { qty: number(activeWanted), available: number(activeAvailable), extra: number(activeOver) })}
                            </p>
                        )}
                        <div className="ops-row is-wrap is-tight">
                            <span className="ops-meta ops-semibold">{translate('portal', 'b2b_quick_qty')}:</span>
                            {[5, 10, 20, 50, 100].map(chip => (
                                <button
                                    key={chip}
                                    type="button"
                                    onClick={() => setQuantity(q => Math.min(MAX_ORDER_QUANTITY, q + chip))}
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

                        {/* Active Selection Details Strip */}
                        <div className="ppm-selection">
                            <div className="ops-row is-loose is-wrap">
                                <div className="ppm-sel-name">
                                    {activeProduct.name} {(activeProduct.size_label || activeProduct.strength) ? `(${activeProduct.size_label || activeProduct.strength})` : ''}
                                    <span className="ppm-sel-sku">
                                        {translate('portal', 'product_code_value', { sku: String(activeProduct.sku) })} · {categoryWord(activeProduct.category)}{activeProduct.sub_category ? ` / ${activeProduct.sub_category}` : ''}
                                    </span>
                                </div>
                                {activeAvailable !== null && (
                                    <span className={`hub-pill ${activeAvailable > 0 ? 'tone-ok' : 'tone-danger'}`} data-testid="available-now">
                                        {activeAvailable > 0 ? page('available_now', { qty: number(activeAvailable) }) : page('available_none')}
                                    </span>
                                )}
                                {stockKnown(activeProduct) && (
                                    <span className={`hub-pill ${stockState(activeProduct) === 'in' ? 'tone-ok' : 'tone-danger'}`}>
                                        {stockState(activeProduct) === 'in'
                                            ? translate('portal', 'ppm_stock_left', { qty: number(activeProduct.stock), unit: String(unitWord(activeProduct.unit) || translate('portal', 'ppm_unit_fallback')) })
                                            : translate('portal', 'ppm_stock_awaiting')}
                                    </span>
                                )}
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
                        </div>
                    </form>
                )}
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
                            onClick={() => setClearAsk(true)}
                            data-testid="cart-clear"
                        >
                            {translate('portal', 'b2b_clear_all')}
                        </button>
                    )}
                </div>

                {(
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
                                {cart.length === 0 && (
                                    <tr>
                                        <td colSpan={8}>
                                            <div className="hub-empty ppm-empty">
                                                <ShoppingBag size={32} />
                                                <p className="ops-semibold">{translate('portal', 'ppm_cart_empty_title')}</p>
                                                <small className="ops-muted">{translate('portal', 'ppm_cart_empty_body')}</small>
                                            </div>
                                        </td>
                                    </tr>
                                )}
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
                                                {p.group ? <div className="cell-sub ppm-group">{String(p.group)}</div> : null}
                                                <div className="cell-sub">
                                                    {(p.size_label || p.strength) ? <strong className="ppm-line-strength">{p.size_label || p.strength}</strong> : null}
                                                    <span>{categoryWord(p.category)}{p.sub_category ? ` · ${p.sub_category}` : ''}</span>
                                                    <span className="ppm-line-unit">({unitWord(p.unit) || translate('portal', 'ppm_box_unit')})</span>
                                                </div>
                                                {(() => {
                                                    const free = availableOf(p);
                                                    const extra = free === null ? 0 : Math.max(0, item.quantity - free);
                                                    return extra > 0 ? <div className="field-hint is-warn" role="status">{page('over_line', { extra: number(extra) })}</div> : null;
                                                })()}
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
                                {takaWords(cartSummary.totalPayable) !== null && <small className="ppm-sum-words">{takaWords(cartSummary.totalPayable)}</small>}
                            </div>
                        </div>

                        {/* Payment & Submission */}
                        <form onSubmit={askToConfirm} className="action-form" id="ppm-order-form">
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
                                        {MOBILE_CHANNELS.map(x => (
                                            <option key={x} value={x} disabled={!payChannels.includes(x)}>
                                                {translate('portal', `pay_channel_${x.toLowerCase()}`)}{payChannels.includes(x) ? '' : ` ${translate('order_split', 'method_not_ready')}`}
                                            </option>
                                        ))}
                                        <option value={OFFICE_CHANNEL}>{translate('portal', 'pay_channel_office')}</option>
                                        <option value={ACCOUNT_CHANNEL} disabled={!accountOffered} data-testid="wallet-option">
                                            {translate('portal', 'pay_channel_account')} ({money(accountBalance)}){accountOptionNote ? ` ${accountOptionNote}` : ''}
                                        </option>
                                    </select>
                                    {paysAtOffice && !accountChosen && <small className="field-hint">{translate('order_payment', 'method_office_hint')}</small>}
                                    {accountChosen && <small className="field-hint">{translate('order_payment', 'method_account_hint')}</small>}
                                    {payOptions.account_block !== 'off' && (
                                        <small className="field-hint" data-testid="wallet-usable">
                                            {translate('order_payment', 'wallet_usable_line', { amount: money(accountBalance) })} {translate('order_payment', 'wallet_usable_note')}
                                        </small>
                                    )}
                                    {payOptions.account_block === 'debt' && <small className="field-hint is-warn" role="status">{translate('order_payment', 'account_block_debt')}</small>}
                                </label>

                                {/* Paying from the wallet: how much, and what is still to pay. Less than the order means a second method. */}
                                {accountChosen && accountOffered && (
                                    <div data-testid="wallet-part">
                                        <label>
                                            {translate('order_payment', 'wallet_part_label')}
                                            <input
                                                type="number"
                                                inputMode="decimal"
                                                min="0.01"
                                                step="0.01"
                                                max={walletMax.toFixed(2)}
                                                value={walletPartText ?? walletMax.toFixed(2)}
                                                aria-invalid={walletPartProblem !== null}
                                                onChange={e => setWalletPartText(e.target.value)}
                                            />
                                            <small className="field-hint">{translate('order_payment', 'wallet_part_hint', { max: money(walletMax) })}</small>
                                            {walletPartProblem && <span className="field-error" role="alert">{walletPartProblem}</span>}
                                            {walletPartError && <span className="field-error" role="alert">{walletPartError}</span>}
                                        </label>
                                        <div className="ppm-summary" data-testid="wallet-split-summary">
                                            <div>
                                                <small className="ppm-sum-label">{translate('order_payment', 'wallet_part_from')}</small>
                                                <strong className="ppm-sum-value">{money(walletPartProblem ? 0 : walletPart)}</strong>
                                            </div>
                                            <div>
                                                <small className="ppm-sum-label">{translate('order_payment', 'account_after')}</small>
                                                <strong className="ppm-sum-value">{money(walletPartProblem ? accountBalance : Math.max(0, accountAfter))}</strong>
                                            </div>
                                            <div className="ppm-sum-net">
                                                <small className="ppm-sum-label">{translate('order_payment', 'wallet_part_rest')}</small>
                                                <strong className="ppm-sum-value is-net">{money(walletPartProblem ? cartSummary.totalPayable : Math.max(0, splitRest))}</strong>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* The wallet pays less than the order: say what is taken and ask how the rest is paid. */}
                                {splitOn && (
                                    <div data-testid="split-pay">
                                        <p className="field-hint is-warn" role="status" data-testid="split-summary">
                                            {translate('order_payment', 'wallet_part_rest_needed', { rest: money(splitRest) })}
                                        </p>
                                        <label>
                                            {translate('order_split', 'rest_method')}
                                            <select
                                                value={restChannel}
                                                onChange={e => {
                                                    setRestChannel(e.target.value);
                                                    f.setData('payment_account_id', '');
                                                }}
                                            >
                                                {MOBILE_CHANNELS.map(x => (
                                                    <option key={x} value={x} disabled={!payChannels.includes(x)}>
                                                        {translate('portal', `pay_channel_${x.toLowerCase()}`)}{payChannels.includes(x) ? '' : ` ${translate('order_split', 'method_not_ready')}`}
                                                    </option>
                                                ))}
                                                <option value={OFFICE_CHANNEL}>{translate('portal', 'pay_channel_office')}</option>
                                            </select>
                                        </label>
                                        {paysAtOffice && <small className="field-hint">{translate('order_split', 'cash_note')}</small>}
                                    </div>
                                )}

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

                                {fullPaymentRequired || splitOn ? (
                                    <label>
                                        {splitOn ? translate('order_split', 'still_to_pay') : translate('portal', 'ppm_full_amount_label')}
                                        <input
                                            type="text"
                                            readOnly
                                            aria-readonly="true"
                                            value={money(splitOn ? splitRest : cartSummary.totalPayable)}
                                        />
                                        {!splitOn && <small className="field-hint">{translate('portal', 'ppm_full_amount_hint')}</small>}
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
                                    <FilePicker
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        required={fullPaymentRequired || f.data.reference.trim() !== ''}
                                        onPick={file => f.setData('proof', file)}
                                    />
                                    <small className="field-hint">{translate('portal', 'payment_proof_hint')}</small>
                                    {f.errors.proof && <span className="field-error" role="alert">{f.errors.proof}</span>}
                                </label>
                                </>}
                            </div>

                            <fieldset className="ppm-sizes ppm-delivery" data-testid="delivery-method">
                                <legend className="ops-field-label">{translate('order_delivery', 'method_label')}</legend>
                                {(['courier', 'office'] as const).map(method => (
                                    <label key={method} className={`ppm-size${f.data.delivery_method === method ? ' is-on' : ''}`}>
                                        <input
                                            type="radio"
                                            name="ppm-delivery-method"
                                            value={method}
                                            checked={f.data.delivery_method === method}
                                            onChange={() => f.setData('delivery_method', method)}
                                        />
                                        <span className="ppm-size-label">{translate('order_delivery', `method_${method}`)}</span>
                                    </label>
                                ))}
                            </fieldset>
                            {collectsAtOffice && <p className="field-hint" role="status" data-testid="delivery-office-hint">{translate('order_delivery', 'method_office_hint')}</p>}
                            {f.errors.delivery_method && <span className="field-error" role="alert">{f.errors.delivery_method}</span>}

                            {!collectsAtOffice && courierChoices.length > 0 && (
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

                            {orderNote.shown && <label>
                                {orderNote.label(translate('portal', 'note'))}
                                <textarea
                                    rows={2}
                                    required={orderNote.required}
                                    placeholder={translate('portal', 'order_note_placeholder')}
                                    value={f.data.note}
                                    onChange={e => f.setData('note', e.target.value)}
                                />
                            </label>}

                            {/* FormErrors also keeps the page-top message quiet, so errors are not shown twice. */}
                            <FormErrors errors={f.errors} className="error ppm-error" besideFields={['payment_account_id', 'proof', 'courier_choice', 'account_amount', 'delivery_method']} />

                            <div className="ops-row is-end ops-mt-3 ppm-submit-row">
                                <button
                                    type="submit"
                                    className="button dark ppm-submit"
                                    disabled={f.processing || walletPartProblem !== null}
                                >
                                    {f.processing
                                        ? translate('portal', 'submitting')
                                        : splitOn
                                            ? translate('order_split', 'submit', { balance: money(walletPart), rest: money(splitRest) })
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

            {/* Phone only (hidden by CSS elsewhere): the total and the send button stay above the bottom tab bar. */}
            {isPhone && cart.length > 0 && (
                <div className="ppm-sticky-bar" data-testid="order-sticky-bar">
                    <div className="ppm-sticky-total">
                        <small>{page('mobile_total')}</small>
                        <strong>{money(cartSummary.totalPayable)}</strong>
                    </div>
                    <button type="submit" form="ppm-order-form" className="button dark" disabled={f.processing || walletPartProblem !== null}>
                        {f.processing ? translate('portal', 'submitting') : page('mobile_send')}
                    </button>
                </div>
            )}

            {clearAsk && (
                <Modal
                    title={translate('leftovers_partner', 'clear_cart_title')}
                    onClose={() => setClearAsk(false)}
                    footer={(
                        <>
                            <button type="button" className="button" data-testid="cart-clear-no" onClick={() => setClearAsk(false)}>{translate('leftovers_partner', 'clear_cart_no')}</button>
                            <button type="button" className="button dark" data-testid="cart-clear-yes" onClick={() => { setCart([]); setClearAsk(false); }}>{translate('leftovers_partner', 'clear_cart_yes')}</button>
                        </>
                    )}
                >
                    <p>{translate('leftovers_partner', 'clear_cart_body', { count: String(cart.length) })}</p>
                </Modal>
            )}

            {confirm && (() => {
                const checking = confirm.status === 'checking';
                const failed = confirm.status === 'failed';
                const backOrders = cart
                    .map(item => ({ item, free: availableOf(item.product) }))
                    .filter((entry): entry is { item: CartItem; free: number } => entry.free !== null && entry.item.quantity > entry.free);
                const balanceMoved = paisa(confirm.before) !== paisa(accountBalance);
                const methodLabel = splitOn
                    ? `${translate('portal', 'pay_channel_account')} + ${translate('portal', `pay_channel_${moneyChannel.toLowerCase()}`)}`
                    : translate('portal', `pay_channel_${f.data.channel.toLowerCase()}`);
                // The balance dropped while the page was open: the rest payment's details were never asked for, so go back first.
                const restDetailsMissing = splitOn && !paysAtOffice && f.data.reference.trim() === '';
                const sendBlocked = checking || failed || restDetailsMissing || walletPartProblem !== null;
                return (
                    <Modal
                        title={page('confirm_title')}
                        onClose={() => setConfirm(null)}
                        footer={(
                            <>
                                <button type="button" className="button" onClick={() => setConfirm(null)}>{page('confirm_back')}</button>
                                <button type="button" className="button dark" data-testid="confirm-send" disabled={sendBlocked || f.processing} onClick={sendOrder}>
                                    {page('confirm_send')} <ArrowRight size={17} />
                                </button>
                            </>
                        )}
                    >
                        <div className="ppm-confirm" data-testid="order-confirm">
                            {checking && <p role="status">{page('confirm_checking')}</p>}
                            {failed && <p className="field-error" role="alert">{page('confirm_refresh_failed')}</p>}
                            <div className="ppm-summary">
                                <div className="ppm-sum-net">
                                    <small className="ppm-sum-label is-ok ops-bold">{page('confirm_total_label')}</small>
                                    <strong className="ppm-sum-value is-net" data-testid="confirm-total">{money(cartSummary.totalPayable)}</strong>
                                    <span className="ops-semibold" data-testid="confirm-words">{page('confirm_in_words')}: {amountInWords(cartSummary.totalPayable)}</span>
                                </div>
                                <div>
                                    <small className="ppm-sum-label">{page('confirm_items', { items: String(cartSummary.itemsCount), qty: String(cartSummary.totalQty) })}</small>
                                    <strong className="ppm-sum-value">{page('confirm_method')}: {methodLabel}</strong>
                                </div>
                            </div>
                            <p data-testid="confirm-delivery"><b>{translate('order_delivery', 'method_label')}:</b> {translate('order_delivery', `method_${f.data.delivery_method}`)}</p>
                            {cartSummary.totalPayable >= BIG_ORDER_TAKA && <p className="notice warning" role="note" data-testid="confirm-big-note">{page('confirm_big_note')}</p>}
                            {!checking && !failed && (paysFromAccount || splitOn) && (
                                <div data-testid="confirm-account">
                                    <p>{page('confirm_account_balance', { balance: money(accountBalance) })}</p>
                                    {balanceMoved && <p className="field-hint is-warn" role="status">{page('confirm_account_changed', { before: money(confirm.before), now: money(accountBalance) })}</p>}
                                    {splitOn && <p>{page('confirm_account_split', { balance: money(walletPart), rest: money(splitRest) })}</p>}
                                    {paysFromAccount && <p>{page('confirm_account_full', { amount: money(cartSummary.totalPayable), after: money(Math.max(0, accountAfter)) })}</p>}
                                    {restDetailsMissing && <p className="field-error" role="alert">{page('confirm_account_short')}</p>}
                                </div>
                            )}
                            {!checking && !failed && backOrders.length > 0 && (
                                <div className="notice warning" role="status" data-testid="confirm-back-orders">
                                    <p><b>{page('over_confirm_title')}</b></p>
                                    <ul>
                                        {backOrders.map(({ item, free }) => (
                                            <li key={item.product.id}>
                                                {page('over_confirm_row', { name: String(item.product.name), qty: number(item.quantity), available: number(free), extra: number(item.quantity - free) })}
                                            </li>
                                        ))}
                                    </ul>
                                    <p>{page('over_confirm_hint')}</p>
                                </div>
                            )}
                        </div>
                    </Modal>
                );
            })()}
        </div>
    );
}
