import React, { useState } from 'react';
import { Plus } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import type { Shortcut } from './types';

const POPULAR_SHORTCUT_ICONS = [
    { icon: 'fa-seedling', labelKey: 'sce_sc_icon_seedling' },
    { icon: 'fa-comments', labelKey: 'sce_sc_icon_comments' },
    { icon: 'fa-hand-holding-heart', labelKey: 'sce_sc_icon_care' },
    { icon: 'fa-headset', labelKey: 'sce_sc_icon_support' },
    { icon: 'fa-diagram-project', labelKey: 'sce_sc_icon_projects' },
    { icon: 'fa-capsules', labelKey: 'sce_sc_icon_medicine' },
    { icon: 'fa-kit-medical', labelKey: 'sce_sc_icon_kit' },
    { icon: 'fa-user-doctor', labelKey: 'sce_sc_icon_doctor' },
    { icon: 'fa-spa', labelKey: 'sce_sc_icon_spa' },
    { icon: 'fa-truck-fast', labelKey: 'sce_sc_icon_delivery' },
    { icon: 'fa-shield-halved', labelKey: 'sce_sc_icon_quality' },
    { icon: 'fa-location-dot', labelKey: 'sce_sc_icon_locator' },
    { icon: 'fa-award', labelKey: 'sce_sc_icon_award' },
    { icon: 'fa-store', labelKey: 'sce_sc_icon_outlets' },
    { icon: 'fa-phone', labelKey: 'sce_sc_icon_hotline' },
    { icon: 'fa-envelope', labelKey: 'sce_sc_icon_email' },
    { icon: 'fa-circle-question', labelKey: 'sce_sc_icon_faq' },
    { icon: 'fa-gift', labelKey: 'sce_sc_icon_gifts' },
];

export function ShortcutsManager({
    shortcuts,
    defaultShortcuts,
    enabled,
    onToggleEnabled,
    onChange,
}: {
    shortcuts: Shortcut[];
    defaultShortcuts: Shortcut[];
    enabled: boolean;
    onToggleEnabled: (enabled: boolean) => void;
    onChange: (items: Shortcut[]) => void;
}) {
    const translate = useT();
    const [pickerOpenIdx, setPickerOpenIdx] = useState<number | null>(null);

    const handleAdd = () => {
        const newItem: Shortcut = {
            id: `sc-new-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            title: translate('portal', 'sce_new_shortcut_title'),
            subtitle: translate('portal', 'sce_new_shortcut_subtitle'),
            icon: 'fa-star',
            link: '/products',
        };
        onChange([...shortcuts, newItem]);
    };

    const handleRemove = (index: number) => {
        const target = shortcuts[index];
        const name = target?.title?.trim() || translate('portal', 'sce_shortcut_number', { number: String(index + 1) });
        if (window.confirm(translate('portal', 'cms_confirm_remove_named', { name }))) {
            const next = shortcuts.filter((_, idx) => idx !== index);
            onChange(next);
            if (pickerOpenIdx === index) setPickerOpenIdx(null);
        }
    };

    const handleMoveUp = (index: number) => {
        if (index <= 0) return;
        const next = [...shortcuts];
        const temp = next[index - 1];
        next[index - 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleMoveDown = (index: number) => {
        if (index >= shortcuts.length - 1) return;
        const next = [...shortcuts];
        const temp = next[index + 1];
        next[index + 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleFieldChange = (index: number, field: keyof Shortcut, val: string) => {
        const next = shortcuts.map((item, idx) => {
            if (idx === index) {
                return { ...item, [field]: val };
            }
            return item;
        });
        onChange(next);
    };

    const handleReset = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_shortcuts'))) {
            onChange(defaultShortcuts);
            setPickerOpenIdx(null);
        }
    };

    return (
        <div>
            {/* Section Visibility Switch */}
            <div className={`ed-switch-banner${enabled ? ' is-on tone-ok' : ''}`}>
                <div className="ed-row is-loose">
                    <div className="ed-switch-icon">
                        {enabled ? '✓' : '✕'}
                    </div>
                    <div>
                        <div className="ed-row">
                            <strong className="ed-strong is-lg">
                                {translate('portal', 'sce_shortcuts_section_status', {
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
                                ? translate('portal', 'sce_shortcuts_enabled_hint')
                                : translate('portal', 'sce_shortcuts_disabled_hint')}
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

            {/* Action Bar */}
            <div className="ed-head">
                <div className="ed-row is-loose">
                    <h3 className="ed-title is-flush">
                        {translate('portal', 'sce_shortcuts_heading', { count: String(shortcuts.length) })}
                    </h3>
                    <span className="ed-desc is-sm">
                        {translate('portal', 'sce_shortcuts_manage_hint')}
                    </span>
                </div>
                <div className="ed-row">
                    <button
                        type="button"
                        onClick={handleAdd}
                        className="button small dark"
                    >
                        <Plus size={14} /> {translate('portal', 'sce_add_shortcut')}
                    </button>
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small"
                    >
                        {translate('portal', 'sce_reset_five_shortcuts')}
                    </button>
                </div>
            </div>

            {/* List of Shortcuts */}
            <div className="ed-stack">
                {shortcuts.map((sc, idx) => {
                    const defSc = defaultShortcuts[idx] || { title: translate('portal', 'sce_shortcut_fallback_title'), subtitle: translate('portal', 'sce_shortcut_fallback_subtitle'), icon: 'fa-seedling', link: '/products' };
                    const isPickerOpen = pickerOpenIdx === idx;
                    const isImg = (sc.icon || '').startsWith('/') || (sc.icon || '').startsWith('http');
                    const cleanIconClass = (sc.icon || defSc.icon).startsWith('fa-') ? (sc.icon || defSc.icon) : `fa-${sc.icon || defSc.icon}`;

                    return (
                        <div
                            key={sc.id || idx}
                            className="ed-card"
                        >
                            {/* Card Header */}
                            <div className="ed-head is-ruled">
                                <div className="ed-row is-loose">
                                    <span className="hub-step">
                                        {idx + 1}
                                    </span>
                                    <strong className="ed-strong">
                                        {sc.title || defSc.title || translate('portal', 'sce_shortcut_number', { number: String(idx + 1) })}
                                    </strong>
                                    {sc.subtitle && (
                                        <span className="ed-desc is-sm">
                                            — {sc.subtitle}
                                        </span>
                                    )}
                                </div>
                                <div className="ed-row is-tight">
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
                                        disabled={idx === shortcuts.length - 1}
                                        onClick={() => handleMoveDown(idx)}
                                        title={translate('portal', 'sce_move_down')}
                                        className={`ed-icon-btn is-sm${idx === shortcuts.length - 1 ? ' is-disabled' : ''}`}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(idx)}
                                        title={translate('portal', 'sce_delete_shortcut')}
                                        className="button small danger"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>

                            {/* Card Body Grid */}
                            <div className="ed-grid is-md">
                                {/* Title */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_title')}
                                    </label>
                                    <input
                                        type="text"
                                        value={sc.title}
                                        placeholder={defSc.title}
                                        onChange={e => handleFieldChange(idx, 'title', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSc.title ?? '') })}</small>
                                </div>

                                {/* Subtitle */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_subtitle_caption')}
                                    </label>
                                    <input
                                        type="text"
                                        value={sc.subtitle}
                                        placeholder={defSc.subtitle}
                                        onChange={e => handleFieldChange(idx, 'subtitle', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSc.subtitle ?? '') })}</small>
                                </div>

                                {/* Icon */}
                                <div>
                                    <div className="ed-head is-sm">
                                        <label className="ed-label is-inline">
                                            {translate('portal', 'sce_icon_fa_or_image')}
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => setPickerOpenIdx(isPickerOpen ? null : idx)}
                                            className={`ed-mini ${isPickerOpen ? 'is-active' : 'tone-info'}`}
                                        >
                                            {isPickerOpen ? `✕ ${translate('portal', 'sce_close')}` : `🎨 ${translate('portal', 'sce_pick_icon')}`}
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        value={sc.icon}
                                        placeholder={defSc.icon}
                                        onChange={e => handleFieldChange(idx, 'icon', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSc.icon ?? '') })}</small>
                                </div>

                                {/* Link */}
                                <div>
                                    <div className="ed-head is-sm">
                                        <label className="ed-label is-inline">
                                            {translate('portal', 'sce_target_link')}
                                        </label>
                                        <div className="ed-row is-tight">
                                            {[
                                                { label: translate('portal', 'sce_link_preset_products'), url: '/products' },
                                                { label: translate('portal', 'sce_link_preset_dealers'), url: '/dealers' },
                                                { label: translate('portal', 'sce_link_preset_about'), url: '/about' },
                                                { label: translate('portal', 'sce_link_preset_complaint'), url: '/contact?topic=complaint' },
                                            ].map(preset => (
                                                <button
                                                    key={preset.url}
                                                    type="button"
                                                    onClick={() => handleFieldChange(idx, 'link', preset.url)}
                                                    className="ed-mini"
                                                >
                                                    {preset.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <input
                                        type="text"
                                        value={sc.link}
                                        placeholder={defSc.link}
                                        onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">
                                        {translate('portal', 'sce_default_placeholder', { value: defSc.link })}
                                    </small>
                                </div>
                            </div>

                            {/* Icon Picker Popdown */}
                            {isPickerOpen && (
                                <div className="ed-picker">
                                    <div className="ed-head is-sm">
                                        <strong className="ed-strong is-sm tone-ok">{translate('portal', 'sce_choose_icon')}</strong>
                                        <span className="ed-meta">{translate('portal', 'cms_click_icon_apply_short')}</span>
                                    </div>
                                    <div className="ed-grid is-xs">
                                        {POPULAR_SHORTCUT_ICONS.map(ic => (
                                            <button
                                                key={ic.icon}
                                                type="button"
                                                onClick={() => {
                                                    handleFieldChange(idx, 'icon', ic.icon);
                                                    setPickerOpenIdx(null);
                                                }}
                                                className={`ed-option${sc.icon === ic.icon ? ' is-active' : ''}`}
                                            >
                                                <i className={`fa-solid ${ic.icon} ed-picker-icon`} />
                                                <span className="ed-truncate">
                                                    {translate('portal', ic.labelKey)}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Live Card Preview Box */}
                            <div className="ed-foot ed-head is-flush">
                                <span className="ed-kicker">
                                    {translate('portal', 'sce_homepage_card_preview')}
                                </span>
                                <div className="ed-preview-chip">
                                    {isImg ? (
                                        <img src={sc.icon} alt={sc.title} className="ed-icon-img is-lg" />
                                    ) : (
                                        <i className={`fa-solid ${cleanIconClass} ed-preview-icon`} />
                                    )}
                                    <div>
                                        <div className="ed-strong">
                                            {sc.title || defSc.title}
                                        </div>
                                        {(sc.subtitle || defSc.subtitle) && (
                                            <div className="ed-meta">
                                                {sc.subtitle || defSc.subtitle}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Bottom Add Shortcut Button */}
            <div className="ed-row is-center ed-mt">
                <button
                    type="button"
                    onClick={handleAdd}
                    className="ed-add tone-ok"
                >
                    <Plus size={16} /> {translate('portal', 'sce_add_another_shortcut_card')}
                </button>
            </div>
        </div>
    );
}
