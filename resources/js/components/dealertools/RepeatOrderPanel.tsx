import React from 'react';
import { RotateCcw } from 'lucide-react';
import { Badge, date, money, type Row } from '../UI';
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
    status?: string;
    total?: string;
    names?: string[];
    items: RepeatItem[];
    skipped: { name: string; reason: string }[];
}

/** Past orders the partner can put back in the cart with one tap, at today's prices. One compact line each. */
export default function RepeatOrderPanel({ orders, onFill }: { orders: RepeatOption[]; onFill: (option: RepeatOption) => void }) {
    const translate = useT();
    if (orders.length === 0) {
        return null;
    }
    return (
        <section className="panel ppm-recent" data-testid="repeat-orders">
            <h2>{translate('purchase_page', 'reorder_recent_title')}</h2>
            <ul className="ppm-recent-list">
                {orders.map((option) => (
                    <li key={option.order_id} className="ppm-recent-row">
                        <strong className="ppm-recent-number">{option.number}</strong>
                        <span className="ppm-recent-date">{date(option.ordered_on)}</span>
                        {option.total !== undefined && <span className="ppm-recent-total" data-testid="repeat-total">{money(option.total)}</span>}
                        {option.status ? <Badge status={option.status} /> : null}
                        {option.items.some(item => item.quantity > 0)
                            ? <button type="button" className="button small dark ppm-recent-action" onClick={() => onFill(option)}><RotateCcw size={15} /> {translate('purchase_page', 'reorder_button')}</button>
                            : <small>{translate('dealer_tools', 'repeat_nothing')}</small>}
                    </li>
                ))}
            </ul>
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
