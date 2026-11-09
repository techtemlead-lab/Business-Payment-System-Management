import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Badge, channelLabel, dateTime, money, type Row } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { joined } from './treasury/format';
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
                    <small className="cell-sub">{joined([row.name, row.dealer_code])}</small>
                </>
            ),
        },
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
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'sub', render: (row) => <><Badge status={row.status} />{row.status === 'rejected' && row.reason && <small className="cell-sub">{row.reason}</small>}</> },
        {
            key: 'action',
            title: translate('portal', 'col_review'),
            phone: 'hide',
            render: (row) => (row.status === 'pending' ? <button type="button" className="button small" onClick={(event) => { event.stopPropagation(); onReview(row); }}>{translate('portal', 'review')} <ArrowUpRight size={14} aria-hidden="true" /></button> : (row.checked_by ?? '—')),
        },
    ];

    return <MoneyTable<Row> data={data} prop="data" columns={columns} searchPlaceholder={translate('portal', 'payment_verification_queue')} actions={actions} empty={translate('portal', 'empty_default_title')} />;
}
