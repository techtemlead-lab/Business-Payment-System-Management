import { Link } from '@inertiajs/react';
import { Badge, channelLabel, date, money, number } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import { Figures } from '../components/treasury/FundCard';
import { useTreasuryViewer } from '../components/treasury/TreasuryTabs';
import { joined, percentText } from '../components/treasury/format';
import { useT } from '../shared/i18n/t';

type LineTarget =
    | { kind: 'partner'; id: number; name: string; code: string | null; area: string | null }
    | { kind: 'fund'; key: string; name: string };

type LineStatus = 'done' | 'waiting' | 'held' | 'moved' | 'returned';

type MoneyLine = {
    key: string;
    type_label: string;
    percent: string | null;
    percent_of: 'paid' | 'mrp' | null;
    amount: string;
    returned: string | null;
    to: LineTarget;
    status: LineStatus;
    status_label: string;
    reason?: string | null;
};

type OrderMoney = {
    order: {
        id: string;
        number: string;
        created_at: string;
        status: string;
        partner: { id: number; name: string; code: string | null; role: string; area: string | null };
        mrp_total: string;
        paid: string;
        percent_off: string | null;
        payments: { method: string; method_label: string; amount: string; reference: string | null; confirmed_by: string | null }[];
    };
    posting: 'posted' | 'part_delivered' | 'waiting_delivery' | 'no_commission';
    basis: { cash: string; mrp: string; refunded: string } | null;
    lines: MoneyLine[];
    lines_total: string;
    adds_up: boolean;
    no_commission: { reason: string | null; amount: string; taken_back: string | null; still_owed: string | null } | null;
};

const STATUS_BADGE: Record<LineStatus, string> = { done: 'active', waiting: 'pending', held: 'held', moved: 'closed', returned: 'reversed' };

function isAboveZero(value: unknown): boolean {
    return Number(String(value ?? '0').replace(/[^0-9.-]/g, '')) > 0;
}

function WentTo({ to, reason }: { to: LineTarget; reason?: string | null }) {
    const translate = useT();
    const viewer = useTreasuryViewer();
    if (to.kind === 'fund') {
        return <><b>{to.name}</b>{reason && <small className="cell-sub">{translate('treasury', 'reason_line', { reason })}</small>}</>;
    }

    return (
        <>
            {viewer.finance ? <Link className="record-link" href={`/treasury/partners/${to.id}`}>{to.name}</Link> : <b>{to.name}</b>}
            <small className="cell-sub">{joined([to.code, to.area])}</small>
        </>
    );
}

/** The one plain line that says why the split below is what it is. */
function usePostingLine({ posting, basis, lines, no_commission: noCommission }: OrderMoney): string | null {
    const translate = useT();
    if (posting === 'waiting_delivery') {
        return translate('treasury', 'posting_waiting');
    }
    if (posting === 'part_delivered') {
        return joined([translate('treasury', 'posting_part_delivered'), basis ? money(basis.cash) : null]);
    }
    if (posting === 'no_commission' && noCommission && (noCommission.taken_back || noCommission.still_owed)) {
        return joined([
            translate('treasury', 'posting_no_commission'),
            noCommission.taken_back ? translate('treasury', 'posting_taken_back', { amount: money(noCommission.taken_back) }) : null,
            noCommission.still_owed ? translate('treasury', 'posting_still_owed', { amount: money(noCommission.still_owed) }) : null,
        ]);
    }
    if (posting === 'no_commission' && lines.length === 0) {
        return joined([translate('treasury', 'posting_no_commission'), noCommission ? money(noCommission.amount) : null, noCommission?.reason ? translate('treasury', 'reason_line', { reason: noCommission.reason }) : null]);
    }

    return null;
}

/** Where every taka of one order went: the percent, the type and who or which fund received it. */
export default function TreasuryOrder({ detail }: { detail: OrderMoney }) {
    const translate = useT();
    const { order, posting, basis, lines, lines_total: linesTotal, adds_up: addsUp, no_commission: noCommission } = detail;
    const showsReturned = lines.some((line) => isAboveZero(line.returned));
    const postingLine = usePostingLine(detail);

    return (
        <TreasuryPage
            title={order.number}
            subtitle={joined([date(order.created_at), order.partner.name, order.partner.code, order.partner.area])}
            back={{ href: '/treasury/orders', label: translate('treasury', 'tab_orders') }}
            action={<Badge status={order.status} />}
        >
            <Figures
                items={[
                    { key: 'mrp', label: translate('treasury', 'col_mrp_total'), value: order.mrp_total },
                    { key: 'paid', label: translate('treasury', 'col_paid'), value: order.paid },
                    { key: 'off', label: translate('treasury', 'card_off'), value: order.percent_off === null ? '—' : `${number(order.percent_off)}%`, text: true },
                ]}
            />
            {order.payments.length > 0 && (
                <p className="tsy-line">
                    {translate('treasury', 'payment')}: {order.payments.map((payment) => joined([payment.method_label || channelLabel(payment.method, translate), payment.reference])).join('; ')}
                </p>
            )}
            {postingLine && <p className="tsy-line">{postingLine}</p>}
            {lines.length > 0 && (
                <div className="panel table-panel">
                    <table className="tsy-lines">
                        <thead>
                            <tr>
                                <th>{translate('treasury', 'col_type')}</th>
                                <th>{translate('treasury', 'col_rate')}</th>
                                <th>{translate('treasury', 'col_went_to')}</th>
                                {showsReturned && <th className="is-money">{translate('treasury', 'col_returned')}</th>}
                                <th className="is-money">{translate('treasury', 'col_amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => (
                                <tr key={line.key}>
                                    <td data-part="type">
                                        <b>{line.type_label}</b>
                                        {line.status !== 'done' && <span className={`badge ${STATUS_BADGE[line.status]}`}>{line.status_label}</span>}
                                    </td>
                                    <td data-part="rate" className={line.percent === null ? 'is-blank' : undefined}>{percentText(translate, line.percent, line.percent_of) || '—'}</td>
                                    <td data-part="to"><WentTo to={line.to} reason={line.reason ?? (posting === 'no_commission' ? noCommission?.reason : null)} /></td>
                                    {showsReturned && <td data-part="returned" className="is-money">{isAboveZero(line.returned) ? money(line.returned) : '—'}</td>}
                                    <td data-part="amount" className="is-money">{money(line.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={showsReturned ? 4 : 3}>{translate('treasury', 'total')}</td>
                                <td className={`is-money${addsUp ? '' : ' is-negative'}`}>{money(linesTotal)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </TreasuryPage>
    );
}
