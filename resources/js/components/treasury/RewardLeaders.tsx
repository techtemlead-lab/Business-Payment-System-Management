import { Badge, money, number } from '../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import { joined } from './format';
import Figures from '../office/Figures';
import { useT } from '../../shared/i18n/t';

export type RewardAward = 'bike' | 'car';

export type RewardPool = { balance: string; own: string; old: string; award: string | null; prizes_payable: number | null; waiting_count: number; waiting_amount: string };

export type RewardLeaderFigures = {
    targets: Record<RewardAward, string | null>;
    awards: Record<RewardAward, string | null>;
    counts: Record<RewardAward, number>;
    pools: Record<RewardAward, RewardPool>;
    /** The awards this fund's page lists: one for the Bike or Car fund, both for the old combined fund. */
    shown: RewardAward[];
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

/** What each prize fund on this page holds and can pay: its own money, the old combined money that can also pay, the prize amount, how many prizes can be paid now and how many are waiting. */
export function VehiclePoolsPanel({ pools, shown }: { pools: RewardLeaderFigures['pools']; shown: RewardAward[] }) {
    const translate = useT();
    const notSet = translate('money_core', 'vp_not_set');
    const single = shown.length === 1;

    return (
        <section>
            <h3 className="hub-title is-sm">{translate('money_core', single ? 'vp_title_one' : 'vp_title')}</h3>
            <p className="hub-hint">{translate('money_core', single ? 'vp_intro_one' : 'vp_intro')}</p>
            {shown.map((award) => (
                <div key={award}>
                    {!single && <h4 className="hub-title is-sm">{translate('treasury', `leaders_${award}`)}</h4>}
                    <Figures
                        items={[
                            { key: 'balance', label: translate('money_core', single ? 'vp_own' : 'vp_balance'), value: single ? pools[award].own : pools[award].balance, headline: true },
                            { key: 'award', label: translate('money_core', 'vp_award'), value: pools[award].award ?? notSet, text: pools[award].award === null },
                            { key: 'payable', label: translate('money_core', 'vp_payable'), value: pools[award].prizes_payable === null ? notSet : number(pools[award].prizes_payable), text: true },
                            { key: 'waiting', label: translate('money_core', 'vp_waiting'), value: translate('money_core', 'vp_waiting_value', { count: number(pools[award].waiting_count), amount: number(pools[award].waiting_amount) }), text: true },
                        ]}
                    />
                    {single && Number(pools[award].old) > 0 && (
                        <p className="hub-hint">
                            {translate('money_core', 'vp_old')}: <b>{money(pools[award].old)}</b>
                        </p>
                    )}
                </div>
            ))}
        </section>
    );
}

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
