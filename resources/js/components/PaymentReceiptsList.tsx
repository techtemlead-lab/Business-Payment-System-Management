import { usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { money, dateTime, Table, Pagination, type Row } from './UI';
import JargonHint from './JargonHint';
import { useT } from '../shared/i18n/t';

/**
 * A dealer's or sub-dealer's own commission payout receipts. Each one opens as a
 * printable page (A4 or an 80 mm receipt printer).
 */
export default function PaymentReceiptsList({ receipts }: { receipts?: Row }) {
    const translate = useT();
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const rows: Row[] = receipts?.data ?? [];

    return (
        <section className="panel table-panel" data-tour="payment-receipts">
            <div className="panel-heading">
                <div>
                    <h2>{translate('portal', 'payment_receipts')}</h2>
                    <p className="muted">{translate('portal', 'prc_list_hint')}</p>
                    <JargonHint terms={['tds']} />
                </div>
            </div>
            <Table
                filename="payment-receipts"
                searchable={false}
                totalCount={receipts?.total}
                rows={rows}
                columns={[
                    { key: 'number', title: translate('portal', 'prc_number'), render: (r: Row) => <b>{r.number}</b> },
                    { key: 'paid_at', title: translate('portal', 'prc_date'), render: (r: Row) => dateTime(r.paid_at) },
                    { key: 'kind', title: translate('portal', 'prc_kind'), render: (r: Row) => translate('portal', r.reserve_disbursement_id ? 'prc_kind_reserve' : 'prc_kind_withdrawal') },
                    { key: 'method', title: translate('portal', 'prc_method'), render: (r: Row) => translate('portal', `payout_method_${r.method}`) },
                    { key: 'reference', title: translate('portal', 'prc_reference'), render: (r: Row) => r.reference || '—' },
                    { key: 'amount', title: translate('portal', 'prc_commission_amount'), money: true, render: (r: Row) => money(r.amount) },
                    { key: 'withheld_amount', title: translate('portal', 'prc_tax_withheld'), money: true, render: (r: Row) => money(r.withheld_amount) },
                    { key: 'net_amount', title: translate('portal', 'prc_net_paid'), money: true, total: true, render: (r: Row) => <b>{money(r.net_amount)}</b> },
                    {
                        key: 'print',
                        title: translate('portal', 'prc_open'),
                        render: (r: Row) => (
                            <a className="button small" href={`${base}/payment-receipts/${r.id}/print`} target="_blank" rel="noopener">
                                <Printer size={14} /> {translate('portal', 'prc_view_print')}
                            </a>
                        ),
                    },
                ]}
                empty={translate('portal', 'prc_list_empty')}
                emptyBody={translate('portal', 'prc_list_empty_body')}
            />
            <Pagination page={receipts} />
        </section>
    );
}
