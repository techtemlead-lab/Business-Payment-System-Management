import { Row, money, number, ActionForm } from './UI';
import { useT } from '../shared/i18n/t';

export type OwedItem = { id: number; partner_id: number; partner: string; product: string; quantity: number; value: string; source_order_id: string; source_number: string; note: string | null; created_at: string };

/** Statuses of a next order that can still take the owed goods before they leave. */
const receivingStatuses = ['submitted', 'held', 'approved'];

/**
 * Goods the company owes the partner (paid in full, short at dispatch). The partner sees what is
 * owed; staff record a short line as owed, add the owed goods to this order, or refund them.
 */
export default function OwedItemsPanel({ order, owedItems, owner, canSendGoods, canRefund, unsentOf }: { order: Row; owedItems: OwedItem[]; owner: boolean; canSendGoods: boolean; canRefund: boolean; unsentOf: (line: Row) => number }) {
    const translate = useT();
    const paidInFull = Number(order.total) > 0 && Number(order.paid) >= Number(order.total);
    const shortLines = canSendGoods && order.status === 'partially_dispatched' && paidInFull
        ? (order.lines ?? []).filter((line: Row) => !line.is_free && unsentOf(line) > 0)
        : [];
    const fromOtherOrders = owedItems.filter(item => item.source_order_id !== order.id);
    if (owedItems.length === 0 && shortLines.length === 0) {
        return null;
    }

    return <div className="notice" role="status" data-testid="owed-items">
        {owedItems.length > 0 && <>
            <b>{translate('owed_items', owner ? 'company_owes_heading' : 'staff_heading')}</b>
            <ul className="plain-list">{owedItems.map(item => <li key={item.id}>
                {translate('owed_items', 'company_owes', { product: item.product, quantity: number(item.quantity), number: item.source_number })}
                {!owner && <> · {money(item.value)}</>}
                {!owner && canRefund && <ActionForm action={`/commands/owed-items/${item.id}/refund`} fields={[{ name: 'reason', label: translate('owed_items', 'refund_reason'), required: true }]} submit={translate('owed_items', 'refund_button')} />}
            </li>)}</ul>
            {!owner && canSendGoods && fromOtherOrders.length > 0 && receivingStatuses.includes(order.status) && <ActionForm action={`/commands/orders/${order.id}/owed/ship`} fields={[]} submit={translate('owed_items', 'ship_button')} />}
        </>}
        {shortLines.map((line: Row) => <div key={line.id}>
            <p>{line.name}: {number(unsentOf(line))}</p>
            <ActionForm action={`/commands/orders/${order.id}/owed`} defaults={{ line_id: line.id }} fields={[{ name: 'note', label: translate('owed_items', 'record_note'), required: true }]} submit={translate('owed_items', 'record_button')} />
        </div>)}
    </div>;
}
