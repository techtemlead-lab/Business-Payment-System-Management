import React from 'react';
import { Package, Plus, Trash2 } from 'lucide-react';
import { Field } from './formFields';
import { useT } from '../../shared/i18n/t';
import type { FeaturedProductItem, CustomizationData } from './types';

export function FeaturedProductsManager({
    customization,
    defaults,
    products,
    updateField,
    autoModeList,
}: {
    customization: CustomizationData;
    defaults: CustomizationData;
    products: { id: number; name: string; mrp: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    updateField: (key: keyof CustomizationData, val: any) => void;
    /** Shown in automatic mode in place of the read-only summary of featured products. */
    autoModeList?: React.ReactNode;
}) {
    const translate = useT();
    const isEnabled = customization.featured_products_enabled !== '0';
    const isTabsEnabled = customization.featured_products_tabs_enabled !== '0';
    const mode = customization.featured_products_mode || 'auto';
    const items = customization.featured_products_items || [];

    const handleAddItem = () => {
        const firstUnselected = products.find(p => !items.some(it => String(it.product_id) === String(p.id)));
        const newItem: FeaturedProductItem = {
            id: `fp-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            product_id: firstUnselected ? String(firstUnselected.id) : (products[0] ? String(products[0].id) : ''),
            marketing_badge: '★ Best Seller',
            override_title: '',
        };
        updateField('featured_products_items', [...items, newItem]);
    };

    const handleUpdateItem = (index: number, updates: Partial<FeaturedProductItem>) => {
        const updated = items.map((it, idx) => (idx === index ? { ...it, ...updates } : it));
        updateField('featured_products_items', updated);
    };

    const handleRemoveItem = (index: number) => {
        const updated = items.filter((_, idx) => idx !== index);
        updateField('featured_products_items', updated);
    };

    const handleMoveItem = (index: number, direction: 'up' | 'down') => {
        const targetIndex = direction === 'up' ? index - 1 : index + 1;
        if (targetIndex < 0 || targetIndex >= items.length) return;
        const copy = [...items];
        const [moved] = copy.splice(index, 1);
        copy.splice(targetIndex, 0, moved);
        updateField('featured_products_items', copy);
    };

    const quickBadges = [
        { label: '★ Best Seller', bg: '#006837', text: '#fff' },
        { label: '🔥 Hot Deal', bg: '#ef4444', text: '#fff' },
        { label: 'New Arrival', bg: '#0284c7', text: '#fff' },
        { label: '⚡ Popular', bg: '#f59e0b', text: '#fff' },
        { label: 'Trending', bg: '#7c3aed', text: '#fff' },
    ];

    const catalogFeatured = products.filter(p => p.featured);

    return (
        <div className="ed-card is-soft is-lg ed-mb-lg">
            <div className="ed-head">
                <div>
                    <h3 className="ed-title is-flush ed-row">
                        <Package size={20} className="icon-ok" /> {translate('portal', 'sce_featured_products_section')}
                    </h3>
                    <p className="ed-desc">
                        {translate('portal', 'sce_featured_products_section_hint')}
                    </p>
                </div>
                <div className="ed-row is-loose">
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_enabled', isEnabled ? '0' : '1')}
                        className={`ed-toggle${isEnabled ? ' is-on' : ''}`}
                    >
                        <span className="ed-dot" />
                        {isEnabled
                            ? translate('portal', 'sce_section_active')
                            : translate('portal', 'sce_section_hidden_short')}
                    </button>
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_tabs_enabled', isTabsEnabled ? '0' : '1')}
                        className={`ed-toggle${isTabsEnabled ? ' is-on' : ''}`}
                    >
                        <span className="ed-dot" />
                        {isTabsEnabled
                            ? translate('portal', 'sce_filter_tabs_on')
                            : translate('portal', 'sce_filter_tabs_off')}
                    </button>
                </div>
            </div>

            {/* General Section Labels */}
            <div className="form-grid ed-grid is-md ed-mb">
                <Field
                    label={translate('portal', 'sce_f_section_label')}
                    fieldKey="featured_products_badge"
                    value={customization.featured_products_badge}
                    placeholder={defaults.featured_products_badge || 'POPULAR SELECTION'}
                    onChange={updateField}
                />
                <Field
                    label={translate('portal', 'sce_f_section_title')}
                    fieldKey="featured_products_title"
                    value={customization.featured_products_title}
                    placeholder={defaults.featured_products_title || 'Featured Products'}
                    onChange={updateField}
                />
                <Field
                    label={translate('portal', 'sce_f_section_subtitle')}
                    fieldKey="public_products_subtitle"
                    value={customization.public_products_subtitle}
                    placeholder={defaults.public_products_subtitle || 'Discover our product range and connect with an authorized dealer.'}
                    onChange={updateField}
                />
                <Field
                    label={translate('portal', 'sce_f_see_all_text')}
                    fieldKey="featured_products_view_all"
                    value={customization.featured_products_view_all}
                    placeholder={defaults.featured_products_view_all || 'View All Products'}
                    onChange={updateField}
                />
                <Field
                    label={translate('portal', 'sce_f_see_all_link')}
                    fieldKey="featured_products_view_all_link"
                    value={customization.featured_products_view_all_link}
                    placeholder={defaults.featured_products_view_all_link || '/products'}
                    onChange={updateField}
                />
            </div>

            {/* Showcase Selection Mode */}
            <div className="ed-card ed-mb">
                <div className="ed-strong ed-mb-sm">
                    {translate('portal', 'sce_product_mode')}
                </div>
                <div className="ed-row is-loose is-wrap">
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_mode', 'auto')}
                        className={`ed-mode${mode === 'auto' ? ' is-active' : ''}`}
                    >
                        <span className="ed-radio">
                            {mode === 'auto' && <span className="ed-dot" />}
                        </span>
                        <span>⚡ {translate('portal', 'sce_featured_mode_auto')}</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => updateField('featured_products_mode', 'curated')}
                        className={`ed-mode${mode === 'curated' ? ' is-active' : ''}`}
                    >
                        <span className="ed-radio">
                            {mode === 'curated' && <span className="ed-dot" />}
                        </span>
                        <span>✨ {translate('portal', 'sce_featured_mode_curated')}</span>
                    </button>
                </div>

                {mode === 'auto' && autoModeList}

                {mode === 'auto' && !autoModeList && (
                    <div className="ed-well ed-mt">
                        <div className="ed-strong ed-mb-xs">
                            ℹ️ {translate('portal', 'sce_catalog_featured_heading', { count: String(catalogFeatured.length) })}
                        </div>
                        {catalogFeatured.length > 0 ? (
                            <div className="ed-row is-wrap ed-mt-sm">
                                {catalogFeatured.map(p => (
                                    <span key={p.id} className="hub-pill tone-ok">
                                        ★ {p.name} [{p.category}] — {translate('portal', 'sce_amount_tk', { amount: String(p.mrp) })}
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <div className="ed-placeholder">
                                {translate('portal', 'sce_catalog_featured_empty')}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Curated Products List */}
            {mode === 'curated' && (
                <div className="ed-stack">
                    <div className="ed-head is-flush">
                        <span className="ed-strong">
                            {translate('portal', 'sce_curated_products_heading', { count: String(items.length) })}
                        </span>
                        <button
                            type="button"
                            onClick={handleAddItem}
                            className="button small dark"
                        >
                            <Plus size={14} /> {translate('portal', 'sce_add_product_to_showcase')}
                        </button>
                    </div>

                    {items.length === 0 ? (
                        <div className="ed-empty">
                            <Package size={32} className="ed-empty-icon icon-muted" />
                            <p className="ed-desc is-flush ed-mb">
                                {translate('portal', 'sce_curated_products_empty')}
                            </p>
                            <button
                                type="button"
                                onClick={handleAddItem}
                                className="button small dark"
                            >
                                {translate('portal', 'sce_add_first_showcase_product')}
                            </button>
                        </div>
                    ) : (
                        items.map((item, idx) => {
                            const selectedProd = products.find(p => String(p.id) === String(item.product_id));
                            return (
                                <div
                                    key={item.id || idx}
                                    className="ed-card ed-item-row"
                                >
                                    <div className="ed-grid is-md">
                                        {/* Product Selector */}
                                        <label className="ed-block">
                                            <span className="ed-label">
                                                {translate('portal', 'sce_select_product', { n: String(idx + 1) })}
                                            </span>
                                            <select
                                                value={item.product_id}
                                                onChange={e => handleUpdateItem(idx, { product_id: e.target.value })}
                                                className="ed-input is-sm"
                                            >
                                                <option value="">{translate('portal', 'sce_choose_product')}</option>
                                                {products.map(p => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name} [{p.category}] — {translate('portal', 'sce_amount_tk', { amount: String(p.mrp) })} {p.stock !== undefined ? translate('portal', 'sce_stock_label', { count: String(p.stock) }) : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        {/* Marketing Badge */}
                                        <div>
                                            <span className="ed-label">
                                                {translate('portal', 'sce_marketing_badge')}
                                            </span>
                                            <div className="ed-row is-wrap ed-mb-sm">
                                                {quickBadges.map(qb => (
                                                    <button
                                                        key={qb.label}
                                                        type="button"
                                                        onClick={() => handleUpdateItem(idx, { marketing_badge: qb.label })}
                                                        className="ed-chip"
                                                        style={item.marketing_badge === qb.label ? { borderColor: qb.bg, background: qb.bg, color: qb.text } : undefined}
                                                    >
                                                        {qb.label}
                                                    </button>
                                                ))}
                                                {item.marketing_badge && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleUpdateItem(idx, { marketing_badge: '' })}
                                                        className="ed-mini tone-danger"
                                                    >
                                                        {translate('portal', 'sce_clear')}
                                                    </button>
                                                )}
                                            </div>
                                            <input
                                                type="text"
                                                placeholder={translate('portal', 'sce_custom_badge_ph')}
                                                value={item.marketing_badge || ''}
                                                onChange={e => handleUpdateItem(idx, { marketing_badge: e.target.value })}
                                                className="ed-input is-sm"
                                            />
                                        </div>

                                        {/* Optional Title Override */}
                                        <label className="ed-block">
                                            <span className="ed-label">
                                                {translate('portal', 'sce_title_override_optional')}
                                            </span>
                                            <input
                                                type="text"
                                                placeholder={selectedProd ? selectedProd.name : translate('portal', 'sce_title_override_ph')}
                                                value={item.override_title || ''}
                                                onChange={e => handleUpdateItem(idx, { override_title: e.target.value })}
                                                className="ed-input is-sm"
                                            />
                                        </label>
                                    </div>

                                    {/* Action buttons (Move Up, Move Down, Delete) */}
                                    <div className="ed-stack is-tight is-center">
                                        <div className="ed-row is-tight">
                                            <button
                                                type="button"
                                                disabled={idx === 0}
                                                onClick={() => handleMoveItem(idx, 'up')}
                                                className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                                title={translate('portal', 'sce_move_up')}
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                disabled={idx === items.length - 1}
                                                onClick={() => handleMoveItem(idx, 'down')}
                                                className={`ed-icon-btn is-sm${idx === items.length - 1 ? ' is-disabled' : ''}`}
                                                title={translate('portal', 'sce_move_down')}
                                            >
                                                ↓
                                            </button>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveItem(idx)}
                                            className="ed-icon-btn tone-danger"
                                            title={translate('portal', 'sce_remove_from_showcase')}
                                        >
                                            <Trash2 size={14} />
                                        </button>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
