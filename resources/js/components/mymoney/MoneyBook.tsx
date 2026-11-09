import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import MoneyTable, { type MoneyColumn } from '../MoneyTable';
import { date, money, number } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isBelowZero, type StatementRow, type StatementTable } from './types';

function signedMoney(amount: unknown): string {
    const value = Number(amount);

    return `${value < 0 ? '-' : '+'} ${money(Math.abs(value))}`;
}

/** What happened on this line: the type and percent, then the order and who it came from. */
function WhatHappened({ row }: { row: StatementRow }) {
    const translate = useT();
    const percent = Number(row.percent) > 0 ? ` ${number(row.percent)}%` : '';
    const details = [row.order_number, row.from_name].filter(Boolean).join(' · ');

    return (
        <>
            {row.type_label}{percent}
            {details && <small className="cell-sub">{details}</small>}
            {row.waiting_until && <small className="cell-sub">{translate('portal', 'commission_waiting_until', { date: date(row.waiting_until) })}</small>}
            {row.receipt_url && <small className="cell-sub"><a className="text-link" href={row.receipt_url} target="_blank" rel="noopener noreferrer">{translate('my_money', 'receipt')}</a></small>}
        </>
    );
}

/**
 * One statement of every taka in and out, on the shared money table. With one type
 * chosen the running total means nothing, so that column goes and the sum shows instead.
 */
export default function MoneyBook({ table, actions, orderHref }: {
    table: StatementTable;
    actions?: ReactNode;
    /** Where an order row opens; the office goes to its own order report. */
    orderHref?: (orderId: string) => string;
}) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const oneType = Boolean(table.state.filters.type);

    const columns: MoneyColumn<StatementRow>[] = [
        { key: 'occurred_at', title: translate('my_money', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.occurred_at) },
        { key: 'what', title: translate('my_money', 'col_what'), phone: 'title', render: (row) => <WhatHappened row={row} /> },
        {
            key: 'amount',
            title: translate('my_money', 'col_in_out'),
            sortKey: 'amount',
            money: true,
            total: oneType ? 'amount' : undefined,
            phone: 'amount',
            render: (row) => <span className={isBelowZero(row.amount) ? 'is-negative' : undefined}>{signedMoney(row.amount)}</span>,
        },
        ...(oneType ? [] : [{
            key: 'running',
            title: translate('my_money', 'running_total'),
            money: true,
            phone: 'hide' as const,
            render: (row: StatementRow) => money(row.running),
        }]),
    ];

    const rowHref = (row: StatementRow): string | null => {
        if (row.order_id) {
            return orderHref ? orderHref(row.order_id) : `${base}/orders/${row.order_id}`;
        }

        return row.withdrawal_id ? `${base}/withdrawals/${row.withdrawal_id}` : null;
    };

    return (
        <section id="mm-book" className="mm-book">
            <MoneyTable
                data={table}
                prop="table"
                columns={columns}
                searchPlaceholder={translate('my_money', 'search')}
                rowHref={rowHref}
                actions={actions}
                empty={translate('my_money', 'empty')}
            />
        </section>
    );
}
