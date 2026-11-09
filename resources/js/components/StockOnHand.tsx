import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import Figures from './office/Figures';
import { Modal, date, money, number, type Row } from './UI';
import { useAddressVisit } from './MoneyTableFilters';
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
    branch_id?: number | null;
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

export type StockTableData = MoneyTableData<StockRow>;

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

function Units({ value, tone }: { value: number; tone?: 'warning' }) {
    if (value <= 0) {
        return <span className="sf-none">—</span>;
    }

    return <b className={tone === 'warning' ? 'text-amber' : undefined}>{number(value)}</b>;
}

function StatusBadge({ row }: { row: StockRow }) {
    const translate = useT();
    const t = (key: string) => translate('office_stock', key);
    if (row.active === false) {
        return <span className="badge archived">{t('status_hidden')}</span>;
    }
    if (row.is_stock_paused) {
        return <span className="badge held">{t('status_orders_stopped')}</span>;
    }
    if (row.level === 'out_of_stock') {
        return <span className="badge rejected">{translate('stock_lots', 'level_out_of_stock')}</span>;
    }
    if (row.level === 'low') {
        return <span className="badge held">{translate('stock_lots', 'level_low')}</span>;
    }

    return <span className="badge published">{t('level_full')}</span>;
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="os-fact">
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

type LotOnHand = { batch_number?: string | null; expires_on?: string | null; quantity: number; on_hold: boolean; expired: boolean };

/** One stock row in full: every stage, the batches, the branch split, the low-stock limit, and Stop orders. */
function StockDrawer({ row, lots, companyStock, canToggle, onClose }: {
    row: StockRow;
    lots: LotOnHand[];
    companyStock: number | null;
    canToggle: boolean;
    onClose: () => void;
}) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string | number>) => translate('office_stock', key, replace);

    const commands = (
        <div className="os-drawer-actions">
            <button
                type="button"
                className="button"
                disabled={!canToggle}
                onClick={() => router.post(`/commands/products/${row.id}/toggle-stock`, {}, { preserveScroll: true })}
            >
                {translate('portal', row.is_stock_paused ? 'resume_ordering' : 'pause_ordering')}
            </button>
            <Link className="button" href={`/products/${row.id}`}>{t('edit')}</Link>
        </div>
    );

    return (
        <Modal title={row.register_name || row.name} onClose={onClose} variant="drawer" badge={<StatusBadge row={row} />} footer={commands}>
            <div className="os-drawer">
                <dl className="os-facts">
                    {row.branch_name && <Fact label={translate('portal', 'col_branch')}>{row.branch_name}</Fact>}
                    <Fact label={t('card_in_stock')}><b>{number(row.in_stock)}</b></Fact>
                    <Fact label={t('stock_on_hold')}>{number(row.on_hold)}</Fact>
                    <Fact label={translate('stock_lots', 'stage_expired')}>{number(row.expired)}</Fact>
                    <Fact label={t('card_courier')}>{number(row.with_courier)}</Fact>
                    <Fact label={t('col_booked')}>{number(row.booked)}</Fact>
                    <Fact label={t('drawer_free_to_sell')}>{number(row.free_to_sell)}</Fact>
                    {companyStock !== null && <Fact label={t('drawer_all_branches')}>{number(companyStock)}</Fact>}
                    <Fact label={t('drawer_value')}>{money(row.stock_value)}</Fact>
                    <Fact label={t('mrp')}>{row.mrp != null && row.mrp !== '' ? money(row.mrp) : '—'}</Fact>
                    {row.cost_price != null && row.cost_price !== '' && <Fact label={translate('portal', 'col_cost')}>{money(row.cost_price)}</Fact>}
                    {row.dealer_price != null && row.dealer_price !== '' && <Fact label={t('dealer_price')}>{money(row.dealer_price)} {percentLabel(row.dealer_discount_pct)}</Fact>}
                    {row.sub_dealer_price != null && row.sub_dealer_price !== '' && <Fact label={t('sub_dealer_price')}>{money(row.sub_dealer_price)} {percentLabel(row.sub_dealer_discount_pct)}</Fact>}
                    <Fact label={t('drawer_low_limit')}>{number(row.low_stock_at)} <Link className="text-link" href={`/products/${row.id}`}>{t('drawer_change_limit')}</Link></Fact>
                </dl>
                <section className="os-drawer-section">
                    <h3>{t('drawer_batches')}</h3>
                    {lots.length === 0 ? <p className="os-remove-line">{t('drawer_no_batches')}</p> : (
                        <ul className="os-lots">
                            {lots.map((lot, index) => (
                                <li key={index} className="os-lot">
                                    <span>
                                        {lot.batch_number || t('drawer_no_batch')}
                                        <small>{lot.expires_on ? date(lot.expires_on) : t('drawer_no_expiry')}</small>
                                    </span>
                                    <span>
                                        {t('drawer_units', { count: number(lot.quantity) })}
                                        {lot.on_hold && <small>{t('stock_on_hold')}</small>}
                                        {lot.expired && <small className="text-danger">{translate('stock_lots', 'stage_expired')}</small>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </Modal>
    );
}

export default function StockOnHand({ table, canToggle, showBranch }: {
    table: StockTableData;
    canToggle: boolean;
    showBranch: boolean;
}) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string | number>) => translate('office_stock', key, replace);
    const visit = useAddressVisit('data');
    const { props: pageProps } = usePage<any>();
    const [openRowKey, setOpenRowKey] = useState<string | null>(null);
    const sums = table.totals.sums ?? {};
    const units = (key: string) => number(Number(sums[key] ?? 0));
    const active = table.state.filters;
    const openRow = (row: StockRow) => setOpenRowKey(row.row_key);

    const filterCard = (key: string, value: string) => ({
        active: active[key] === value,
        onClick: () => visit({ [key]: active[key] === value ? null : value }),
    });

    const columns: MoneyColumn<StockRow>[] = [
        {
            key: 'name',
            title: translate('portal', 'col_product'),
            sortKey: 'name',
            phone: 'title',
            render: (row) => (
                <>
                    <button type="button" className="os-name-link" onClick={() => openRow(row)}>{row.register_name || row.name}</button>
                    <small className="cell-sub">{[row.sku, showBranch ? row.branch_name : null, row.unit].filter(Boolean).join(' · ')}</small>
                    <small className="cell-sub sf-phone-stages">
                        {[
                            row.on_hold > 0 ? t('on_hold_short', { count: number(row.on_hold) }) : null,
                            row.with_courier > 0 ? `${t('col_courier')} ${number(row.with_courier)}` : null,
                            row.expired > 0 ? t('expired_short', { count: number(row.expired) }) : null,
                        ].filter(Boolean).join(' · ')}
                    </small>
                </>
            ),
        },
        {
            key: 'in_stock',
            title: t('col_in_stock'),
            sortKey: 'in_stock',
            align: 'right',
            phone: 'amount',
            render: (row) => (
                <>
                    <b className={row.level === 'out_of_stock' ? 'text-danger' : row.level === 'low' ? 'text-amber' : undefined}>{number(row.in_stock)}</b>
                    {row.on_hold > 0 ? <small className="cell-sub text-amber">{t('on_hold_short', { count: number(row.on_hold) })}</small> : null}
                    {row.expired > 0 ? <small className="cell-sub text-danger">{t('expired_short', { count: number(row.expired) })}</small> : null}
                </>
            ),
        },
        {
            key: 'booked',
            title: t('col_booked'),
            sortKey: 'booked',
            align: 'right',
            phone: 'hide',
            render: (row) => (row.booked > 0 ? (
                <span title={translate('portal', 'col_booked_units_hint')}>
                    {number(row.booked)}
                    <small className={`cell-sub${row.free_to_sell < 0 ? ' text-danger' : ''}`}>{translate('stock_lots', 'free_to_sell_short', { count: number(row.free_to_sell) })}</small>
                </span>
            ) : <span className="sf-none">—</span>),
        },
        { key: 'with_courier', title: t('col_courier'), sortKey: 'with_courier', align: 'right', phone: 'hide', render: (row) => <Units value={row.with_courier} /> },
        {
            key: 'expiry',
            title: translate('portal', 'col_expiry'),
            sortKey: 'expiry',
            phone: 'sub',
            render: (row) => <>{row.expires_on ? date(row.expires_on) : '—'}{row.batch_number ? <small className="cell-sub">{translate('portal', 'col_batch_short', { batch: row.batch_number })}</small> : null}</>,
        },
        { key: 'status', title: translate('portal', 'col_status'), phone: 'sub', render: (row) => <StatusBadge row={row} /> },
    ];

    const extraSorts = [
        { key: 'on_hold', title: t('stock_on_hold') },
        ...(showBranch ? [{ key: 'branch', title: translate('portal', 'col_branch') }] : []),
        { key: 'mrp', title: t('mrp') },
        { key: 'stock_value', title: t('card_value') },
    ];

    const openedRow = table.rows.data.find((row) => row.row_key === openRowKey);
    const stockProducts: Row[] = pageProps.data?.products ?? [];
    const openedProduct = openedRow ? (stockProducts.find((product) => product.id === openedRow.id && (product.branch_id == null || openedRow.branch_id == null || product.branch_id === openedRow.branch_id)) ?? stockProducts.find((product) => product.id === openedRow.id)) : undefined;

    return (
        <section className="stock-register-page">
            <Figures
                items={[
                    { key: 'in_stock', label: t('card_in_stock'), value: units('in_stock'), text: true },
                    { key: 'value', label: t('card_value'), value: String(sums.stock_value ?? '0') },
                    { key: 'low', label: t('card_low'), value: units('low_count'), text: true, ...filterCard('level', 'low') },
                    { key: 'courier', label: t('card_courier'), value: units('with_courier'), text: true, ...filterCard('stage', 'with_courier') },
                ]}
            />
            <div className="sf-table">
                <MoneyTable
                    data={table}
                    prop="data"
                    columns={columns}
                    searchPlaceholder={translate('portal', 'search_products')}
                    empty={translate('portal', 'stock_no_matches')}
                    extraSorts={extraSorts}
                    onRowOpen={openRow}
                />
            </div>
            {openedRow && (
                <StockDrawer
                    row={openedRow}
                    lots={openedProduct?.lots_on_hand ?? []}
                    companyStock={!showBranch && openedProduct?.company_stock !== undefined ? Number(openedProduct.company_stock) : null}
                    canToggle={canToggle}
                    onClose={() => setOpenRowKey(null)}
                />
            )}
        </section>
    );
}
