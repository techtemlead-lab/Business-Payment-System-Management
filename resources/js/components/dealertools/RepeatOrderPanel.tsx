import React from 'react';
import { RotateCcw } from 'lucide-react';
import { date, money, number, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

export interface RepeatItem {
    product: Row;
    quantity: number;
    last_quantity: number;
    last_unit_price: string;
    price_changed: boolean;
}

export interface RepeatOption {
    order_id: string;
    number: string;
    ordered_on: string;
    /** What the order cost then, and the names of the products on it. */
    total?: string;
    names?: string[];
    items: RepeatItem[];
    skipped: { name: string; reason: string }[];
}

/** Past orders the partner can put back in the cart with one tap, at today's prices. */
/** Most product names written on a card; the rest become "n more". */

export default function RepeatOrderPanel({ orders, onFill }: { orders: RepeatOption[]; onFill: (option: RepeatOption) => void }) {
    const translate = useT();
    if (orders.length === 0) {
        return null;
    }
    return (
        <section className="panel" data-testid="repeat-orders">
            <h2>{translate('dealer_tools', 'repeat_title')}</h2>
            <p className="muted">{translate('dealer_tools', 'repeat_hint')}</p>
            <div className="metric-grid three">
                {orders.map((option, index) => (
                    <div key={option.order_id} className="metric">
                        <span>{translate('dealer_tools', index === 0 ? 'repeat_last' : 'repeat_past')} · {date(option.ordered_on)}</span>
                        <strong>{option.items.slice(0, 2).map(item => String(item.product.name ?? '')).join(', ')}{option.items.length > 2 ? ` ${translate('partner_ux', 'repeat_more', { count: number(option.items.length - 2) })}` : ''}</strong>
                        <small>{translate('partner_ux', 'repeat_total')}: {money(option.total ?? option.items.reduce((sum, item) => sum + Number(item.product.price ?? 0) * item.quantity, 0))}</small>
                        <small>{translate('dealer_tools', 'repeat_items', { count: number(option.items.length) })} · {option.number}</small>
                        {option.items.some(item => item.quantity > 0)
                            ? <button type="button" className="button small dark" onClick={() => onFill(option)}><RotateCcw size={15} /> {translate('dealer_tools', 'repeat_fill')}</button>
                            : <small>{translate('dealer_tools', 'repeat_nothing')}</small>}
                    </div>
                ))}
            </div>
        </section>
    );
}

/** The changes a partner should know about after a past order is put in the cart. */
export function repeatChangeNotes(option: RepeatOption, translate: (group: string, key: string, replace?: Record<string, string>) => string): string[] {
    const notes: string[] = [];
    option.items.forEach((item) => {
        const name = String(item.product.name ?? '');
        if (item.price_changed) {
            notes.push(translate('dealer_tools', 'repeat_price_changed', { name, price: money(item.product.price) }));
        }
    });
    option.skipped.forEach((skip) => notes.push(translate('dealer_tools', `repeat_skip_${skip.reason}`, { name: skip.name })));
    return notes;
}
