import { label, money } from '../components/UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../components/MoneyTable';
import TreasuryPage from '../components/treasury/TreasuryPage';
import { joined } from '../components/treasury/format';
import { useT } from '../shared/i18n/t';

type PartnerRow = {
    id: number;
    name: string;
    code: string | null;
    role: string;
    area: string | null;
    available: string;
    waiting: string;
    ordered_mrp: string;
    cash_given: string;
    withdrawn: string;
    spent: string;
};

/** What each dealer and sub-dealer has now, and what they have ordered, paid and taken out. */
export default function TreasuryPartners({ table }: { table: MoneyTableData<PartnerRow> }) {
    const translate = useT();
    const columns: MoneyColumn<PartnerRow>[] = [
        { key: 'name', title: translate('treasury', 'col_partner'), sortKey: 'name', phone: 'title', render: (row) => <><b>{row.name}</b><small className="cell-sub">{joined([row.code, label(row.role, translate), row.area])}</small></> },
        { key: 'available', title: translate('treasury', 'card_now'), sortKey: 'available', money: true, total: 'available', phone: 'amount', render: (row) => <b>{money(row.available)}</b> },
        { key: 'waiting', title: translate('treasury', 'col_waiting'), sortKey: 'waiting', money: true, total: 'waiting', phone: 'hide', render: (row) => money(row.waiting) },
        { key: 'ordered_mrp', title: translate('treasury', 'col_ordered'), sortKey: 'ordered_mrp', money: true, total: 'ordered_mrp', phone: 'hide', render: (row) => money(row.ordered_mrp) },
        { key: 'cash_given', title: translate('treasury', 'col_cash'), sortKey: 'cash_given', money: true, total: 'cash_given', phone: 'hide', render: (row) => money(row.cash_given) },
        { key: 'withdrawn', title: translate('treasury', 'col_out'), sortKey: 'withdrawn', money: true, total: 'withdrawn', phone: 'hide', render: (row) => <>{money(row.withdrawn)}<small className="cell-sub">{translate('treasury', 'spent_line', { amount: money(row.spent) })}</small></> },
    ];

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            <MoneyTable<PartnerRow>
                data={table}
                prop="table"
                columns={columns}
                searchPlaceholder={translate('treasury', 'search_partners')}
                rowHref={(row) => `/treasury/partners/${row.id}`}
                empty={translate('treasury', 'empty_partners')}
            />
        </TreasuryPage>
    );
}
