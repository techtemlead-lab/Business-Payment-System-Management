import { dateTime, money, number } from '../components/UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../components/MoneyTable';
import TreasuryPage from '../components/treasury/TreasuryPage';
import { joined } from '../components/treasury/format';
import { useT } from '../shared/i18n/t';

type EventRow = {
    id: number;
    occurred_at: string;
    kind: string;
    action: string;
    action_label: string;
    label: string | null;
    order_id: string | null;
    order_number: string | null;
    partner_name: string | null;
    partner_code: string | null;
    recipient_name: string | null;
    fund_key: string | null;
    fund_name: string | null;
    product_name: string | null;
    quantity: number | null;
    amount: string | null;
    percent: string | null;
};

/** Every event in time order: orders, money, commission, funds, withdrawals and stock. */
export default function TreasuryEvents({ table }: { table: MoneyTableData<EventRow> }) {
    const translate = useT();
    const detail = (row: EventRow): string => joined([
        row.fund_name,
        row.recipient_name ?? row.partner_name,
        row.percent !== null && row.percent !== undefined ? `${number(row.percent)}%` : null,
        row.order_number,
        row.product_name,
    ]);
    const figure = (row: EventRow): string => {
        if (row.amount !== null && row.amount !== undefined) {
            return money(row.amount);
        }

        return row.quantity !== null && row.quantity !== undefined ? translate('treasury', 'pieces', { count: number(Math.abs(row.quantity)) }) : '—';
    };
    const columns: MoneyColumn<EventRow>[] = [
        { key: 'occurred_at', title: translate('treasury', 'col_time'), sortKey: 'occurred_at', phone: 'sub', render: (row) => dateTime(row.occurred_at) },
        { key: 'action', title: translate('treasury', 'col_event'), sortKey: 'action', phone: 'title', render: (row) => <b>{joined([row.action_label, row.label])}</b> },
        { key: 'detail', title: translate('treasury', 'col_detail'), phone: 'sub', render: (row) => detail(row) || '—' },
        { key: 'amount', title: translate('treasury', 'col_amount_or_quantity'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (row) => <b>{figure(row)}</b> },
    ];

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            <MoneyTable<EventRow>
                data={table}
                prop="table"
                columns={columns}
                searchPlaceholder={translate('treasury', 'search_events')}
                rowHref={(row) => (row.order_id ? `/treasury/orders/${row.order_id}` : null)}
                empty={translate('treasury', 'empty_events')}
            />
        </TreasuryPage>
    );
}
