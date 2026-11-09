import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { date, label, money, number } from '../components/UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../components/MoneyTable';
import TreasuryPage from '../components/treasury/TreasuryPage';
import { FundFigures, type FundCardData } from '../components/treasury/FundCard';
import FundSpendForm, { type FundSpendOptions } from '../components/treasury/FundSpendForm';
import RewardLeaders, { type RewardAward, type RewardLeaderFigures, type RewardLeaderRow } from '../components/treasury/RewardLeaders';
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

type FundView = 'in' | 'out' | RewardAward;

/** One fund: the same three cards as the Treasury home, then the money that came in or went out. The car and bike fund can also list leaders' progress. */
export default function TreasuryFund({ card, view, table, spend, leaders }: { card: FundCardData; view: FundView; table: MoneyTableData<MoneyInRow | MoneyOutRow | RewardLeaderRow>; spend: FundSpendOptions | null; leaders: RewardLeaderFigures | null }) {
    const translate = useT();
    const path = usePage().url.split('?')[0];
    const [spending, setSpending] = useState(false);
    const viewSwitch = useRef<HTMLDivElement>(null);

    useEffect(() => {
        // On a phone the choices scroll sideways; keep the chosen one in sight.
        const strip = viewSwitch.current;
        const chosen = strip?.querySelector<HTMLElement>('[aria-current="true"]');
        if (strip && chosen) {
            strip.scrollLeft = chosen.offsetLeft - (strip.clientWidth - chosen.offsetWidth) / 2;
        }
    }, [view]);

    const views: { key: FundView; word: string }[] = [
        { key: 'in', word: translate('treasury', 'came_in') },
        { key: 'out', word: translate('treasury', 'went_out') },
        ...(leaders ? (['bike', 'car'] as RewardAward[]).map((award) => ({ key: award, word: `${translate('treasury', `leaders_${award}`)} (${number(leaders.counts[award])})` })) : []),
    ];

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
            <div className="office-switch" role="group" aria-label={card.name} ref={viewSwitch}>
                {views.map((option) => (
                    <Link key={option.key} href={option.key === 'in' ? path : `${path}?view=${option.key}`} preserveScroll className={option.key === view ? 'is-active' : undefined} aria-current={option.key === view ? 'true' : undefined}>
                        {option.word}
                    </Link>
                ))}
            </div>
            {(view === 'bike' || view === 'car') && leaders ? (
                <RewardLeaders key={view} award={view} table={table as MoneyTableData<RewardLeaderRow>} figures={leaders} />
            ) : view === 'in' ? (
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
