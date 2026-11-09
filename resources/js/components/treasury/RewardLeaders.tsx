import { Badge, money, number } from '../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import { joined } from './format';
import { useT } from '../../shared/i18n/t';

export type RewardAward = 'bike' | 'car';

export type RewardLeaderFigures = {
    targets: Record<RewardAward, string | null>;
    awards: Record<RewardAward, string | null>;
    counts: Record<RewardAward, number>;
};

export type RewardLeaderRow = {
    id: number;
    partner_name: string;
    partner_code: string | null;
    partner_phone: string | null;
    partner_district: string | null;
    qualifying_volume: string;
    progress: string | null;
    stage: 'in_progress' | 'reached' | 'given';
};

/** Leaders on their way to one award, highest sales first. The target and award are the same for every row, so they are said once. */
export default function RewardLeaders({ award, table, figures }: { award: RewardAward; table: MoneyTableData<RewardLeaderRow>; figures: RewardLeaderFigures }) {
    const translate = useT();
    const notSet = translate('treasury', 'not_set');
    const amountText = (amount: string | null) => (amount === null ? notSet : money(amount));

    const columns: MoneyColumn<RewardLeaderRow>[] = [
        {
            key: 'leader',
            title: translate('treasury', 'col_leader'),
            sortKey: 'name',
            phone: 'title',
            render: (row) => (
                <>
                    <b>{row.partner_name}</b>
                    <small className="cell-sub">{joined([row.partner_code, row.partner_phone, row.partner_district])}</small>
                </>
            ),
        },
        { key: 'volume', title: translate('treasury', 'col_counted_sales'), sortKey: 'volume', money: true, phone: 'amount', render: (row) => <b>{money(row.qualifying_volume)}</b> },
        { key: 'progress', title: translate('treasury', 'col_progress'), align: 'right', phone: 'sub', render: (row) => (row.progress === null ? notSet : `${number(row.progress)}%`) },
        { key: 'stage', title: translate('treasury', 'filter_status'), sortKey: 'status', phone: 'sub', render: (row) => <Badge status={row.stage} /> },
    ];

    return (
        <MoneyTable<RewardLeaderRow>
            data={table}
            prop="table"
            columns={columns}
            searchPlaceholder={translate('treasury', 'search_leaders')}
            hideViewChips
            actions={<span className="muted">{translate('treasury', 'leader_goal', { target: amountText(figures.targets[award]), award: amountText(figures.awards[award]) })}</span>}
            empty={translate('treasury', 'empty_leaders')}
        />
    );
}
