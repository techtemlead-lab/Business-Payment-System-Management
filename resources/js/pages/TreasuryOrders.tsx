import { date, label, money } from '../components/UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../components/MoneyTable';
import TreasuryPage from '../components/treasury/TreasuryPage';
import DownloadsMenu, { type DownloadChoices } from '../components/treasury/DownloadsMenu';
import { joined } from '../components/treasury/format';
import { useT } from '../shared/i18n/t';

type OrderRow = {
    id: string;
    number: string;
    created_at: string;
    partner_name: string;
    partner_code: string | null;
    partner_role: string;
    mrp_total: string;
    paid: string;
};

/** The accountant's report: one row per order, click a row to see where its money went. */
export default function TreasuryOrders({ table, downloads }: { table: MoneyTableData<OrderRow>; downloads: DownloadChoices }) {
    const translate = useT();
    const columns: MoneyColumn<OrderRow>[] = [
        { key: 'created_at', title: translate('treasury', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => <>{date(row.created_at)}<small className="cell-sub">{row.number}</small></> },
        { key: 'partner', title: translate('treasury', 'col_who'), sortKey: 'partner', phone: 'title', render: (row) => <><b>{row.partner_name}</b><small className="cell-sub">{joined([row.partner_code, label(row.partner_role, translate)])}</small></> },
        { key: 'mrp_total', title: translate('treasury', 'col_mrp_total'), sortKey: 'mrp_total', money: true, total: 'mrp_total', phone: 'hide', render: (row) => money(row.mrp_total) },
        { key: 'paid', title: translate('treasury', 'col_paid'), sortKey: 'paid', money: true, total: 'paid', phone: 'amount', render: (row) => <b>{money(row.paid)}</b> },
    ];

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            <MoneyTable<OrderRow>
                data={table}
                prop="table"
                columns={columns}
                searchPlaceholder={translate('treasury', 'search_orders')}
                rowHref={(row) => `/treasury/orders/${row.id}`}
                actions={<DownloadsMenu state={table.state} choices={downloads} />}
                empty={translate('treasury', 'empty_orders')}
            />
        </TreasuryPage>
    );
}
