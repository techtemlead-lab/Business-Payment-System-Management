import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Badge, channelLabel, dateTime, label, money, type Row } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { joined } from './treasury/format';
import { orderDue } from './office/portalShared';
import { useT } from '../shared/i18n/t';

/** Payments waiting to be checked, on the shared table. Reviewing opens the same box as before. */
export default function PaymentsQueue({ data, onReview, actions }: { data: MoneyTableData<Row>; onReview: (row: Row) => void; actions?: React.ReactNode }) {
    const translate = useT();
    const columns: MoneyColumn<Row>[] = [
        { key: 'created_at', title: translate('portal', 'col_date'), sortKey: 'created_at', phone: 'sub', render: (row) => dateTime(row.created_at) },
        {
            key: 'order',
            title: translate('portal', 'col_order'),
            sortKey: 'number',
            phone: 'title',
            render: (row) => (
                <>
                    <Link className="record-link" href={`/orders/${row.order_id}`}>{row.number}</Link>
                    {row.order_status && <small className="cell-sub"><Badge status={row.order_status} /></small>}
                </>
            ),
        },
        { key: 'partner', title: translate('portal', 'col_partner'), sortKey: 'partner', phone: 'sub', render: (row) => <>{row.name ?? '—'}<small className="cell-sub">{joined([row.dealer_code, row.role ? label(row.role, translate) : null, row.phone])}</small></> },
        { key: 'area', title: translate('portal', 'col_area'), sortKey: 'area', phone: 'hide', render: (row) => <>{joined([row.district, row.division]) || '—'}{row.branch_name && <small className="cell-sub">{row.branch_name}</small>}</> },
        {
            key: 'channel',
            title: translate('portal', 'col_channel'),
            sortKey: 'channel',
            phone: 'sub',
            render: (row) => (
                <>
                    {channelLabel(row.channel, translate) || '—'}
                    {row.reference && <small className="cell-sub">{row.reference}</small>}
                    {row.has_proof && <small className="cell-sub"><a className="text-link" href={`/commands/operations/receipts/payment/${row.id}`} target="_blank" rel="noopener">{translate('portal', 'payment_proof_view')}</a></small>}
                </>
            ),
        },
        { key: 'amount', title: translate('portal', 'col_amount'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (row) => <b>{money(row.amount)}</b> },
        { key: 'due', title: translate('portal', 'col_due'), sortKey: 'due', money: true, phone: 'hide', render: (row) => <>{money(orderDue({ ...row, status: row.order_status }))}<small className="cell-sub">{translate('portal', 'pay_order_value_paid', { value: money(row.total), paid: money(row.paid) })}</small></> },
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'sub', render: (row) => <><Badge status={row.status} />{row.status === 'rejected' && row.reason && <small className="cell-sub">{row.reason}</small>}</> },
        { key: 'checked_by', title: translate('portal', 'col_checked_by'), sortKey: 'checked_by', phone: 'hide', render: (row) => (row.status !== 'pending' && row.checked_by ? <>{row.checked_by}<small className="cell-sub">{dateTime(row.checked_at)}</small></> : '—') },
        {
            key: 'action',
            title: translate('portal', 'col_review'),
            phone: 'hide',
            render: (row) => (row.status === 'pending' ? <button type="button" className="button small" onClick={(event) => { event.stopPropagation(); onReview(row); }}>{translate('portal', 'review')} <ArrowUpRight size={14} aria-hidden="true" /></button> : '—'),
        },
    ];

    return <MoneyTable<Row> data={data} prop="data" columns={columns} searchPlaceholder={translate('portal', 'payment_verification_queue')} actions={actions} empty={translate('portal', 'empty_default_title')} />;
}
