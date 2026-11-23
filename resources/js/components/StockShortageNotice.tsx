import { Row, number } from './UI';
import { useT } from '../shared/i18n/t';

type BranchStock = { branch_id: number; name: string; code: string; products: Record<string, number>; can_send_all: boolean };

/**
 * Warns the office when the stock cannot cover what is still to send on an order, and says in
 * a few lines what can be done with the missing pieces: send them later, keep the money as due
 * and send them with the partner's next order, or refund them. Nothing is decided here.
 */
export default function StockShortageNotice({ order, stockByBranch, unsentOf }: { order: Row; stockByBranch: BranchStock[]; unsentOf: (line: Row) => number }) {
    const translate = useT();
    const needed = new Map<number, { name: string; need: number }>();
    for (const line of (order.lines ?? []) as Row[]) {
        const left = unsentOf(line);
        if (left > 0) {
            const productId = Number(line.product_id);
            needed.set(productId, { name: String(line.name), need: (needed.get(productId)?.need ?? 0) + left });
        }
    }
    const short = [...needed.entries()]
        .map(([productId, item]) => ({ ...item, have: stockByBranch.reduce((sum, branch) => sum + Number(branch.products[String(productId)] ?? 0), 0) }))
        .filter(item => item.have < item.need);
    if (short.length === 0) {
        return null;
    }

    return <div className="notice stock-short" role="alert" data-testid="stock-shortage">
        <b>{translate('orders_stock', 'short_title')}</b>
        <ul className="plain-list">{short.map(item => <li key={item.name}>
            {translate('orders_stock', 'short_line', { product: item.name, need: number(item.need), have: number(item.have), short: number(item.need - item.have) })}
        </li>)}</ul>
        <p><b>{translate('orders_stock', 'short_options_title')}</b></p>
        <ul>
            <li>{translate('orders_stock', 'short_opt_due')}</li>
            <li>{translate('orders_stock', 'short_opt_refund')}</li>
            <li>{translate('orders_stock', 'short_opt_later')}</li>
        </ul>
    </div>;
}
