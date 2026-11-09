import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { date, label, money } from '../components/UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../components/MoneyTable';
import TreasuryPage from '../components/treasury/TreasuryPage';
import { FundFigures, type FundCardData } from '../components/treasury/FundCard';
import FundSpendForm, { type FundSpendOptions } from '../components/treasury/FundSpendForm';
import { joined, percentText } from '../components/treasury/format';
import { useT } from '../shared/i18n/t';

type MoneyInRow = {
    id: string | number;
    created_at: string;
    order_id: string | null;
    order_number: string | null;
    partner_name: string | null;
    partner_code: string | null;
    partner_role: string | null;
    amount: string;
    type_label: string;
    percent: string | null;
    percent_of: 'paid' | 'mrp' | null;
    mrp_total: string | null;
    paid: string | null;
    reason?: string | null;
};

type MoneyOutRow = {
    date: string;
    what: string;
    to: string | null;
    amount: string;
    reference: string | null;
    receipt_url: string | null;
};

type FundView = 'in' | 'out';

/** One fund: the same three cards as the Treasury home, then the money that came in or went out. */
export default function TreasuryFund({ card, view, table, spend }: { card: FundCardData; view: FundView; table: MoneyTableData<MoneyInRow | MoneyOutRow>; spend: FundSpendOptions | null }) {
    const translate = useT();
    const path = usePage().url.split('?')[0];
    const [spending, setSpending] = useState(false);

    const columnsIn: MoneyColumn<MoneyInRow>[] = [
        { key: 'created_at', title: translate('treasury', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.created_at) },
        {
            key: 'order',
            title: translate('treasury', 'col_order_who'),
            sortKey: 'order',
            phone: 'title',
            render: (row) => (
                <>
                    <b>{row.order_number ?? row.partner_name ?? '—'}</b>
                    {row.order_number && row.partner_name && <small className="cell-sub">{joined([row.partner_name, row.partner_code, row.partner_role ? label(row.partner_role, translate) : null])}</small>}
                </>
            ),
        },
        { key: 'amount', title: translate('treasury', 'came_in'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (row) => <b>{money(row.amount)}</b> },
        {
            key: 'type',
            title: translate('treasury', 'col_type'),
            phone: 'sub',
            render: (row) => (
                <>
                    {joined([row.type_label, percentText(translate, row.percent, row.percent_of)])}
                    {row.reason && <small className="cell-sub">{translate('treasury', 'reason_line', { reason: row.reason })}</small>}
                </>
            ),
        },
        { key: 'mrp_total', title: translate('treasury', 'col_mrp_total'), sortKey: 'mrp_total', money: true, phone: 'hide', render: (row) => (row.mrp_total === null ? '—' : money(row.mrp_total)) },
        { key: 'paid', title: translate('treasury', 'col_paid'), sortKey: 'paid', money: true, phone: 'hide', render: (row) => (row.paid === null ? '—' : money(row.paid)) },
    ];

    const columnsOut: MoneyColumn<MoneyOutRow>[] = [
        { key: 'date', title: translate('treasury', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.date) },
        { key: 'what', title: translate('treasury', 'col_for'), phone: 'title', render: (row) => <><b>{row.what}</b>{row.reference && <small className="cell-sub">{row.reference}</small>}</> },
        { key: 'to', title: translate('treasury', 'col_to'), phone: 'sub', render: (row) => row.to ?? '—' },
        { key: 'amount', title: translate('treasury', 'went_out'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (row) => <b>{money(row.amount)}</b> },
        { key: 'receipt', title: translate('treasury', 'col_receipt'), phone: 'hide', render: (row) => (row.receipt_url ? <a className="text-link" href={row.receipt_url}>{translate('treasury', 'view')}</a> : '—') },
    ];

    return (
        <TreasuryPage
            title={card.name}
            back={{ href: '/treasury/funds', label: translate('treasury', 'title') }}
            action={spend && card.spendable ? <button type="button" className="button dark" onClick={() => setSpending(true)}>{translate('treasury', 'spend')}</button> : undefined}
        >
            <FundFigures card={card} />
            <div className="office-switch" role="group" aria-label={card.name}>
                {(['in', 'out'] as FundView[]).map((option) => (
                    <Link key={option} href={option === 'in' ? path : `${path}?view=out`} preserveScroll className={option === view ? 'is-active' : undefined} aria-current={option === view ? 'true' : undefined}>
                        {translate('treasury', option === 'in' ? 'came_in' : 'went_out')}
                    </Link>
                ))}
            </div>
            {view === 'in' ? (
                <MoneyTable<MoneyInRow>
                    key="in"
                    data={table as MoneyTableData<MoneyInRow>}
                    prop="table"
                    columns={columnsIn}
                    searchPlaceholder={translate('treasury', 'search')}
                    rowHref={(row) => (row.order_id ? `/treasury/orders/${row.order_id}` : null)}
                    empty={translate('treasury', 'empty_in')}
                />
            ) : (
                <MoneyTable<MoneyOutRow>
                    key="out"
                    data={table as MoneyTableData<MoneyOutRow>}
                    prop="table"
                    columns={columnsOut}
                    searchPlaceholder={translate('treasury', 'search')}
                    empty={translate('treasury', 'empty_out')}
                />
            )}
            {spending && spend && <FundSpendForm card={card} spend={spend} onClose={() => setSpending(false)} />}
        </TreasuryPage>
    );
}
