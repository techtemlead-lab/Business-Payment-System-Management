import React, { useState } from 'react';
import { Package, Plus, Trash2 } from 'lucide-react';
import { localizeDigits } from '../../business-date';
import { toLatin } from '../../shared/countUp';
import { useT } from '../../shared/i18n/t';
import { money } from '../UI';

export type FeaturedOrderProduct = { id: number; name: string; mrp: string; image?: string | null };

/** The products on the home page, in order. The page's Save button stores the list. */
export function FeaturedOrderList({
    items,
    candidates,
    canEdit,
    changed,
    error,
    onChange,
}: {
    items: FeaturedOrderProduct[];
    candidates: (FeaturedOrderProduct & { category?: string })[];
    canEdit: boolean;
    changed: boolean;
    error?: string;
    onChange: (items: FeaturedOrderProduct[]) => void;
}) {
    const translate = useT();
    const [typedSerial, setTypedSerial] = useState<{ id: number; value: string } | null>(null);
    const [productToAdd, setProductToAdd] = useState('');
    const addableProducts = candidates.filter(product => !items.some(item => item.id === product.id));

    const moveItem = (from: number, to: number) => {
        if (to === from || to < 0 || to >= items.length) {
            return;
        }
        const reordered = [...items];
        const [moved] = reordered.splice(from, 1);
        reordered.splice(to, 0, moved);
        onChange(reordered);
    };

    const applyTypedSerial = (index: number) => {
        if (!typedSerial) {
            return;
        }
        const serial = Number(toLatin(typedSerial.value.trim()));
        setTypedSerial(null);
        if (Number.isInteger(serial) && serial >= 1) {
            moveItem(index, Math.min(serial, items.length) - 1);
        }
    };

    const addProduct = () => {
        const product = addableProducts.find(candidate => String(candidate.id) === productToAdd);
        if (!product) {
            return;
        }
        onChange([...items, { id: product.id, name: product.name, mrp: product.mrp, image: product.image }]);
        setProductToAdd('');
    };

    return (
        <div className="ed-stack ed-mt">
            <div>
                <div className="ed-strong">{translate('portal', 'sce_home_order_heading', { count: String(items.length) })}</div>
                <p className="ed-desc is-sm">{translate('portal', 'sce_home_order_hint')}</p>
            </div>

            {items.length === 0 ? (
                <div className="ed-empty is-sm">{translate('portal', 'sce_home_order_empty')}</div>
            ) : (
                <div className="ed-stack is-tight">
                    {items.map((item, index) => (
                        <div key={item.id} className="ed-card is-compact ed-order-row">
                            {canEdit ? (
                                <input
                                    type="text"
                                    inputMode="numeric"
                                    className="ed-input ed-pct"
                                    aria-label={translate('portal', 'sce_home_order_serial', { name: item.name })}
                                    value={typedSerial?.id === item.id ? typedSerial.value : localizeDigits(String(index + 1))}
                                    onFocus={event => event.target.select()}
                                    onChange={event => setTypedSerial({ id: item.id, value: event.target.value })}
                                    onBlur={() => applyTypedSerial(index)}
                                    onKeyDown={event => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            event.currentTarget.blur();
                                        }
                                    }}
                                />
                            ) : (
                                <span className="ed-strong">{index + 1}</span>
                            )}
                            <span className="ed-order-thumb">
                                {item.image ? <img src={item.image} alt="" loading="lazy" /> : <Package size={20} />}
                            </span>
                            <div>
                                <div className="ed-strong">{item.name}</div>
                                <div className="ed-desc is-sm is-flush">{translate('portal', 'mrp_label', { amount: money(item.mrp) })}</div>
                            </div>
                            {canEdit && (
                                <div className="ed-row is-tight ed-order-actions">
                                    <button
                                        type="button"
                                        disabled={index === 0}
                                        onClick={() => moveItem(index, index - 1)}
                                        className="ed-icon-btn"
                                        title={translate('portal', 'sce_move_up')}
                                        aria-label={translate('portal', 'sce_move_up')}
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={index === items.length - 1}
                                        onClick={() => moveItem(index, index + 1)}
                                        className="ed-icon-btn"
                                        title={translate('portal', 'sce_move_down')}
                                        aria-label={translate('portal', 'sce_move_down')}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => onChange(items.filter(other => other.id !== item.id))}
                                        className="button small danger"
                                    >
                                        <Trash2 size={14} /> {translate('portal', 'sce_home_order_remove')}
                                    </button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {canEdit && addableProducts.length > 0 && (
                <div className="ed-row is-wrap">
                    <select
                        className="ed-input is-sm is-grow"
                        aria-label={translate('portal', 'sce_home_order_choose')}
                        value={productToAdd}
                        onChange={event => setProductToAdd(event.target.value)}
                    >
                        <option value="">{translate('portal', 'sce_home_order_choose')}</option>
                        {addableProducts.map(product => (
                            <option key={product.id} value={product.id}>
                                {product.name}{product.category ? ` [${product.category}]` : ''} — {money(product.mrp)}
                            </option>
                        ))}
                    </select>
                    <button type="button" className="button small dark" disabled={!productToAdd} onClick={addProduct}>
                        <Plus size={14} /> {translate('portal', 'sce_home_order_add')}
                    </button>
                </div>
            )}

            {canEdit && changed && <div className="ed-strong is-sm tone-warn">{translate('portal', 'sce_home_order_unsaved')}</div>}
            {error && <div className="ed-strong is-sm tone-danger">{error}</div>}
        </div>
    );
}
