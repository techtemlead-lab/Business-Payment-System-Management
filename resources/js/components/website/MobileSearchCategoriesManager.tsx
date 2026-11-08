import React, { useState } from 'react';
import { Plus, Trash2, ArrowUp, ArrowDown } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { notify } from '../../shared/notify';
import type { CustomizationData } from './types';

interface MobileSearchCategoriesManagerProps {
    enabled: string;
    heading: string;
    items: string;
    limit: string;
    featuredCategoriesRaw?: string;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    onChange: (field: keyof CustomizationData, value: string) => void;
}

export function MobileSearchCategoriesManager({
    enabled,
    heading,
    items,
    limit,
    featuredCategoriesRaw = '',
    products = [],
    onChange,
}: MobileSearchCategoriesManagerProps) {
    const translate = useT();
    const [newName, setNewName] = useState('');
    const isOn = enabled !== '0';
    const lines = (items || '').split(/\r?\n/).map((s) => s.trim()).filter(Boolean);

    const emitLines = (next: string[]) => onChange('mobile_search_categories_items', next.join('\n'));

    const handleAdd = () => {
        const trimmed = newName.trim();
        if (!trimmed) return;
        if (lines.some((l) => l.split('|')[0].trim().toLowerCase() === trimmed.toLowerCase())) {
            notify(translate('portal', 'cms_alert_nav_dup', { name: trimmed }), 'error');
            return;
        }
        emitLines([...lines, trimmed]);
        setNewName('');
    };

    const handleRemove = (index: number) => {
        const target = lines[index]?.split('|')[0]?.trim() || '';
        if (!window.confirm(translate('portal', 'cms_confirm_remove_mobile_search_cat', { name: target }))) {
            return;
        }
        emitLines(lines.filter((_, i) => i !== index));
    };

    const handleMove = (index: number, direction: 'up' | 'down') => {
        const targetIdx = direction === 'up' ? index - 1 : index + 1;
        if (targetIdx < 0 || targetIdx >= lines.length) return;
        const next = [...lines];
        const tmp = next[targetIdx];
        next[targetIdx] = next[index];
        next[index] = tmp;
        emitLines(next);
    };

    const handleSyncFeatured = () => {
        const names = featuredCategoriesRaw.split(/\r?\n/).map((l) => l.split('|')[0].trim()).filter(Boolean);
        if (names.length === 0) {
            notify(translate('portal', 'cms_alert_no_featured'), 'error');
            return;
        }
        emitLines(Array.from(new Set(names)));
    };

    const handleSyncProducts = () => {
        const cats = Array.from(new Set((products || []).map((p) => p.category).filter(Boolean))).sort();
        if (cats.length === 0) {
            notify(translate('portal', 'cms_alert_no_categories'), 'error');
            return;
        }
        emitLines(cats);
    };

    return (
        <div className={`ed-card is-lg hub-span-all ed-mt-sm${isOn ? ' is-on' : ' is-soft'}`}>
            <div className="ed-head">
                <div>
                    <h4 className="ed-title is-flush">
                        {translate('portal', 'cms_mobile_search_categories_title')}
                    </h4>
                    <p className="ed-desc is-sm">
                        {translate('portal', 'cms_mobile_search_categories_hint')}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => onChange('mobile_search_categories_enabled', isOn ? '0' : '1')}
                    className={`button small ${isOn ? 'dark' : 'is-off'}`}
                >
                    {isOn ? translate('portal', 'cms_mobile_search_categories_on') : translate('portal', 'cms_mobile_search_categories_off')}
                </button>
            </div>

            <div className="ed-grid is-md ed-mb">
                <label className="ed-field">
                    {translate('portal', 'cms_mobile_search_categories_heading')}
                    <input
                        value={heading}
                        onChange={(e) => onChange('mobile_search_categories_heading', e.target.value)}
                        placeholder={translate('portal', 'cms_mobile_search_categories_heading_ph')}
                        className="ed-input"
                    />
                </label>
                <label className="ed-field">
                    {translate('portal', 'cms_mobile_search_categories_limit')}
                    <input
                        type="number"
                        min={1}
                        max={24}
                        value={limit || '6'}
                        onChange={(e) => onChange('mobile_search_categories_limit', e.target.value)}
                        className="ed-input is-narrow"
                    />
                </label>
            </div>

            <div className="ed-row is-wrap ed-mb">
                <button type="button" className="button small" onClick={handleSyncFeatured}>
                    {translate('portal', 'cms_mobile_search_sync_featured')}
                </button>
                <button type="button" className="button small" onClick={handleSyncProducts}>
                    {translate('portal', 'cms_mobile_search_sync_products')}
                </button>
                {lines.length > 0 && (
                    <button
                        type="button"
                        className="button small danger"
                        onClick={() => {
                            if (window.confirm(translate('portal', 'cms_mobile_search_clear_confirm'))) {
                                emitLines([]);
                            }
                        }}
                    >
                        {translate('portal', 'cms_mobile_search_clear')}
                    </button>
                )}
            </div>

            <div className="ed-row ed-mb">
                <input
                    value={newName}
                    onChange={(e) => setNewName(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            handleAdd();
                        }
                    }}
                    placeholder={translate('portal', 'cms_mobile_search_add_ph')}
                    className="ed-input is-grow"
                />
                <button type="button" className="button small dark" onClick={handleAdd}>
                    <Plus size={14} /> {translate('portal', 'cms_mobile_search_add')}
                </button>
            </div>

            {lines.length === 0 ? (
                <p className="ed-desc is-sm is-flush">
                    {translate('portal', 'cms_mobile_search_empty')}
                </p>
            ) : (
                <ul className="ed-list">
                    {lines.map((line, index) => {
                        const name = line.split('|')[0].trim();
                        return (
                            <li
                                key={`${name}-${index}`}
                                className="ed-list-item"
                            >
                                <strong className="ed-strong ed-grow">{name}</strong>
                                <button type="button" className="button small" onClick={() => handleMove(index, 'up')} disabled={index === 0} aria-label={translate('portal', 'sce_move_up')}>
                                    <ArrowUp size={14} />
                                </button>
                                <button type="button" className="button small" onClick={() => handleMove(index, 'down')} disabled={index === lines.length - 1} aria-label={translate('portal', 'sce_move_down')}>
                                    <ArrowDown size={14} />
                                </button>
                                <button type="button" className="button small" onClick={() => handleRemove(index)} aria-label={translate('portal', 'sce_remove')}>
                                    <Trash2 size={14} />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
