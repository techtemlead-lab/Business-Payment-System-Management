import { money } from './UI';
import FoldCard from './FoldCard';
import { joined, percentText } from './treasury/format';
import { useT } from '../shared/i18n/t';

type LineTarget =
    | { kind: 'partner'; id: number; name: string; code: string | null; area: string | null }
    | { kind: 'fund'; key: string | null; name: string };

type MoneyLine = {
    key: string;
    type_label: string;
    percent: string | null;
    percent_of: 'paid' | 'mrp' | null;
    amount: string;
    returned: string | null;
    to: LineTarget;
    status: 'done' | 'waiting' | 'held' | 'moved' | 'returned' | 'preview';
    status_label: string;
    reason?: string | null;
};

export type OrderMoneyBreakdownData = {
    mode: 'posted' | 'preview' | 'none';
    lines: MoneyLine[];
    cash: string | null;
    lines_total: string | null;
    adds_up: boolean;
    own_only: boolean;
    reason: string | null;
};

const STATUS_BADGE: Record<MoneyLine['status'], string> = { done: 'active', waiting: 'pending', held: 'held', moved: 'closed', returned: 'reversed', preview: 'draft' };

/** Who or which fund received a line. A share with nobody to receive it shows the company fund it went to. */
function GoesTo({ to, reason }: { to: LineTarget; reason?: string | null }) {
    const translate = useT();

    return (
        <>
            <b>{to.name}</b>
            {to.kind === 'partner' && joined([to.code, to.area]) !== '' && <small className="cell-sub">{joined([to.code, to.area])}</small>}
            {to.kind === 'fund' && <small className="cell-sub">{translate('order_money', 'company_fund')}</small>}
            {reason && <small className="cell-sub">{translate('treasury', 'reason_line', { reason })}</small>}
        </>
    );
}

/**
 * "Where this order's money went". With postings it shows the posted lines; without them it shows the
 * same split as a clearly labelled preview that never reads as real money. A partner sees their own lines only.
 */
export default function OrderMoneyBreakdown({ data }: { data: OrderMoneyBreakdownData | null | undefined }) {
    const translate = useT();
    if (!data) {
        return null;
    }
    const preview = data.mode === 'preview';
    const title = translate('order_money', preview ? 'title_preview' : 'title');

    if (data.mode === 'none') {
        return (
            <FoldCard testId="order-money" title={title}>
                <p className="muted">{translate('order_money', `none_${data.reason ?? 'not_available'}`)}</p>
            </FoldCard>
        );
    }

    return (
        <FoldCard testId="order-money" className="table-panel" title={title}>
            {preview
                ? <div className="notice" role="status" data-testid="order-money-preview"><b>{translate('order_money', 'preview_notice')}</b><p>{translate('order_money', 'preview_hint')}{data.reason ? ` ${translate('order_money', `reason_${data.reason}`)}` : ''}</p></div>
                : <p className="muted" style={{ padding: '0 24px' }}>{translate('order_money', 'posted_note')}</p>}
            {data.own_only && <p className="muted" style={{ padding: '0 24px' }}>{translate('order_money', 'own_only')}</p>}
            <div className="table-wrap">
                <table className="order-money-table">
                    <caption className="sr-only">{title}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{translate('order_money', 'col_head')}</th>
                            <th scope="col">{translate('order_money', 'col_percent')}</th>
                            <th scope="col" className="is-num">{translate('order_money', 'col_amount')}</th>
                            <th scope="col">{translate('order_money', 'col_goes_to')}</th>
                            <th scope="col">{translate('order_money', 'col_status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.lines.length === 0 && <tr className="table-empty-row"><td colSpan={5} className="muted">{translate('order_money', 'none_not_available')}</td></tr>}
                        {data.lines.map((line) => (
                            <tr key={line.key}>
                                <td><b>{line.type_label}</b></td>
                                <td>{percentText(translate, line.percent, line.percent_of) || '—'}</td>
                                <td className="is-num">{money(line.amount)}{Number(line.returned ?? 0) > 0 && <small className="cell-sub">{translate('order_money', 'returned', { amount: money(line.returned) })}</small>}</td>
                                <td><GoesTo to={line.to} reason={line.reason} /></td>
                                <td><span className={`badge ${STATUS_BADGE[line.status] ?? ''}`}>{line.status_label}</span></td>
                            </tr>
                        ))}
                    </tbody>
                    {data.lines_total !== null && data.lines.length > 0 && (
                        <tfoot>
                            <tr>
                                <td colSpan={2}>{translate('order_money', 'total')}</td>
                                <td className={`is-num${data.adds_up ? '' : ' is-negative'}`}><b>{money(data.lines_total)}</b></td>
                                <td colSpan={2}>{data.cash !== null && translate('order_money', 'accounted', { amount: money(data.lines_total), cash: money(data.cash) })}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
            {!data.adds_up && <p className="error" role="alert" style={{ margin: 16 }}>{translate('order_money', 'not_adding_up')}</p>}
        </FoldCard>
    );
}
