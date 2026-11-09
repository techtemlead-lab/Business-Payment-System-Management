import React, { useState } from 'react';
import { Image, Plus, Trash2 } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { notify } from '../../shared/notify';

interface CategoryItem {
    id: string;
    name: string;
    icon: string;
    link: string;
    badge?: string;
}

const POPULAR_CATEGORY_ICONS = [
    {
        groupKey: 'sce_icon_group_health',
        icons: [
            { icon: 'fa-briefcase-medical', labelKey: 'sce_icon_medical_equipment' },
            { icon: 'fa-prescription-bottle-medical', labelKey: 'sce_icon_unani_herbal' },
            { icon: 'fa-capsules', labelKey: 'sce_icon_capsules' },
            { icon: 'fa-tablets', labelKey: 'sce_icon_tablets' },
            { icon: 'fa-pills', labelKey: 'sce_icon_pills' },
            { icon: 'fa-stethoscope', labelKey: 'sce_icon_diagnostics' },
            { icon: 'fa-kit-medical', labelKey: 'sce_icon_first_aid' },
            { icon: 'fa-mortar-pestle', labelKey: 'sce_icon_herbal_compounding' },
            { icon: 'fa-heart-pulse', labelKey: 'sce_icon_cardio' },
            { icon: 'fa-bandage', labelKey: 'sce_icon_bandages' },
            { icon: 'fa-hand-holding-medical', labelKey: 'sce_icon_health_support' },
            { icon: 'fa-shield-halved', labelKey: 'sce_icon_health_protection' },
        ],
    },
    {
        groupKey: 'sce_icon_group_herbal',
        icons: [
            { icon: 'fa-leaf', labelKey: 'sce_icon_pure_herbal' },
            { icon: 'fa-seedling', labelKey: 'sce_icon_agriculture' },
            { icon: 'fa-bowl-food', labelKey: 'sce_icon_food_supplement' },
            { icon: 'fa-bottle-droplet', labelKey: 'sce_icon_oils' },
            { icon: 'fa-apple-whole', labelKey: 'sce_icon_diet' },
            { icon: 'fa-spa', labelKey: 'sce_icon_wellness' },
            { icon: 'fa-tree', labelKey: 'sce_icon_nature' },
            { icon: 'fa-cannabis', labelKey: 'sce_icon_botanical' },
            { icon: 'fa-wheat-awn', labelKey: 'sce_icon_grains' },
            { icon: 'fa-fish', labelKey: 'sce_icon_meat_fish' },
        ],
    },
    {
        groupKey: 'sce_icon_group_beauty',
        icons: [
            { icon: 'fa-pump-medical', labelKey: 'sce_icon_personal_care' },
            { icon: 'fa-gem', labelKey: 'sce_icon_cosmetics' },
            { icon: 'fa-sparkles', labelKey: 'sce_icon_skin_glow' },
            { icon: 'fa-feather-pointed', labelKey: 'sce_icon_gentle_care' },
            { icon: 'fa-shirt', labelKey: 'sce_icon_fashion' },
            { icon: 'fa-briefcase', labelKey: 'sce_icon_leather' },
            { icon: 'fa-water', labelKey: 'sce_icon_water' },
            { icon: 'fa-mug-hot', labelKey: 'sce_icon_kettle' },
            { icon: 'fa-fire-burner', labelKey: 'sce_icon_cookeries' },
            { icon: 'fa-temperature-arrow-up', labelKey: 'sce_icon_heater' },
            { icon: 'fa-couch', labelKey: 'sce_icon_furniture' },
            { icon: 'fa-laptop', labelKey: 'sce_icon_electronics' },
            { icon: 'fa-handshake-angle', labelKey: 'sce_icon_service' },
        ],
    },
    {
        groupKey: 'sce_icon_group_general',
        icons: [
            { icon: 'fa-tag', labelKey: 'sce_icon_default_tag' },
            { icon: 'fa-bag-shopping', labelKey: 'sce_icon_shopping_bag' },
            { icon: 'fa-box-open', labelKey: 'sce_icon_product_box' },
            { icon: 'fa-star', labelKey: 'sce_icon_top_rated' },
            { icon: 'fa-gift', labelKey: 'sce_icon_special_offer' },
            { icon: 'fa-award', labelKey: 'sce_icon_certified' },
        ],
    },
];

function parseItems(raw: string): CategoryItem[] {
    const lines = (raw || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    return lines.map((line, idx) => {
        const parts = line.split('|').map(p => p.trim());
        return {
            id: `cat-${idx}-${line.slice(0, 10).replace(/[^a-zA-Z0-9]/g, '_')}`,
            name: parts[0] || '',
            icon: parts[1] || 'fa-tag',
            link: parts[2] || '',
            badge: parts[3] || '',
        };
    });
}

function serializeItems(items: CategoryItem[]): string {
    return items
        .filter(it => it.name.trim() !== '')
        .map(it => {
            const name = it.name.trim();
            const icon = (it.icon || 'fa-tag').trim();
            const link = (it.link || '').trim();
            const badge = (it.badge || '').trim();
            if (badge) {
                return `${name} | ${icon} | ${link} | ${badge}`;
            }
            if (link) {
                return `${name} | ${icon} | ${link}`;
            }
            return `${name} | ${icon}`;
        })
        .join('\n');
}

export function CategoryManager({
    value,
    defaultValue,
    onChange,
    products = []
}: {
    value?: string;
    defaultValue?: string;
    onChange: (val: string) => void;
    products?: { id: number; name: string; mrp: string; category: string }[];
}) {
    const translate = useT();
    const [mode, setMode] = useState<'visual' | 'text'>('visual');
    const [pickerOpenIdx, setPickerOpenIdx] = useState<number | null>(null);
    const [uploadingIdx, setUploadingIdx] = useState<number | null>(null);

    const uploadCategoryImage = async (index: number, file: File | null) => {
        if (!file) {
            return;
        }

        setUploadingIdx(index);
        try {
            const body = new FormData();
            body.append('image', file);
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                || (document.querySelector('input[name="_token"]') as HTMLInputElement | null)?.value
                || '';
            if (token) {
                body.append('_token', token);
            }

            const response = await fetch('/commands/customization/category-icon', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
            });

            const payload = await response.json().catch(() => null) as { ok?: boolean; url?: string; message?: string; errors?: { image?: string[] } } | null;
            if (!response.ok || !payload?.ok || !payload.url) {
                throw new Error(payload?.errors?.image?.[0] || payload?.message || translate('portal', 'sce_category_image_upload_failed'));
            }

            handleFieldChange(index, 'icon', payload.url);
        } catch (error) {
            notify(error instanceof Error ? error.message : translate('portal', 'sce_category_image_upload_failed'), 'error');
        } finally {
            setUploadingIdx(null);
        }
    };

    const rawValue = typeof value === 'string' ? value : (defaultValue || '');
    const items = parseItems(rawValue);

    const updateAndEmit = (newItems: CategoryItem[]) => {
        onChange(serializeItems(newItems));
    };

    const handleAddItem = () => {
        const newItem: CategoryItem = {
            id: `cat-new-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            name: translate('portal', 'sce_new_category'),
            icon: 'fa-tag',
            link: '',
            badge: '',
        };
        updateAndEmit([...items, newItem]);
    };

    const handleRemoveItem = (index: number) => {
        const target = items[index];
        if (!target) return;
        const msg = target.name.trim()
            ? translate('portal', 'cms_confirm_remove_category_named', { name: target.name })
            : translate('portal', 'cms_confirm_remove_category');
        if (window.confirm(msg)) {
            const next = items.filter((_, idx) => idx !== index);
            updateAndEmit(next);
            if (pickerOpenIdx === index) setPickerOpenIdx(null);
        }
    };

    const handleMoveUp = (index: number) => {
        if (index <= 0) return;
        const next = [...items];
        const temp = next[index - 1];
        next[index - 1] = next[index];
        next[index] = temp;
        updateAndEmit(next);
    };

    const handleMoveDown = (index: number) => {
        if (index >= items.length - 1) return;
        const next = [...items];
        const temp = next[index + 1];
        next[index + 1] = next[index];
        next[index] = temp;
        updateAndEmit(next);
    };

    const handleFieldChange = (index: number, field: keyof CategoryItem, val: string) => {
        const next = items.map((item, idx) => {
            if (idx === index) {
                return { ...item, [field]: val };
            }
            return item;
        });
        updateAndEmit(next);
    };

    const handleReset = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_categories'))) {
            onChange(defaultValue || '');
            setPickerOpenIdx(null);
        }
    };

    const handleClearAll = () => {
        if (window.confirm(translate('portal', 'cms_confirm_clear_categories'))) {
            onChange('');
            setPickerOpenIdx(null);
        }
    };

    const handleLoadFromProducts = () => {
        const catalogCats = Array.from(new Set(products.map(p => p.category).filter(Boolean))).sort();
        if (catalogCats.length === 0) {
            notify(translate('portal', 'cms_alert_no_product_categories'), 'error');
            return;
        }

        const newItems: CategoryItem[] = catalogCats.map((cat, idx) => {
            const lower = cat.toLowerCase();
            let icon = 'fa-tag';
            if (lower.includes('medicine') || lower.includes('unani') || lower.includes('syrup')) icon = 'fa-prescription-bottle-medical';
            else if (lower.includes('supplement') || lower.includes('food')) icon = 'fa-bowl-food';
            else if (lower.includes('care') || lower.includes('personal')) icon = 'fa-pump-medical';
            else if (lower.includes('cosmetic') || lower.includes('beauty')) icon = 'fa-gem';
            else if (lower.includes('equipment') || lower.includes('device') || lower.includes('diagnostic')) icon = 'fa-briefcase-medical';
            else if (lower.includes('herb') || lower.includes('natural') || lower.includes('plant')) icon = 'fa-leaf';
            else if (lower.includes('agri')) icon = 'fa-seedling';
            else if (lower.includes('water')) icon = 'fa-water';
            else if (lower.includes('service')) icon = 'fa-handshake-angle';
            else if (lower.includes('leather')) icon = 'fa-briefcase';
            else if (lower.includes('furniture')) icon = 'fa-couch';
            else if (lower.includes('meat') || lower.includes('fish')) icon = 'fa-fish';
            else if (lower.includes('kettle')) icon = 'fa-mug-hot';
            else if (lower.includes('cook')) icon = 'fa-fire-burner';
            else if (lower.includes('heater')) icon = 'fa-temperature-arrow-up';
            else if (lower.includes('electronic')) icon = 'fa-laptop';
            else if (lower.includes('fashion')) icon = 'fa-shirt';

            return {
                id: `cat-prod-${idx}-${Date.now()}`,
                name: cat,
                icon,
                link: `/products?category=${encodeURIComponent(cat)}`,
            };
        });

        updateAndEmit(newItems);
        setPickerOpenIdx(null);
    };

    return (
        <div className="ed-card is-soft">
            {/* Header & Controls Toolbar */}
            <div className="ed-head">
                <div>
                    <div className="ed-row is-loose">
                        <h4 className="ed-subtitle is-flush">
                            {translate('portal', 'sce_featured_categories_manager')}
                        </h4>
                        <span className="hub-pill">
                            {translate('portal', items.length === 1 ? 'sce_category_count_one' : 'sce_category_count_many', { count: String(items.length) })}
                        </span>
                    </div>
                    <small className="ed-meta is-block">
                        {translate('portal', 'sce_featured_categories_manager_hint')}
                    </small>
                </div>

                {/* Mode Switcher */}
                <div className="hub-seg">
                    <button
                        type="button"
                        onClick={() => setMode('visual')}
                        className={mode === 'visual' ? 'is-active' : undefined}
                    >
                        🎨 {translate('portal', 'sce_visual_builder')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('text')}
                        className={mode === 'text' ? 'is-active' : undefined}
                    >
                        📝 {translate('portal', 'sce_raw_text_mode')}
                    </button>
                </div>
            </div>

            {/* Quick Action Freedom Bar */}
            <div className="ed-toolbar">
                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleAddItem}
                        className="button small dark"
                    >
                        <Plus size={15} /> {translate('portal', 'sce_add_new_category')}
                    </button>
                    {products.length > 0 && (
                        <button
                            type="button"
                            onClick={handleLoadFromProducts}
                            className="button small light"
                        >
                            ⚡ {translate('portal', 'sce_load_from_products', { count: String(products.length) })}
                        </button>
                    )}
                </div>

                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small tone-info"
                    >
                        ↺ {translate('portal', 'sce_reset_reference_defaults')}
                    </button>
                    <button
                        type="button"
                        onClick={handleClearAll}
                        className="button small danger"
                    >
                        🗑️ {translate('portal', 'sce_clear_all')}
                    </button>
                </div>
            </div>

            {/* Visual Builder Mode */}
            {mode === 'visual' && (
                <div>
                    {items.length === 0 ? (
                        <div className="ed-empty">
                            <div className="ed-empty-icon">🏷️</div>
                            <strong className="ed-strong is-lg is-block">{translate('portal', 'cms_no_categories_yet')}</strong>
                            <p className="ed-desc ed-mb">
                                {translate('portal', 'sce_no_categories_hint')}
                            </p>
                            <div className="ed-row is-loose is-center">
                                <button
                                    type="button"
                                    onClick={handleAddItem}
                                    className="button small dark"
                                >
                                    <Plus size={16} /> {translate('portal', 'sce_add_first_category')}
                                </button>
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    className="button small"
                                >
                                    ↺ {translate('portal', 'sce_restore_reference_defaults')}
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="ed-stack">
                            {items.map((item, idx) => {
                                const isImage = (item.icon || '').startsWith('http') || (item.icon || '').startsWith('/') || /\.(png|jpe?g|svg|webp|gif)/i.test(item.icon || '');
                                const isPickerOpen = pickerOpenIdx === idx;

                                return (
                                    <div
                                        key={item.id || idx}
                                        className="ed-card"
                                    >
                                        {/* Card Top Bar */}
                                        <div className="ed-head is-ruled">
                                            <div className="ed-row is-loose">
                                                {/* Reorder Buttons */}
                                                <div className="ed-row is-tight">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMoveUp(idx)}
                                                        disabled={idx === 0}
                                                        title={translate('portal', 'sce_move_category_up')}
                                                        className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                                    >
                                                        ▲
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMoveDown(idx)}
                                                        disabled={idx === items.length - 1}
                                                        title={translate('portal', 'sce_move_category_down')}
                                                        className={`ed-icon-btn is-sm${idx === items.length - 1 ? ' is-disabled' : ''}`}
                                                    >
                                                        ▼
                                                    </button>
                                                </div>

                                                {/* Item Number */}
                                                <span className="hub-pill">
                                                    #{idx + 1}
                                                </span>

                                                {/* Live Icon / Image Preview */}
                                                <div
                                                    className="ed-icon tone-ok"
                                                >
                                                    {isImage ? (
                                                        <img src={item.icon} alt={item.name} className="ed-icon-img" />
                                                    ) : (
                                                        <i className={`fa-solid ${item.icon || 'fa-tag'}`} aria-hidden="true" />
                                                    )}
                                                </div>

                                                <strong className="ed-strong">
                                                    {item.name || <span className="ed-placeholder">{translate('portal', 'sce_untitled_category')}</span>}
                                                </strong>

                                                {/* Active Products Count & Status Pill */}
                                                {item.name.trim() !== '' && (
                                                    (() => {
                                                        const count = products.filter(p => (p.category || '').trim().toLowerCase() === item.name.trim().toLowerCase()).length;
                                                        return count > 0 ? (
                                                            <span className="hub-pill tone-ok">
                                                                ✓ {translate('portal', count === 1 ? 'sce_active_product_count_one' : 'sce_active_product_count_many', { count: String(count) })}
                                                            </span>
                                                        ) : (
                                                            <span className="hub-pill tone-warn" title={translate('portal', 'sce_no_active_products_in_category')}>
                                                                ⚠️ {translate('portal', 'sce_zero_catalog_products')}
                                                            </span>
                                                        );
                                                    })()
                                                )}

                                                {/* Badge Indicator Pill */}
                                                {item.badge && (
                                                    <span className="hub-pill tone-danger">
                                                        ★ {item.badge}
                                                    </span>
                                                )}
                                            </div>

                                            {/* Remove / Delete Button */}
                                            <button
                                                type="button"
                                                onClick={() => handleRemoveItem(idx)}
                                                className="button small danger"
                                            >
                                                <Trash2 size={13} /> {translate('portal', 'sce_remove')}
                                            </button>
                                        </div>

                                        {/* Card Input Fields */}
                                        <div className="ed-grid is-sm">
                                            {/* Category Name */}
                                            <label className="ed-block">
                                                <span className="ed-label">
                                                    {translate('portal', 'sce_category_name')}
                                                </span>
                                                <input
                                                    type="text"
                                                    value={item.name}
                                                    placeholder={translate('portal', 'sce_category_name_ph')}
                                                    onChange={e => handleFieldChange(idx, 'name', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                            </label>

                                            {/* Icon Field & Quick Picker Toggle */}
                                            <label className="ed-block">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline">
                                                        {translate('portal', 'sce_icon_label')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => setPickerOpenIdx(isPickerOpen ? null : idx)}
                                                        className={`ed-mini ${isPickerOpen ? 'is-active' : 'tone-info'}`}
                                                    >
                                                        {isPickerOpen ? translate('portal', 'sce_close_picker') : translate('portal', 'sce_pick_icon')}
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.icon}
                                                    placeholder={translate('portal', 'sce_icon_ph')}
                                                    onChange={e => handleFieldChange(idx, 'icon', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                                <div className="ed-row is-wrap ed-mt-sm">
                                                    <label
                                                        className={`button small light${uploadingIdx === idx ? ' ed-busy' : ''}`}
                                                    >
                                                        <Image size={13} />
                                                        {uploadingIdx === idx
                                                            ? translate('portal', 'sce_uploading_category_image')
                                                            : translate('portal', 'sce_upload_category_image')}
                                                        <input
                                                            type="file"
                                                            accept="image/png,image/jpeg,image/webp"
                                                            disabled={uploadingIdx === idx}
                                                            className="ed-hidden"
                                                            onChange={e => {
                                                                const file = e.target.files?.[0] ?? null;
                                                                e.target.value = '';
                                                                void uploadCategoryImage(idx, file);
                                                            }}
                                                        />
                                                    </label>
                                                    <small className="ed-meta">
                                                        {translate('portal', 'sce_category_image_hint')}
                                                    </small>
                                                </div>
                                            </label>

                                            {/* Destination Link */}
                                            <label className="ed-block">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline">
                                                        {translate('portal', 'sce_target_url_optional')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            const autoLink = `/products?category=${encodeURIComponent(item.name.trim())}`;
                                                            handleFieldChange(idx, 'link', autoLink);
                                                        }}
                                                        className="ed-mini"
                                                    >
                                                        {translate('portal', 'sce_auto_catalog')}
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.link}
                                                    placeholder={`/products?category=${encodeURIComponent(item.name || translate('portal', 'sce_category_fallback'))}`}
                                                    onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                            </label>

                                            {/* Promotional Badge */}
                                            <label className="ed-block">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline">
                                                        {translate('portal', 'sce_promotional_badge_optional')}
                                                    </span>
                                                    <div className="ed-row is-tight">
                                                        {['HOT', 'NEW', 'POPULAR', ''].map(tag => (
                                                            <button
                                                                key={tag || 'none'}
                                                                type="button"
                                                                onClick={() => handleFieldChange(idx, 'badge', tag)}
                                                                className={`ed-mini${item.badge === tag ? ' is-active' : ''}`}
                                                            >
                                                                {tag || translate('portal', 'sce_clear')}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.badge || ''}
                                                    placeholder={translate('portal', 'sce_badge_ph')}
                                                    onChange={e => handleFieldChange(idx, 'badge', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                            </label>
                                        </div>

                                        {/* Dropdown Icon Selector (Opens inline if toggled) */}
                                        {isPickerOpen && (
                                            <div
                                                className="ed-picker"
                                            >
                                                <div className="ed-head is-sm">
                                                    <strong className="ed-strong is-sm tone-ok">
                                                        {translate('portal', 'sce_select_icon_for', { name: item.name || translate('portal', 'sce_category_fallback') })}
                                                    </strong>
                                                    <span className="ed-meta">
                                                        {translate('portal', 'cms_click_icon_apply')}
                                                    </span>
                                                </div>

                                                <div className="ed-stack">
                                                    {POPULAR_CATEGORY_ICONS.map(group => (
                                                        <div key={group.groupKey}>
                                                            <div className="ed-group-label">
                                                                {translate('portal', group.groupKey)}
                                                            </div>
                                                            <div className="ed-grid is-xs">
                                                                {group.icons.map(ic => (
                                                                    <button
                                                                        key={ic.icon}
                                                                        type="button"
                                                                        onClick={() => {
                                                                            handleFieldChange(idx, 'icon', ic.icon);
                                                                            setPickerOpenIdx(null);
                                                                        }}
                                                                        className={`ed-option${item.icon === ic.icon ? ' is-active' : ''}`}
                                                                    >
                                                                        <i className={`fa-solid ${ic.icon} ed-picker-icon`} />
                                                                        <span className="ed-truncate">
                                                                            {translate('portal', ic.labelKey)}
                                                                        </span>
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}

                            {/* Bottom Add Category Button */}
                            <div className="ed-row is-center ed-mt-sm">
                                <button
                                    type="button"
                                    onClick={handleAddItem}
                                    className="ed-add tone-ok"
                                >
                                    <Plus size={16} /> {translate('portal', 'sce_add_another_category')}
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Raw Text / Bulk Editor Mode */}
            {mode === 'text' && (
                <div>
                    <div className="ed-desc is-sm ed-mb-sm">
                        {translate('portal', 'sce_raw_categories_format_prefix')}{' '}
                        <code>{translate('portal', 'sce_raw_categories_format_code')}</code>
                    </div>
                    <textarea
                        rows={12}
                        value={rawValue}
                        placeholder={defaultValue}
                        onChange={e => onChange(e.target.value)}
                        className="ed-input is-sm is-mono"
                    />
                    <small className="ed-meta is-block">
                        {translate('portal', 'sce_raw_categories_tip')}
                    </small>
                </div>
            )}
        </div>
    );
}
