import { router } from '@inertiajs/react';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { date, money, number } from './UI';
import { StockFilterControls, useAddressVisit, type StockFilterBarData } from './StockFilterBar';
import { useT } from '../shared/i18n/t';
import { localizeDigits } from '../business-date';

type StockRow = {
    id: number;
    row_key: string;
    name: string;
    register_name?: string;
    sku: string;
    category?: string | null;
    sub_category?: string | null;
    unit?: string | null;
    mrp?: string | number | null;
    cost_price?: string | number | null;
    dealer_price?: string | number | null;
    dealer_discount_pct?: string | null;
    sub_dealer_price?: string | number | null;
    sub_dealer_discount_pct?: string | null;
    expires_on?: string | null;
    batch_number?: string | null;
    branch_name?: string | null;
    physical: number;
    in_stock: number;
    on_hold: number;
    expired: number;
    with_courier: number;
    booked: number;
    free_to_sell: number;
    level: 'out_of_stock' | 'low' | 'full';
    low_stock_at: number;
    stock_value: string;
    is_stock_paused?: boolean;
    active?: boolean;
};

export type StockTableData = MoneyTableData<StockRow> & { filter_bar: StockFilterBarData };

function percentLabel(value?: string | null): string {
    if (value == null || value === '') {
        return '';
    }
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
        return '';
    }
    const trimmed = Number.isInteger(amount) ? String(amount) : amount.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');

    return `${localizeDigits(trimmed)}%`;
}

function Units({ value, tone }: { value: number; tone?: 'danger' | 'warning' }) {
    if (value <= 0) {
        return <span className="sf-none">—</span>;
    }

    return <b className={tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-amber' : undefined}>{number(value)}</b>;
}

function LevelBadge({ level }: { level: StockRow['level'] }) {
    const translate = useT();
    if (level === 'full') {
        return null;
    }

    return <span className={`badge ${level === 'out_of_stock' ? 'rejected' : 'held'}`}>{translate('stock_lots', level === 'out_of_stock' ? 'level_out_of_stock' : 'level_low')}</span>;
}

export default function StockOnHand({ table, branchName, branchCount, canToggle, showBranch }: {
    table: StockTableData;
    branchName: string;
    branchCount: number;
    canToggle: boolean;
    showBranch: boolean;
}) {
    const translate = useT();
    const visit = useAddressVisit('data');
    const sums = table.totals.sums ?? {};
    const units = (key: string) => number(Number(sums[key] ?? 0));
    const totalUnits = Number(sums.in_stock ?? 0) + Number(sums.on_hold ?? 0) + Number(sums.expired ?? 0) + Number(sums.with_courier ?? 0);
    const activeStage = table.filter_bar.active.stage;

    const branchSummary = branchCount > 0 ? `${branchName} · ${branchCount} ${translate('portal', 'branches_short')}` : branchName;

    const stageCards: { key: string; label: string; value: string; stage?: string }[] = [
        { key: 'total', label: translate('stock_lots', 'stage_total'), value: number(totalUnits) },
        { key: 'in_stock', label: translate('stock_lots', 'stage_in_stock'), value: units('in_stock') },
        { key: 'on_hold', label: translate('stock_lots', 'stage_on_hold'), value: units('on_hold'), stage: 'on_hold' },
        { key: 'with_courier', label: translate('stock_lots', 'stage_with_courier'), value: units('with_courier'), stage: 'with_courier' },
        { key: 'expired', label: translate('stock_lots', 'stage_expired'), value: units('expired'), stage: 'expired' },
    ];

    const columns: MoneyColumn<StockRow>[] = [
        {
            key: 'name',
            title: translate('portal', 'col_product'),
            sortKey: 'name',
            phone: 'title',
            render: (row) => (
                <>
                    {row.register_name || row.name} <LevelBadge level={row.level} />
                    <small className="cell-sub">{[row.sku, [row.category, row.sub_category].filter(Boolean).join(' › '), row.unit].filter(Boolean).join(' · ')}</small>
                    <small className="cell-sub sf-phone-stages">
                        {[
                            row.on_hold > 0 ? `${translate('stock_lots', 'stage_on_hold')} ${number(row.on_hold)}` : null,
                            row.with_courier > 0 ? `${translate('stock_lots', 'stage_with_courier')} ${number(row.with_courier)}` : null,
                            row.expired > 0 ? `${translate('stock_lots', 'stage_expired')} ${number(row.expired)}` : null,
                        ].filter(Boolean).join(' · ')}
                    </small>
                </>
            ),
        },
        ...(showBranch ? [{ key: 'branch', title: translate('portal', 'col_branch'), sortKey: 'branch', phone: 'sub' as const, render: (row: StockRow) => row.branch_name || '—' }] : []),
        {
            key: 'in_stock',
            title: translate('stock_lots', 'stage_in_stock'),
            sortKey: 'in_stock',
            align: 'right',
            phone: 'amount',
            render: (row) => <b className={row.level === 'out_of_stock' ? 'text-danger' : row.level === 'low' ? 'text-amber' : undefined}>{number(row.in_stock)}</b>,
        },
        { key: 'on_hold', title: translate('stock_lots', 'stage_on_hold'), sortKey: 'on_hold', align: 'right', phone: 'hide', render: (row) => <Units value={row.on_hold} tone="warning" /> },
        { key: 'with_courier', title: translate('stock_lots', 'stage_with_courier'), sortKey: 'with_courier', align: 'right', phone: 'hide', render: (row) => <Units value={row.with_courier} /> },
        { key: 'expired', title: translate('stock_lots', 'stage_expired'), align: 'right', phone: 'hide', render: (row) => <Units value={row.expired} tone="danger" /> },
        {
            key: 'booked',
            title: translate('portal', 'col_booked_units'),
            sortKey: 'booked',
            align: 'right',
            phone: 'hide',
            render: (row) => (row.booked > 0 ? (
                <span title={translate('portal', 'col_booked_units_hint')}>
                    {number(row.booked)}
                    <small className={`cell-sub${row.free_to_sell < 0 ? ' text-danger' : ''}`}>{translate('portal', 'stock_free_to_sell', { count: number(row.free_to_sell) })}</small>
                </span>
            ) : <span className="sf-none">—</span>),
        },
        {
            key: 'expiry',
            title: translate('portal', 'col_expiry'),
            sortKey: 'expiry',
            phone: 'sub',
            render: (row) => <>{row.expires_on ? date(row.expires_on) : '—'}{row.batch_number ? <small className="cell-sub">{translate('portal', 'col_batch_short', { batch: row.batch_number })}</small> : null}</>,
        },
        {
            key: 'mrp',
            title: translate('portal', 'col_mrp'),
            sortKey: 'mrp',
            align: 'right',
            phone: 'hide',
            render: (row) => (
                <span className="sf-price">
                    {row.mrp != null && row.mrp !== '' ? money(row.mrp) : '—'}
                    {row.cost_price != null && row.cost_price !== '' ? <small className="cell-sub">{translate('portal', 'col_cost')} {money(row.cost_price)}</small> : null}
                    {row.dealer_price != null && row.dealer_price !== '' ? <small className="cell-sub">{translate('portal', 'col_dealer_commission')} {money(row.dealer_price)} {percentLabel(row.dealer_discount_pct)}</small> : null}
                    {row.sub_dealer_price != null && row.sub_dealer_price !== '' ? <small className="cell-sub">{translate('portal', 'col_subdealer_commission')} {money(row.sub_dealer_price)} {percentLabel(row.sub_dealer_discount_pct)}</small> : null}
                </span>
            ),
        },
        { key: 'stock_value', title: translate('portal', 'branch_stock_value'), sortKey: 'stock_value', money: true, total: 'stock_value', phone: 'hide' },
        {
            key: 'action',
            title: '',
            phone: 'hide',
            render: (row) => (
                <button
                    type="button"
                    className={`button small ${row.is_stock_paused ? 'stock-resume' : 'stock-pause'}`}
                    disabled={!canToggle}
                    onClick={() => router.post(`/commands/products/${row.id}/toggle-stock`, {}, { preserveScroll: true })}
                >
                    {row.is_stock_paused ? translate('portal', 'resume_ordering') : translate('portal', 'pause_ordering')}
                </button>
            ),
        },
    ];

    const sortOptions = columns.filter((column) => column.sortKey).map((column) => ({ key: column.sortKey as string, title: column.title }));

    return (
        <section className="stock-register-page">
            <div className="sf-heading">
                <h2>{translate('portal', 'stock_on_hand')}</h2>
                <span>{branchSummary}</span>
            </div>
            <div className="sf-stage-cards">
                {stageCards.map((card) => {
                    const content = (<><span>{card.label}</span><b>{card.value}</b></>);
                    if (!card.stage) {
                        return <div key={card.key} className="sf-stage-card">{content}</div>;
                    }

                    return (
                        <button key={card.key} type="button" className={`sf-stage-card is-button${activeStage === card.stage ? ' is-on' : ''}`} aria-pressed={activeStage === card.stage} onClick={() => visit({ stage: activeStage === card.stage ? null : card.stage ?? null })}>
                            {content}
                        </button>
                    );
                })}
            </div>
            <div className="sf-table">
                <MoneyTable
                    data={table}
                    prop="data"
                    columns={columns}
                    searchPlaceholder={translate('portal', 'search_products')}
                    empty={translate('portal', 'stock_no_matches')}
                    actions={<StockFilterControls bar={table.filter_bar} prop="data" sortOptions={sortOptions} sortState={{ sort: table.state.sort, dir: table.state.dir }} />}
                />
            </div>
        </section>
    );
}
