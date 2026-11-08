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
    quantity_reduced: boolean;
}

export interface RepeatOption {
    order_id: string;
    number: string;
    ordered_on: string;
    items: RepeatItem[];
    skipped: { name: string; reason: string }[];
}

/** Past orders the partner can put back in the cart with one tap, at today's prices and stock. */
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
                        <strong>{option.number}</strong>
                        <small>{translate('dealer_tools', 'repeat_items', { count: number(option.items.length) })}</small>
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
        if (item.quantity_reduced) {
            notes.push(translate('dealer_tools', 'repeat_reduced', { name, count: number(item.quantity) }));
        }
    });
    option.skipped.forEach((skip) => notes.push(translate('dealer_tools', `repeat_skip_${skip.reason}`, { name: skip.name })));
    return notes;
}
