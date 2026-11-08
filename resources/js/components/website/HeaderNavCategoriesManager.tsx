import React, { useState } from 'react';
import { Sparkles, Tag, Package, RotateCcw, Plus, Trash2, ArrowUp, ArrowDown, List } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { notify } from '../../shared/notify';

const DEFAULT_NAV_CATEGORIES = [
    'Medical Equipment',
    'Leather Items',
    'Consumer Items',
    'Medicine',
    'Agriculture Product',
    'Cosmetics',
    'Furniture',
    'Service',
];

interface HeaderNavCategoriesManagerProps {
    value: string;
    defaultValue: string;
    onChange: (value: string) => void;
    featuredCategoriesRaw?: string;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
}

export function HeaderNavCategoriesManager({
    value,
    defaultValue,
    onChange,
    featuredCategoriesRaw = '',
    products = [],
}: HeaderNavCategoriesManagerProps) {
    const translate = useT();
    const [newCategoryName, setNewCategoryName] = useState('');
    const [bulkMode, setBulkMode] = useState(false);
    const [bulkInput, setBulkInput] = useState('');

    const rawTrimmed = (value || '').trim();
    const isCustomized = rawTrimmed.length > 0;

    // Parse current categories (or fallback to defaults)
    const activeCategories: string[] = isCustomized
        ? value.split(/\r?\n/).map(s => s.trim()).filter(Boolean)
        : DEFAULT_NAV_CATEGORIES;

    const handleAdd = () => {
        const trimmed = newCategoryName.trim();
        if (!trimmed) return;
        const currentList = isCustomized ? activeCategories : [...DEFAULT_NAV_CATEGORIES];
        if (currentList.some(c => c.toLowerCase() === trimmed.toLowerCase())) {
            notify(translate('portal', 'cms_alert_nav_dup', { name: trimmed }), 'error');
            return;
        }
        const updated = [...currentList, trimmed];
        onChange(updated.join('\n'));
        setNewCategoryName('');
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAdd();
        }
    };

    const handleRemove = (index: number) => {
        const currentList = isCustomized ? activeCategories : [...DEFAULT_NAV_CATEGORIES];
        const target = currentList[index];
        if (window.confirm(translate('portal', 'cms_confirm_remove_nav', { name: target }))) {
            const updated = currentList.filter((_, i) => i !== index);
            onChange(updated.join('\n'));
        }
    };

    const handleEdit = (index: number, newName: string) => {
        const currentList = isCustomized ? [...activeCategories] : [...DEFAULT_NAV_CATEGORIES];
        currentList[index] = newName;
        onChange(currentList.join('\n'));
    };

    const handleMove = (index: number, direction: 'up' | 'down') => {
        const targetIdx = direction === 'up' ? index - 1 : index + 1;
        const currentList = isCustomized ? [...activeCategories] : [...DEFAULT_NAV_CATEGORIES];
        if (targetIdx < 0 || targetIdx >= currentList.length) return;
        const temp = currentList[targetIdx];
        currentList[targetIdx] = currentList[index];
        currentList[index] = temp;
        onChange(currentList.join('\n'));
    };

    const handleResetDefaults = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_nav'))) {
            onChange(DEFAULT_NAV_CATEGORIES.join('\n'));
            setNewCategoryName('');
        }
    };

    const handleClearToPlaceholders = () => {
        if (window.confirm(translate('portal', 'cms_confirm_clear_nav'))) {
            onChange('');
            setNewCategoryName('');
        }
    };

    const handleSyncFeatured = () => {
        const names = featuredCategoriesRaw.split(/\r?\n/).map(l => l.split('|')[0].trim()).filter(Boolean);
        if (names.length === 0) {
            notify(translate('portal', 'cms_alert_no_featured'), 'error');
            return;
        }
        const unique = Array.from(new Set(names));
        onChange(unique.join('\n'));
    };

    const handleSyncProducts = () => {
        if (!products || products.length === 0) {
            notify(translate('portal', 'cms_alert_no_products'), 'error');
            return;
        }
        const cats = Array.from(new Set(products.map(p => p.category).filter(Boolean))).sort();
        if (cats.length === 0) {
            notify(translate('portal', 'cms_alert_no_categories'), 'error');
            return;
        }
        onChange(cats.join('\n'));
    };

    const handleToggleBulkMode = () => {
        if (!bulkMode) {
            setBulkInput(activeCategories.join('\n'));
        } else {
            const parsed = bulkInput.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
            onChange(parsed.join('\n'));
        }
        setBulkMode(!bulkMode);
    };

    return (
        <div className="ed-card is-lg hub-span-all ed-mt-sm">
            {/* Header / Title */}
            <div className="ed-head is-top">
                <div>
                    <div className="ed-row is-loose">
                        <div className="ed-icon tone-info">
                            <Tag size={18} />
                        </div>
                        <h4 className="ed-title is-flush">
                            {translate('portal', 'sce_nav_bar_heading')}
                        </h4>
                        <span className={`hub-pill${isCustomized ? ' tone-ok' : ''}`}>
                            {isCustomized
                                ? translate('portal', 'sce_nav_custom_active', { count: String(activeCategories.length) })
                                : translate('portal', 'sce_nav_default_placeholders')}
                        </span>
                    </div>
                    <p className="ed-desc">
                        {translate('portal', 'sce_nav_bar_hint')}
                    </p>
                </div>

                {/* Quick Action Buttons */}
                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleResetDefaults}
                        className="button small"
                        title={translate('portal', 'sce_nav_reset_eight_title')}
                    >
                        <RotateCcw size={13} /> {translate('portal', 'sce_nav_reset_eight')}
                    </button>
                    {isCustomized && (
                        <button
                            type="button"
                            onClick={handleClearToPlaceholders}
                            className="button small danger"
                            title={translate('portal', 'sce_clear_custom_list_title')}
                        >
                            <Trash2 size={13} /> {translate('portal', 'sce_clear_custom_list')}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={handleToggleBulkMode}
                        className={`button small ${bulkMode ? 'dark' : 'tone-info'}`}
                    >
                        <List size={13} /> {bulkMode ? translate('portal', 'sce_exit_bulk') : translate('portal', 'sce_bulk_edit')}
                    </button>
                </div>
            </div>

            {/* Notice / Status description */}
            {!isCustomized && (
                <div className="ed-note">
                    <span>
                        {translate('portal', 'sce_nav_defaults_notice')}
                    </span>
                </div>
            )}

            {/* Add New Category Box */}
            <div className="ed-toolbar is-soft">
                <div className="ed-search">
                    <input
                        type="text"
                        value={newCategoryName}
                        onChange={e => setNewCategoryName(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder={translate('portal', 'sce_add_category_ph')}
                        className="ed-input"
                    />
                </div>
                <button
                    type="button"
                    onClick={handleAdd}
                    disabled={!newCategoryName.trim()}
                    className={`button dark${newCategoryName.trim() ? '' : ' is-disabled'}`}
                >
                    <Plus size={15} /> {translate('portal', 'sce_add_option')}
                </button>
            </div>

            {/* Visual Cards vs Bulk Text Mode */}
            {bulkMode ? (
                <div className="ed-mb">
                    <label className="ed-label">
                        {translate('portal', 'sce_bulk_edit')}
                    </label>
                    <textarea
                        rows={8}
                        value={bulkInput}
                        onChange={e => setBulkInput(e.target.value)}
                        className="ed-input is-mono"
                    />
                    <div className="ed-row is-loose ed-mt-sm">
                        <button
                            type="button"
                            onClick={() => {
                                const parsed = bulkInput.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                                onChange(parsed.join('\n'));
                                setBulkMode(false);
                            }}
                            className="button small dark"
                        >
                            {translate('portal', 'sce_apply_return_to_cards')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setBulkMode(false)}
                            className="button small"
                        >
                            {translate('portal', 'sce_cancel')}
                        </button>
                    </div>
                </div>
            ) : (
                <div className="ed-grid is-list ed-mb">
                    {activeCategories.map((category, idx) => (
                        <div
                            key={idx}
                            className="ed-chip-row"
                        >
                            {/* Position Index Badge */}
                            <span className="ed-index">
                                #{idx + 1}
                            </span>

                            {/* Editable Input */}
                            <input
                                type="text"
                                value={category}
                                onChange={e => handleEdit(idx, e.target.value)}
                                className="ed-inline-input"
                                onFocus={e => {
                                    e.currentTarget.style.border = '1px solid #3b82f6';
                                    e.currentTarget.style.background = '#ffffff';
                                }}
                                onBlur={e => {
                                    e.currentTarget.style.border = '1px solid transparent';
                                    e.currentTarget.style.background = 'transparent';
                                }}
                            />

                            {/* Move Up / Move Down buttons */}
                            <div className="ed-row is-tight">
                                <button
                                    type="button"
                                    onClick={() => handleMove(idx, 'up')}
                                    disabled={idx === 0}
                                    className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                    title={translate('portal', 'sce_move_left_up')}
                                >
                                    <ArrowUp size={12} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleMove(idx, 'down')}
                                    disabled={idx === activeCategories.length - 1}
                                    className={`ed-icon-btn is-sm${idx === activeCategories.length - 1 ? ' is-disabled' : ''}`}
                                    title={translate('portal', 'sce_move_right_down')}
                                >
                                    <ArrowDown size={12} />
                                </button>
                            </div>

                            {/* Remove button */}
                            <button
                                type="button"
                                onClick={() => handleRemove(idx)}
                                className="ed-icon-btn is-sm tone-danger"
                                title={translate('portal', 'sce_remove_named', { name: category })}
                            >
                                <Trash2 size={13} />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {/* Sync Helpers */}
            <div className="ed-row is-wrap ed-mb">
                <button
                    type="button"
                    onClick={handleSyncFeatured}
                    className="button small light"
                >
                    <Sparkles size={13} /> {translate('portal', 'sce_sync_from_featured_categories')}
                </button>
                {products && products.length > 0 && (
                    <button
                        type="button"
                        onClick={handleSyncProducts}
                        className="button small tone-info"
                    >
                        <Package size={13} /> {translate('portal', 'sce_nav_load_from_products', { count: String(products.length) })}
                    </button>
                )}
            </div>

            {/* Live Storefront Bar Preview */}
            <div className="ed-card">
                <div className="ed-head is-sm">
                    <div className="ed-row">
                        <span className="ed-dot tone-ok"></span>
                        <strong className="ed-kicker">
                            {translate('portal', 'sce_nav_preview_heading')}
                        </strong>
                    </div>
                    <span className="ed-meta">
                        {translate('portal', 'sce_nav_preview_hint')}
                    </span>
                </div>

                <div className="ed-nav-preview">
                    {activeCategories.length === 0 ? (
                        <span className="ed-placeholder">
                            {translate('portal', 'cms_no_categories_selected')}
                        </span>
                    ) : (
                        activeCategories.map((cat, i) => (
                            <span
                                key={i}
                                className="ed-nav-preview-item"
                            >
                                {cat}
                            </span>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}
