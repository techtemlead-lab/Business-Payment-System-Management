import React, { useState } from 'react';
import { withBoldValues } from './formFields';
import { Plus } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { notify } from '../../shared/notify';
import SearchSelect from '../SearchSelect';
import type { CampaignBundleItem, CampaignCardItem } from './types';

export function CampaignOffersManager({
    cards,
    defaultCards,
    enabled,
    badge,
    title,
    subtitle,
    defaultBadge,
    defaultTitle,
    defaultSubtitle,
    onToggleEnabled,
    onUpdateHeaderField,
    onChange,
    products = [],
}: {
    cards: CampaignCardItem[];
    defaultCards: CampaignCardItem[];
    enabled: boolean;
    badge: string;
    title: string;
    subtitle: string;
    defaultBadge: string;
    defaultTitle: string;
    defaultSubtitle: string;
    onToggleEnabled: (enabled: boolean) => void;
    onUpdateHeaderField: (key: 'campaign_badge' | 'campaign_title' | 'campaign_subtitle', val: string) => void;
    onChange: (cards: CampaignCardItem[]) => void;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string }[];
}) {
    const translate = useT();
    const [bundleSelectedProds, setBundleSelectedProds] = useState<Record<number, string>>({});

    const parsePrice = (val: any): number => {
        if (typeof val === 'number') return val;
        if (!val) return 0;
        const clean = String(val).replace(/[^\d.]/g, '');
        const num = parseFloat(clean);
        return isNaN(num) ? 0 : num;
    };

    const handleAddSingle = () => {
        const newCard: CampaignCardItem = {
            id: `card-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            type: 'single',
            product_id: '',
            promo_discount_pct: 60,
            bundle_discount_mode: 'total',
            bundle_discount_pct: 35,
            bundle_items: [],
            tag: 'Special Offer',
            title: 'New Single Product Offer',
            desc: 'Exclusive promotional discount on natural wellness care.',
            feat1: 'Available from authorized dealers',
            feat2: 'Fast Nationwide Delivery & Support',
            price: '',
            mrp: '',
            save: '',
            btn: 'View Offer',
            link: '/products',
            image: '',
            highlight: '0',
        };
        onChange([...cards, newCard]);
    };

    const handleAddBundle = () => {
        const newCard: CampaignCardItem = {
            id: `card-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            type: 'bundle',
            product_id: '',
            promo_discount_pct: 0,
            bundle_discount_mode: 'total',
            bundle_discount_pct: 35,
            bundle_items: [],
            tag: 'Package Deal',
            title: 'New Promotional Wellness Package',
            desc: 'Exclusive curated package combining essential healthcare products at an extra discount.',
            feat1: 'Several products in one package',
            feat2: 'Free Priority Dealer Shipping Included',
            price: '',
            mrp: '',
            save: '',
            btn: 'View Package',
            link: '/products',
            image: '',
            highlight: '0',
        };
        onChange([...cards, newCard]);
    };

    const handleRemove = (index: number) => {
        const target = cards[index];
        const name = target?.title?.trim() || translate('portal', 'sce_offer_card_number', { number: String(index + 1) });
        if (window.confirm(translate('portal', 'cms_confirm_remove_promo', { name }))) {
            const next = cards.filter((_, idx) => idx !== index);
            onChange(next);
        }
    };

    const handleMoveUp = (index: number) => {
        if (index <= 0) return;
        const next = [...cards];
        const temp = next[index - 1];
        next[index - 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleMoveDown = (index: number) => {
        if (index >= cards.length - 1) return;
        const next = [...cards];
        const temp = next[index + 1];
        next[index + 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleFieldChange = (index: number, field: keyof CampaignCardItem, val: any) => {
        const next = cards.map((c, idx) => {
            if (idx === index) {
                return { ...c, [field]: val };
            }
            return c;
        });
        onChange(next);
    };

    const handleSwitchCardType = (index: number, newType: 'single' | 'bundle') => {
        const target = cards[index];
        if (!target || target.type === newType) return;

        const next = cards.map((c, idx) => {
            if (idx === index) {
                if (newType === 'bundle') {
                    return {
                        ...c,
                        type: 'bundle' as const,
                        bundle_discount_mode: c.bundle_discount_mode || ('total' as const),
                        bundle_discount_pct: c.bundle_discount_pct ?? 35,
                        bundle_items: c.bundle_items || [],
                        tag: c.tag === 'Special Offer' ? 'Package Deal' : c.tag,
                    };
                } else {
                    return {
                        ...c,
                        type: 'single' as const,
                        promo_discount_pct: c.promo_discount_pct ?? 60,
                        tag: c.tag === 'Package Deal' ? 'Special Offer' : c.tag,
                    };
                }
            }
            return c;
        });
        onChange(next);
    };

    // Mode A: Single Product Select
    const handleProductSelect = (index: number, productId: string) => {
        const p = products.find(prod => String(prod.id) === productId);
        if (!p) {
            handleFieldChange(index, 'product_id', '');
            return;
        }

        const numMrp = parsePrice(p.mrp);
        const target = cards[index];
        const promoPct = parsePrice(target?.promo_discount_pct) || 60;
        const calcPrice = numMrp > 0 ? Math.round(numMrp * (1 - promoPct / 100)) : 0;
        const calcSave = numMrp > 0 ? Math.max(0, numMrp - calcPrice) : 0;

        const next = cards.map((c, idx) => {
            if (idx === index) {
                return {
                    ...c,
                    type: 'single' as const,
                    product_id: String(p.id),
                    promo_discount_pct: promoPct,
                    link: `/products/${p.id}`,
                    mrp: numMrp > 0 ? `${numMrp.toLocaleString()} Tk` : c.mrp,
                    price: numMrp > 0 ? `${calcPrice.toLocaleString()} Tk` : c.price,
                    save: numMrp > 0 ? `Save ${calcSave.toLocaleString()} Tk` : c.save,
                    title: (!c.title || c.title === 'New Promotional Offer' || c.title === 'New Single Product Offer' || c.title.includes('Package')) ? p.name : c.title,
                };
            }
            return c;
        });
        onChange(next);
    };

    // Mode A: Single Product Promo Discount % Change
    const handlePromoDiscountChange = (index: number, newPctVal: number | string) => {
        const pct = parsePrice(newPctVal);
        const target = cards[index];
        if (!target) return;

        let numMrp = parsePrice(target.mrp);
        if ((!numMrp || numMrp === 0) && target.product_id) {
            const p = products.find(prod => String(prod.id) === target.product_id);
            if (p) numMrp = parsePrice(p.mrp);
        }

        const calcPrice = numMrp > 0 ? Math.round(numMrp * (1 - pct / 100)) : parsePrice(target.price);
        const calcSave = numMrp > 0 ? Math.max(0, numMrp - calcPrice) : 0;

        const next = cards.map((c, idx) => {
            if (idx === index) {
                return {
                    ...c,
                    promo_discount_pct: pct,
                    price: numMrp > 0 ? `${calcPrice.toLocaleString()} Tk` : c.price,
                    save: numMrp > 0 ? `Save ${calcSave.toLocaleString()} Tk` : c.save,
                };
            }
            return c;
        });
        onChange(next);
    };

    // Mode B: Recalculate Bundle Card
    const recalculateBundleCard = (
        cardIndex: number,
        items: CampaignBundleItem[],
        mode: 'total' | 'itemized',
        bundleDiscountPct: number | string
    ) => {
        const pct = parsePrice(bundleDiscountPct);
        const totalMrp = items.reduce((acc, it) => acc + parsePrice(it.mrp), 0);

        let packagePrice = 0;
        if (mode === 'itemized') {
            packagePrice = items.reduce((acc, it) => {
                const itPrice = it.item_price !== undefined
                    ? parsePrice(it.item_price)
                    : Math.round(parsePrice(it.mrp) * (1 - (parsePrice(it.custom_discount_pct ?? pct) / 100)));
                return acc + itPrice;
            }, 0);
        } else {
            packagePrice = Math.round(totalMrp * (1 - pct / 100));
        }
        const saveAmt = Math.max(0, totalMrp - packagePrice);

        const next = cards.map((c, idx) => {
            if (idx === cardIndex) {
                return {
                    ...c,
                    type: 'bundle' as const,
                    bundle_discount_mode: mode,
                    bundle_discount_pct: pct,
                    bundle_items: items,
                    mrp: totalMrp > 0 ? `${totalMrp.toLocaleString()} Tk` : c.mrp,
                    price: totalMrp > 0 ? `${packagePrice.toLocaleString()} Tk` : c.price,
                    save: totalMrp > 0 ? `Save ${saveAmt.toLocaleString()} Tk` : c.save,
                };
            }
            return c;
        });
        onChange(next);
    };

    // Mode B: Add Product to Bundle
    const handleAddBundleItem = (cardIndex: number) => {
        const prodId = bundleSelectedProds[cardIndex];
        if (!prodId) {
            notify(translate('portal', 'cms_alert_select_product'), 'error');
            return;
        }
        const p = products.find(prod => String(prod.id) === prodId);
        if (!p) return;

        const target = cards[cardIndex];
        const existingItems = target.bundle_items || [];

        if (existingItems.some(item => String(item.product_id) === String(p.id))) {
            notify(translate('portal', 'cms_alert_already_in_package', { name: p.name }), 'error');
            return;
        }

        const numMrp = parsePrice(p.mrp);
        const mode = target.bundle_discount_mode || 'total';
        const pct = target.bundle_discount_pct ?? 35;
        const newItem: CampaignBundleItem = {
            product_id: p.id,
            name: p.name,
            sku: p.sku || '',
            mrp: numMrp,
            custom_discount_pct: pct,
            item_price: Math.round(numMrp * (1 - parsePrice(pct) / 100)),
        };

        const newItems = [...existingItems, newItem];
        recalculateBundleCard(cardIndex, newItems, mode, pct);
        setBundleSelectedProds(prev => ({ ...prev, [cardIndex]: '' }));
    };

    // Mode B: Remove Product from Bundle
    const handleRemoveBundleItem = (cardIndex: number, itemIndex: number) => {
        const target = cards[cardIndex];
        const newItems = (target.bundle_items || []).filter((_, idx) => idx !== itemIndex);
        recalculateBundleCard(
            cardIndex,
            newItems,
            target.bundle_discount_mode || 'total',
            target.bundle_discount_pct ?? 35
        );
    };

    // Mode B: Change Itemized Discount on specific item
    const handleItemizedDiscountChange = (cardIndex: number, itemIndex: number, newPct: number) => {
        const target = cards[cardIndex];
        const items = [...(target.bundle_items || [])];
        const item = items[itemIndex];
        if (!item) return;

        const numMrp = parsePrice(item.mrp);
        const itemPrice = Math.round(numMrp * (1 - newPct / 100));
        items[itemIndex] = {
            ...item,
            custom_discount_pct: newPct,
            item_price: itemPrice,
        };
        recalculateBundleCard(cardIndex, items, 'itemized', target.bundle_discount_pct ?? 35);
    };

    const handleAutoCalculateSave = (index: number) => {
        const target = cards[index];
        if (!target) return;
        const numP = parsePrice(target.price);
        const numM = parsePrice(target.mrp);
        if (numM > 0 && numP > 0) {
            if (numM > numP) {
                const diff = numM - numP;
                handleFieldChange(index, 'save', `Save ${diff.toLocaleString()} Tk`);
            } else {
                notify(translate('portal', 'cms_alert_mrp_gt_offer'), 'error');
            }
        } else {
            notify(translate('portal', 'cms_alert_enter_prices'), 'error');
        }
    };

    const handleReset = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_promos'))) {
            onChange(defaultCards);
        }
    };

    return (
        <div>
            {/* Master Visibility Switch */}
            <div className={`ed-switch-banner ed-mb-lg${enabled ? ' is-on tone-ok' : ''}`}>
                <div className="ed-row is-loose is-top">
                    <div className="ed-switch-icon">
                        {enabled ? '✓' : '✕'}
                    </div>
                    <div>
                        <div className="ed-row">
                            <strong className="ed-strong is-lg">
                                {translate('portal', 'sce_campaign_section_status', {
                                    state: enabled
                                        ? translate('portal', 'sce_section_active_visible')
                                        : translate('portal', 'sce_section_hidden'),
                                })}
                            </strong>
                            <span className="ed-state-pill">
                                {enabled ? translate('portal', 'sce_enabled') : translate('portal', 'sce_disabled')}
                            </span>
                        </div>
                        <p className="ed-desc">
                            {enabled
                                ? translate('portal', 'sce_campaign_enabled_hint', { count: String(cards.length) })
                                : translate('portal', 'sce_campaign_disabled_hint')}
                        </p>
                    </div>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={() => onToggleEnabled(!enabled)}
                        className={`button ${enabled ? 'danger' : 'dark'}`}
                    >
                        {enabled
                            ? translate('portal', 'sce_disable_hide_section')
                            : translate('portal', 'sce_enable_show_section')}
                    </button>
                </div>
            </div>

            {/* Header Titles */}
            <div className="ed-card is-soft ed-mb-lg">
                <h4 className="ed-subtitle">
                    {translate('portal', 'sce_section_headings_banner')}
                </h4>
                <div className="ed-grid is-md">
                    <div>
                        <label className="ed-label">
                            {translate('portal', 'sce_section_badge_label')}
                        </label>
                        <input
                            type="text"
                            value={badge}
                            placeholder={defaultBadge}
                            onChange={e => onUpdateHeaderField('campaign_badge', e.target.value)}
                            className="ed-input"
                        />
                        <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defaultBadge ?? '') })}</small>
                    </div>
                    <div>
                        <label className="ed-label">
                            {translate('portal', 'sce_section_main_title')}
                        </label>
                        <input
                            type="text"
                            value={title}
                            placeholder={defaultTitle}
                            onChange={e => onUpdateHeaderField('campaign_title', e.target.value)}
                            className="ed-input"
                        />
                        <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defaultTitle ?? '') })}</small>
                    </div>
                    <div className="hub-span-all">
                        <label className="ed-label">
                            {translate('portal', 'sce_section_subtitle_description')}
                        </label>
                        <input
                            type="text"
                            value={subtitle}
                            placeholder={defaultSubtitle}
                            onChange={e => onUpdateHeaderField('campaign_subtitle', e.target.value)}
                            className="ed-input"
                        />
                        <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defaultSubtitle ?? '') })}</small>
                    </div>
                </div>
            </div>

            {/* Action Bar for Offers */}
            <div className="ed-head">
                <div>
                    <h3 className="ed-title is-flush">
                        {translate('portal', 'sce_offer_cards_heading', { count: String(cards.length) })}
                    </h3>
                    <span className="ed-desc is-sm">
                        {translate('portal', 'sce_offer_cards_hint')}
                    </span>
                </div>
                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleAddSingle}
                        className="button small dark"
                    >
                        <Plus size={15} /> {translate('portal', 'sce_add_single_offer')}
                    </button>
                    <button
                        type="button"
                        onClick={handleAddBundle}
                        className="button small dark"
                    >
                        <Plus size={15} /> {translate('portal', 'sce_add_package_offer')}
                    </button>
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small"
                    >
                        {translate('portal', 'sce_reset_to_defaults')}
                    </button>
                </div>
            </div>

            {/* Offer Cards List */}
            <div className="ed-stack is-loose">
                {cards.map((card, idx) => {
                    const isHighlight = card.highlight === '1' || card.highlight === true;
                    const cardType: 'single' | 'bundle' = card.type || (card.bundle_items && card.bundle_items.length > 0 ? 'bundle' : 'single');
                    const defCard = defaultCards[idx] || {
                        tag: cardType === 'bundle' ? translate('portal', 'sce_offer_fallback_tag_package') : translate('portal', 'sce_offer_fallback_tag_single'),
                        title: cardType === 'bundle' ? translate('portal', 'sce_offer_fallback_title_package') : translate('portal', 'sce_offer_fallback_title_single'),
                        desc: '',
                        feat1: '',
                        feat2: '',
                        price: '',
                        mrp: '',
                        save: '',
                        btn: cardType === 'bundle' ? translate('portal', 'sce_offer_fallback_btn_package') : translate('portal', 'sce_offer_fallback_btn_single'),
                        link: '/products',
                        image: '',
                        highlight: '0'
                    };

                    // For Single Product Offer: Find catalog product details
                    const linkedProduct = card.product_id ? products.find(p => String(p.id) === String(card.product_id)) : undefined;
                    const linkedMrp = linkedProduct ? parsePrice(linkedProduct.mrp) : parsePrice(card.mrp);
                    const linkedDealerPrice = linkedProduct?.dealer_price ? parsePrice(linkedProduct.dealer_price) : 0;
                    const regDealerDiscountPct = linkedMrp > 0 && linkedDealerPrice > 0 ? Math.round((1 - linkedDealerPrice / linkedMrp) * 100) : 0;
                    const currentPromoDiscountPct = parsePrice(card.promo_discount_pct) || (linkedMrp > 0 && parsePrice(card.price) > 0 ? Math.round((1 - parsePrice(card.price) / linkedMrp) * 100) : 60);

                    // For Bundle Offer: Calculate total MRP and items
                    const bundleItems = card.bundle_items || [];
                    const totalBundleMrp = bundleItems.reduce((acc, it) => acc + parsePrice(it.mrp), 0);
                    const bundleMode = card.bundle_discount_mode || 'total';
                    const bundlePct = parsePrice(card.bundle_discount_pct ?? 35);
                    const effectiveBundlePrice = parsePrice(card.price);
                    const effectiveBundleSave = parsePrice(card.save) || (totalBundleMrp > 0 ? Math.max(0, totalBundleMrp - effectiveBundlePrice) : 0);
                    const effectiveBundleDiscountPct = totalBundleMrp > 0 ? Math.round((effectiveBundleSave / totalBundleMrp) * 100) : bundlePct;

                    return (
                        <div
                            key={card.id || idx}
                            className={`ed-card is-lg${isHighlight ? ' is-brand' : cardType === 'bundle' ? ' is-info' : ''}`}
                        >
                            {/* Card Top Control Bar */}
                            <div className="ed-head is-ruled">
                                <div className="ed-row is-loose is-wrap">
                                    <span className={`hub-step ${cardType === 'bundle' ? 'tone-info' : isHighlight ? 'tone-ok' : 'tone-brand'}`}>
                                        {idx + 1}
                                    </span>
                                    <strong className="ed-strong is-lg">
                                        {card.title || defCard.title || translate('portal', 'sce_offer_card_number', { number: String(idx + 1) })}
                                    </strong>

                                    {/* Mode Indicator & Switcher */}
                                    <div className="hub-seg">
                                        <button
                                            type="button"
                                            onClick={() => handleSwitchCardType(idx, 'single')}
                                            className={cardType === 'single' ? 'is-active' : undefined}
                                        >
                                            🏷️ {translate('portal', 'sce_single_product')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleSwitchCardType(idx, 'bundle')}
                                            className={cardType === 'bundle' ? 'is-active' : undefined}
                                        >
                                            📦 {translate('portal', 'sce_package_bundle')}
                                        </button>
                                    </div>

                                    {isHighlight && (
                                        <span className="hub-pill tone-ok">
                                            ★ {translate('portal', 'sce_highlighted_badge')}
                                        </span>
                                    )}
                                </div>

                                <div className="ed-row">
                                    {/* Highlight Toggle Button */}
                                    <button
                                        type="button"
                                        onClick={() => handleFieldChange(idx, 'highlight', isHighlight ? '0' : '1')}
                                        className={`button small${isHighlight ? ' dark' : ''}`}
                                    >
                                        ★ {isHighlight ? translate('portal', 'sce_highlighted_active') : translate('portal', 'sce_set_highlighted')}
                                    </button>

                                    {/* Reorder Buttons */}
                                    <button
                                        type="button"
                                        disabled={idx === 0}
                                        onClick={() => handleMoveUp(idx)}
                                        title={translate('portal', 'sce_move_up')}
                                        className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={idx === cards.length - 1}
                                        onClick={() => handleMoveDown(idx)}
                                        title={translate('portal', 'sce_move_down')}
                                        className={`ed-icon-btn is-sm${idx === cards.length - 1 ? ' is-disabled' : ''}`}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(idx)}
                                        title={translate('portal', 'sce_delete_offer_card')}
                                        className="button small danger"
                                    >
                                        ✕ {translate('portal', 'sce_delete')}
                                    </button>
                                </div>
                            </div>

                            {/* MODE A: SINGLE PRODUCT OFFER CONFIGURATION */}
                            {cardType === 'single' && (
                                <div>
                                    {/* Catalog Product Selection Bar */}
                                    <div className="ed-callout tone-ok ed-mb">
                                        <div className="ed-head is-sm">
                                            <span className="ed-strong tone-ok">
                                                🏷️ {translate('portal', 'sce_select_catalog_product_to_feature')}
                                            </span>
                                            {card.product_id && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleFieldChange(idx, 'product_id', '')}
                                                    className="ed-mini tone-danger"
                                                >
                                                    ✕ {translate('portal', 'sce_disconnect_product')}
                                                </button>
                                            )}
                                        </div>
                                        <SearchSelect
                                            value={card.product_id ? String(card.product_id) : ''}
                                            onChange={(value) => handleProductSelect(idx, value)}
                                            className="ed-input"
                                            emptyLabel={translate('portal', 'sce_choose_catalog_product')}
                                            options={products.map(p => ({
                                                value: String(p.id),
                                                label: `#${p.id}: ${p.name} ${translate('portal', 'sce_option_mrp_tk', { mrp: String(p.mrp) })} ${p.sku ? translate('portal', 'sce_option_sku', { sku: String(p.sku) }) : ''} — ${translate('portal', 'sce_option_category', { category: String(p.category ?? '') })}`,
                                                keywords: p.sku ? String(p.sku) : undefined,
                                            }))}
                                        />

                                        {/* Product Base Details & Promotional Discount Rate Setting */}
                                        {linkedProduct ? (
                                            <div className="ed-foot">
                                                <div className="ed-grid is-sm ed-mb">
                                                    <div className="ed-stat">
                                                        <span className="ed-meta is-block">{translate('portal', 'sce_regular_retail_mrp')}</span>
                                                        <strong className="ed-strong">৳ {linkedMrp.toLocaleString()}</strong>
                                                    </div>
                                                    <div className="ed-stat">
                                                        <span className="ed-meta is-block">{translate('portal', 'sce_base_dealer_catalog_price')}</span>
                                                        <strong className="ed-strong">
                                                            {linkedDealerPrice > 0
                                                                ? translate('portal', 'sce_dealer_price_with_off', {
                                                                    price: linkedDealerPrice.toLocaleString(),
                                                                    pct: String(regDealerDiscountPct),
                                                                })
                                                                : translate('portal', 'sce_standard_mrp')}
                                                        </strong>
                                                    </div>
                                                    <div className="ed-stat">
                                                        <span className="ed-meta is-block">{translate('portal', 'sce_product_sku_code')}</span>
                                                        <strong className="ed-strong">{linkedProduct.sku || `#${linkedProduct.id}`}</strong>
                                                    </div>
                                                </div>

                                                {/* Promotional Discount % Input & Quick Presets */}
                                                <div className="ed-card is-compact is-accent">
                                                    <label className="ed-label tone-ok">
                                                        ⚡ {translate('portal', 'sce_set_promo_discount_pct')}
                                                    </label>
                                                    <div className="ed-row is-loose is-wrap">
                                                        <div className="ed-row is-tight">
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={99}
                                                                value={card.promo_discount_pct ?? 60}
                                                                onChange={e => handlePromoDiscountChange(idx, e.target.value)}
                                                                className="ed-input ed-pct tone-ok"
                                                            />
                                                            <strong className="ed-strong is-lg tone-ok">%</strong>
                                                        </div>

                                                        {/* Quick Presets */}
                                                        <div className="ed-row is-wrap">
                                                            <span className="ed-label is-inline">{translate('portal', 'sce_quick_presets')}</span>
                                                            {[30, 40, 50, 60, 70].map(pct => (
                                                                <button
                                                                    key={pct}
                                                                    type="button"
                                                                    onClick={() => handlePromoDiscountChange(idx, pct)}
                                                                    className={`ed-chip${currentPromoDiscountPct === pct ? ' is-active' : ''}`}
                                                                >
                                                                    {pct}%
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <small className="ed-meta is-block">
                                                {translate('portal', 'sce_select_product_auto_price_hint')}
                                            </small>
                                        )}
                                    </div>

                                    {/* Financial Breakdown & Accounting Rule Card */}
                                    {card.product_id && linkedMrp > 0 && (
                                        <div className="ed-callout tone-ok ed-mb">
                                            <div className="ed-head is-sm">
                                                <span className="ed-strong ed-row">
                                                    💰 {translate('portal', 'sce_offer_price_summary')}
                                                </span>
                                                <span className="hub-pill tone-ok">
                                                    {translate('portal', 'sce_offer_discount_active', { pct: String(currentPromoDiscountPct) })}
                                                </span>
                                            </div>
                                            <div className="ed-grid is-xs ed-mb-sm">
                                                <div className="ed-stat">
                                                    <span className="ed-meta is-block">{translate('portal', 'sce_regular_mrp_heading')}</span>
                                                    <strong className="ed-strong">৳ {linkedMrp.toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted">
                                                    <span className="ed-meta is-block tone-ok">{translate('portal', 'sce_offer_price_payable')}</span>
                                                    <strong className="ed-strong is-lg tone-ok">৳ {parsePrice(card.price).toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted tone-danger">
                                                    <span className="ed-meta is-block tone-danger">{translate('portal', 'sce_dealer_profit_savings')}</span>
                                                    <strong className="ed-strong tone-danger">৳ {parsePrice(card.save).toLocaleString()}</strong>
                                                </div>
                                            </div>
                                            <p className="ed-desc is-sm is-flush">
                                                ℹ️ <strong>{translate('portal', 'sce_pricing_rule_label')}</strong>{' '}
                                                {withBoldValues(translate('portal', 'sce_pricing_rule_body'), {
                                                    pct: `${currentPromoDiscountPct}%`,
                                                    price: `৳${parsePrice(card.price).toLocaleString()}`,
                                                })}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* MODE B: GROUP / PACKAGE BUNDLE CONFIGURATION */}
                            {cardType === 'bundle' && (
                                <div>
                                    {/* Bundle Multi-Product Picker */}
                                    <div className="ed-callout tone-info ed-mb">
                                        <div className="ed-head is-sm">
                                            <div>
                                                <strong className="ed-strong tone-info is-block">
                                                    📦 {translate('portal', 'sce_build_package_heading', { count: String(bundleItems.length) })}
                                                </strong>
                                                <span className="ed-meta">
                                                    {translate('portal', 'sce_build_package_hint')}
                                                </span>
                                            </div>
                                        </div>

                                        {/* Product Add Bar */}
                                        <div className="ed-row is-wrap ed-mb">
                                            <SearchSelect
                                                value={bundleSelectedProds[idx] ? String(bundleSelectedProds[idx]) : ''}
                                                onChange={(value) => setBundleSelectedProds(prev => ({ ...prev, [idx]: value }))}
                                                className="ed-input is-grow"
                                                emptyLabel={translate('portal', 'sce_choose_product_for_package')}
                                                options={products.map(p => ({
                                                    value: String(p.id),
                                                    label: `#${p.id}: ${p.name} ${translate('portal', 'sce_option_mrp_tk', { mrp: String(p.mrp) })} ${p.sku ? translate('portal', 'sce_option_sku', { sku: String(p.sku) }) : ''} — ${p.category}`,
                                                    keywords: p.sku ? String(p.sku) : undefined,
                                                }))}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => handleAddBundleItem(idx)}
                                                className="button small dark"
                                            >
                                                <Plus size={14} /> {translate('portal', 'sce_add_product_to_package')}
                                            </button>
                                        </div>

                                        {/* Table of Bundled Items */}
                                        {bundleItems.length === 0 ? (
                                            <div className="ed-empty is-sm">
                                                {translate('portal', 'sce_package_empty')}
                                            </div>
                                        ) : (
                                            <div className="ed-table-wrap">
                                                <table className="ed-table">
                                                    <thead>
                                                        <tr>
                                                            <th className="ed-col-xs">#</th>
                                                            <th>{translate('portal', 'sce_col_product_name')}</th>
                                                            <th className="ed-col-md">{translate('portal', 'sce_col_list_price')}</th>
                                                            {bundleMode === 'itemized' && (
                                                                <>
                                                                    <th className="ed-col-md">{translate('portal', 'sce_col_item_discount_pct')}</th>
                                                                    <th className="ed-col-md">{translate('portal', 'sce_col_final_price')}</th>
                                                                </>
                                                            )}
                                                            <th className="is-num ed-col-sm">{translate('portal', 'sce_col_action')}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {bundleItems.map((it, itemIdx) => (
                                                            <tr key={itemIdx}>
                                                                <td className="ed-meta">{itemIdx + 1}</td>
                                                                <td>
                                                                    <strong className="ed-strong is-block">{it.name}</strong>
                                                                    {it.sku && <span className="ed-meta">{translate('portal', 'sce_sku_value', { sku: String(it.sku) })}</span>}
                                                                </td>
                                                                <td className="is-strong">৳ {parsePrice(it.mrp).toLocaleString()}</td>
                                                                {bundleMode === 'itemized' && (
                                                                    <>
                                                                        <td>
                                                                            <div className="ed-row is-tight">
                                                                                <input
                                                                                    type="number"
                                                                                    min={0}
                                                                                    max={99}
                                                                                    value={it.custom_discount_pct ?? bundlePct}
                                                                                    onChange={e => handleItemizedDiscountChange(idx, itemIdx, parseFloat(e.target.value) || 0)}
                                                                                    className="ed-input ed-pct"
                                                                                />
                                                                                <span>%</span>
                                                                            </div>
                                                                        </td>
                                                                        <td className="is-strong tone-info">
                                                                            ৳ {parsePrice(it.item_price).toLocaleString()}
                                                                        </td>
                                                                    </>
                                                                )}
                                                                <td className="is-num">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleRemoveBundleItem(idx, itemIdx)}
                                                                        className="button small danger"
                                                                    >
                                                                        ✕ {translate('portal', 'sce_remove')}
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}

                                        {/* Bundle Discount Mode & Controls */}
                                        {bundleItems.length > 0 && (
                                            <div className="ed-card is-compact">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline tone-info">
                                                        {translate('portal', 'sce_package_discount_mode')}
                                                    </span>
                                                    <div className="ed-row is-loose">
                                                        <label className={`ed-choice${bundleMode === 'total' ? ' is-active' : ''}`}>
                                                            <input
                                                                type="radio"
                                                                name={`bundle_mode_${idx}`}
                                                                checked={bundleMode === 'total'}
                                                                onChange={() => recalculateBundleCard(idx, bundleItems, 'total', bundlePct)}
                                                            />
                                                            {translate('portal', 'sce_package_mode_total')}
                                                        </label>
                                                        <label className={`ed-choice${bundleMode === 'itemized' ? ' is-active' : ''}`}>
                                                            <input
                                                                type="radio"
                                                                name={`bundle_mode_${idx}`}
                                                                checked={bundleMode === 'itemized'}
                                                                onChange={() => recalculateBundleCard(idx, bundleItems, 'itemized', bundlePct)}
                                                            />
                                                            {translate('portal', 'sce_package_mode_itemized')}
                                                        </label>
                                                    </div>
                                                </div>

                                                {bundleMode === 'total' && (
                                                    <div className="ed-row is-loose is-wrap">
                                                        <span className="ed-label is-inline">{translate('portal', 'sce_package_flat_discount_pct')}</span>
                                                        <div className="ed-row is-tight">
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={99}
                                                                value={card.bundle_discount_pct ?? 35}
                                                                onChange={e => recalculateBundleCard(idx, bundleItems, 'total', e.target.value)}
                                                                className="ed-input ed-pct tone-info"
                                                            />
                                                            <strong className="ed-strong tone-info">%</strong>
                                                        </div>

                                                        {/* Presets */}
                                                        <div className="ed-row is-wrap">
                                                            <span className="ed-meta">{translate('portal', 'sce_quick_presets')}</span>
                                                            {[20, 25, 30, 35, 40, 50].map(pct => (
                                                                <button
                                                                    key={pct}
                                                                    type="button"
                                                                    onClick={() => recalculateBundleCard(idx, bundleItems, 'total', pct)}
                                                                    className={`ed-chip is-info${bundlePct === pct ? ' is-active' : ''}`}
                                                                >
                                                                    {pct}%
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    {/* Bundle Financial Transparency Card */}
                                    {bundleItems.length > 0 && totalBundleMrp > 0 && (
                                        <div className="ed-callout tone-info ed-mb">
                                            <div className="ed-head is-sm">
                                                <span className="ed-strong ed-row">
                                                    📦 {translate('portal', 'sce_package_price_summary')}
                                                </span>
                                                <span className="hub-pill tone-info">
                                                    {translate('portal', 'sce_products_included_count', { count: String(bundleItems.length) })}
                                                </span>
                                            </div>
                                            <div className="ed-grid is-xs ed-mb-sm">
                                                <div className="ed-stat">
                                                    <span className="ed-meta is-block">{translate('portal', 'sce_total_combined_mrp')}</span>
                                                    <strong className="ed-strong">৳ {totalBundleMrp.toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted">
                                                    <span className="ed-meta is-block tone-info">{translate('portal', 'sce_package_offer_price')}</span>
                                                    <strong className="ed-strong is-lg tone-info">৳ {effectiveBundlePrice.toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted tone-danger">
                                                    <span className="ed-meta is-block tone-danger">{translate('portal', 'sce_total_package_savings')}</span>
                                                    <strong className="ed-strong tone-danger">৳ {effectiveBundleSave.toLocaleString()} ({effectiveBundleDiscountPct}%)</strong>
                                                </div>
                                            </div>
                                            <p className="ed-desc is-sm is-flush">
                                                ℹ️ <strong>{translate('portal', 'sce_package_pricing_label')}</strong>{' '}
                                                {withBoldValues(translate('portal', 'sce_package_pricing_body'), {
                                                    price: `৳${effectiveBundlePrice.toLocaleString()}`,
                                                })}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Card Content Grid (Common Fields) */}
                            <div className="ed-grid is-md">
                                {/* Tag */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_tag_badge')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.tag}
                                        placeholder={defCard.tag}
                                        onChange={e => handleFieldChange(idx, 'tag', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.tag ?? '') })}</small>
                                </div>

                                {/* Title */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_title')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.title}
                                        placeholder={defCard.title}
                                        onChange={e => handleFieldChange(idx, 'title', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.title ?? '') })}</small>
                                </div>

                                {/* Description */}
                                <div className="hub-span-all">
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_description')}
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={card.desc}
                                        placeholder={defCard.desc}
                                        onChange={e => handleFieldChange(idx, 'desc', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.desc ?? '') })}</small>
                                </div>

                                {/* Bullet 1 */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_feature_1')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.feat1}
                                        placeholder={defCard.feat1}
                                        onChange={e => handleFieldChange(idx, 'feat1', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.feat1 ?? '') })}</small>
                                </div>

                                {/* Bullet 2 */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_feature_2')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.feat2}
                                        placeholder={defCard.feat2}
                                        onChange={e => handleFieldChange(idx, 'feat2', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.feat2 ?? '') })}</small>
                                </div>

                                {/* Offer Price */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_price_example')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.price}
                                        placeholder={defCard.price}
                                        onChange={e => handleFieldChange(idx, 'price', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.price ?? '') })}</small>
                                </div>

                                {/* MRP */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_mrp_example')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.mrp}
                                        placeholder={defCard.mrp}
                                        onChange={e => handleFieldChange(idx, 'mrp', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.mrp ?? '') })}</small>
                                </div>

                                {/* Save Badge */}
                                <div>
                                    <div className="ed-head is-sm">
                                        <label className="ed-label is-inline">
                                            {translate('portal', 'sce_offer_save_badge_example')}
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => handleAutoCalculateSave(idx)}
                                            className="ed-mini tone-ok"
                                        >
                                            ⚡ {translate('portal', 'sce_calculate')}
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        value={card.save}
                                        placeholder={defCard.save}
                                        onChange={e => handleFieldChange(idx, 'save', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.save ?? '') })}</small>
                                </div>

                                {/* Action Button */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_button_text')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.btn}
                                        placeholder={defCard.btn}
                                        onChange={e => handleFieldChange(idx, 'btn', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.btn ?? '') })}</small>
                                </div>

                                {/* Destination Link */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_destination_link')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.link}
                                        placeholder={defCard.link}
                                        onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.link ?? '') })}</small>
                                </div>

                                {/* Offer Image */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_image_url_optional')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.image}
                                        placeholder="/branding/madina-side-banner-1.jpg"
                                        onChange={e => handleFieldChange(idx, 'image', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_offer_image_blank_hint')}</small>
                                </div>
                            </div>

                            {/* Live Card Preview */}
                            <div className="ed-foot">
                                <span className="ed-kicker ed-mb-sm">
                                    {translate('portal', 'sce_homepage_card_preview')}
                                </span>
                                <div className={`ed-preview-card${isHighlight ? ' is-brand' : cardType === 'bundle' ? ' is-info' : ''}`}>
                                    {card.image && (
                                        <div className="ed-thumb">
                                            <img src={card.image} alt={card.title} />
                                        </div>
                                    )}
                                    <div className="ed-head is-top is-sm">
                                        <h4 className="ed-title is-flush is-sm">
                                            {card.title || defCard.title}
                                        </h4>
                                        <span className={`ed-state-pill ${cardType === 'bundle' ? 'tone-info' : isHighlight ? 'tone-ok' : 'tone-brand'}`}>
                                            {card.tag || defCard.tag}
                                        </span>
                                    </div>
                                    <p className="ed-desc is-sm is-flush ed-mb-sm">
                                        {card.desc || defCard.desc}
                                    </p>

                                    {/* Bundle items preview pill in card */}
                                    {cardType === 'bundle' && bundleItems.length > 0 && (
                                        <div className="ed-callout tone-info is-sm ed-mb-sm">
                                            <strong>📦 {translate('portal', 'sce_package_includes', { count: String(bundleItems.length) })}</strong>
                                            <div className="ed-mt-xs">
                                                {bundleItems.map(it => it.name).join(' • ')}
                                            </div>
                                        </div>
                                    )}

                                    <div className="ed-checklist">
                                        {(card.feat1 || defCard.feat1) && (
                                            <span className="ed-row">
                                                <i className="fa-solid fa-circle-check tone-ok" /> {card.feat1 || defCard.feat1}
                                            </span>
                                        )}
                                        {(card.feat2 || defCard.feat2) && (
                                            <span className="ed-row">
                                                <i className="fa-solid fa-circle-check tone-ok" /> {card.feat2 || defCard.feat2}
                                            </span>
                                        )}
                                    </div>
                                    <div className="ed-row ed-mb">
                                        <strong className="ed-strong is-lg tone-ok">{card.price || defCard.price}</strong>
                                        {(card.mrp || defCard.mrp) && <del className="ed-hint">{card.mrp || defCard.mrp}</del>}
                                        {(card.save || defCard.save) && (
                                            <span className="hub-pill tone-danger">
                                                {card.save || defCard.save}
                                            </span>
                                        )}
                                    </div>
                                    <div className="ed-preview-cta">
                                        {card.btn || defCard.btn} →
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Bottom Add Card Actions */}
            <div className="ed-grid is-md ed-mt">
                <button
                    type="button"
                    onClick={handleAddSingle}
                    className="ed-add tone-ok"
                >
                    <Plus size={16} /> {translate('portal', 'sce_add_another_single_offer')}
                </button>
                <button
                    type="button"
                    onClick={handleAddBundle}
                    className="ed-add tone-info"
                >
                    <Plus size={16} /> {translate('portal', 'sce_add_another_package_offer')}
                </button>
            </div>
        </div>
    );
}
