import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Download, SlidersHorizontal } from 'lucide-react';
import { Table, money, date, Badge, type Row, DateInput, number, Modal } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import OfficePage from './office/OfficePage';
import Figures from './office/Figures';
import type { OfficeHub } from './OfficeHubTabs';
import { useT } from '../shared/i18n/t';
import { businessTodayIso } from '../business-date';

interface PeriodState {
    period: string;
    from: string;
    to: string;
    label: string;
    timezone: string;
}

interface BranchMetric extends Row {
    id: number | null;
    code: string;
    name: string;
    order_count: number;
    order_value: string;
    income: string;
    outstanding: string;
    estimated_profit: string;
    estimated_cost: string;
}

interface PerformanceOrder extends Row {
    id: string;
    number: string;
    status: string;
    partner_name?: string | null;
    dealer_code?: string | null;
    delivered_value: string;
    delivered_paid: string;
    outstanding: string;
    estimated_profit: string;
    branch_name?: string | null;
    branch_code?: string | null;
    created_at: string;
}

interface PerformancePayload {
    period: PeriodState;
    totals: BranchMetric;
    branches: BranchMetric[];
    unassigned: BranchMetric;
    /** One server page of orders; the totals above cover every order in the period. */
    orders: Row & { data: PerformanceOrder[]; total: number };
    /** The list's search, column sort and its own totals, from the server. */
    orders_table?: Omit<MoneyTableData<PerformanceOrder>, 'rows'>;
    selected_branch_id?: number | null;
    can_view_all_branches?: boolean;
}

interface BranchOption {
    id: number;
    code: string;
    name: string;
}

interface BranchPerformanceHubProps {
    performance: PerformancePayload;
    branch_options?: BranchOption[];
}

type ReportDates = { from: string; to?: string };

const VIEW_WORDS: { value: string; word: string }[] = [
    { value: 'today', word: 'orders_period_today' },
    { value: 'week', word: 'orders_period_week' },
    { value: 'month', word: 'orders_period_month' },
    { value: 'year', word: 'orders_period_year' },
    { value: 'day', word: 'mt_view_day' },
    { value: 'custom', word: 'mt_view_range' },
];

const isWholeDate = (value: string): boolean => /^\d{4}-\d{2}-\d{2}$/.test(value);

/** The view choice of a report: a preset, one date picked from the calendar, or a range of dates. */
export function ReportViewChips({ active, from, to, extraChoices = [], allowAll = false, onChoose }: {
    active: string;
    from: string;
    to: string;
    extraChoices?: { value: string; word: string }[];
    /** Adds the "all dates" choice, for reports that can show every date at once. */
    allowAll?: boolean;
    onChoose: (view: string, dates?: ReportDates) => void;
}) {
    const translate = useT();
    const [choosing, setChoosing] = useState<string | null>(null);
    const [dayFrom, setDayFrom] = useState(from);
    const [dayTo, setDayTo] = useState(to);

    useEffect(() => {
        setChoosing(null);
        setDayFrom(from);
        setDayTo(to);
    }, [active, from, to]);

    const shown = choosing ?? active;
    const choices = [...VIEW_WORDS.slice(0, 3), ...extraChoices, ...VIEW_WORDS.slice(3), ...(allowAll ? [{ value: '', word: 'orders_period_all' }] : [])];
    const choose = (value: string) => {
        if (value === 'day' || value === 'custom') {
            setChoosing(value);
            setDayFrom(value === active ? from : '');
            setDayTo(value === active ? to : '');

            return;
        }
        setChoosing(null);
        onChoose(value);
    };
    const changeFrom = (value: string) => {
        setDayFrom(value);
        if (shown === 'day' && isWholeDate(value)) {
            onChoose('day', { from: value });
        } else if (shown === 'custom' && isWholeDate(value) && isWholeDate(dayTo)) {
            onChoose('custom', { from: value, to: dayTo });
        }
    };
    const changeTo = (value: string) => {
        setDayTo(value);
        if (isWholeDate(dayFrom) && isWholeDate(value)) {
            onChoose('custom', { from: dayFrom, to: value });
        }
    };

    return (
        <>
            <div className="mt-views" role="group" aria-label={translate('portal', 'mt_view_label')}>
                {choices.map((choice) => (
                    <button key={choice.value || 'all'} type="button" className="mt-view" aria-pressed={shown === choice.value} onClick={() => choose(choice.value)}>
                        {translate('portal', choice.word)}
                    </button>
                ))}
            </div>
            {shown === 'day' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_view_day')}<DateInput value={dayFrom} onChange={(event) => changeFrom(event.target.value)} /></label>
                </div>
            )}
            {shown === 'custom' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_date_from')}<DateInput value={dayFrom} onChange={(event) => changeFrom(event.target.value)} /></label>
                    <label>{translate('portal', 'mt_date_to')}<DateInput value={dayTo} onChange={(event) => changeTo(event.target.value)} /></label>
                </div>
            )}
        </>
    );
}

/** The filter button a phone shows in place of the drop-downs. It opens them in a sheet. */
export function ReportFilterSheet({ children }: { children: React.ReactNode }) {
    const translate = useT();
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" className="button small mt-sheet-button" onClick={() => setOpen(true)}>
                <SlidersHorizontal size={15} aria-hidden="true" />
                {translate('portal', 'mt_filters')}
            </button>
            {open && <Modal title={translate('portal', 'mt_filters')} onClose={() => setOpen(false)}><div className="mt-sheet">{children}</div></Modal>}
        </>
    );
}

/** On a phone each row is a card: who and the facts on the left, the amount on the right. */
export function ReportCards<T extends Row>({ rows, empty, title, lines, amount }: {
    rows: T[];
    empty: string;
    title: (row: T) => React.ReactNode;
    lines: (row: T) => React.ReactNode[];
    amount: (row: T) => React.ReactNode;
}) {
    return (
        <ul className="mt-cards">
            {rows.length === 0 && <li className="mt-card">{empty}</li>}
            {rows.map((row, index) => (
                <li key={String(row.id ?? index)} className="mt-card">
                    <div className="mt-card-main">
                        <div className="mt-card-title">{title(row)}</div>
                        <div className="mt-card-lines">{lines(row).map((line, lineIndex) => <span key={lineIndex}>{line}</span>)}</div>
                    </div>
                    <div className="mt-card-amount">{amount(row)}</div>
                </li>
            ))}
        </ul>
    );
}

const addDays = (isoDate: string, days: number): string => {
    const moved = new Date(`${isoDate}T00:00:00Z`);
    moved.setUTCDate(moved.getUTCDate() + days);

    return moved.toISOString().slice(0, 10);
};

/** The dates of a view the report can only be asked for by dates. The week runs Sunday to Saturday, as on every other list. */
const datesForView = (view: string): ReportDates | null => {
    const today = businessTodayIso();
    if (view === 'week') {
        const start = addDays(today, -new Date(`${today}T00:00:00Z`).getUTCDay());

        return { from: start, to: addDays(start, 6) };
    }
    if (view === 'year') {
        return { from: `${today.slice(0, 4)}-01-01`, to: `${today.slice(0, 4)}-12-31` };
    }

    return null;
};

export default function BranchPerformanceHub({ performance, branch_options = [] }: BranchPerformanceHubProps) {
    const translate = useT();
    const { url, props: pageProps } = usePage<any>();
    const hub: OfficeHub | null = pageProps.data?.hub ?? null;
    const canDownload = (pageProps.auth.capabilities as string[]).includes('reports');
    const address = new URL(url || '/', 'http://branch-report.local');
    const showsOrders = address.searchParams.get('tab') === 'orders';
    const namedView = address.searchParams.get('view') ?? '';
    const period = performance.period;
    const activeView = period.period !== 'custom' ? period.period : (['week', 'year', 'day'].includes(namedView) ? namedView : 'custom');
    const selectedBranch = performance.selected_branch_id ? String(performance.selected_branch_id) : '';

    const visit = (next: { period?: string; from?: string; to?: string; view?: string; branch_id?: string }) => {
        const nextPeriod = next.period ?? period.period;
        const nextBranch = next.branch_id ?? selectedBranch;
        router.get(address.pathname, {
            period: nextPeriod,
            from: nextPeriod === 'custom' ? (next.from ?? period.from) : undefined,
            to: nextPeriod === 'custom' ? (next.to ?? period.to) : undefined,
            view: nextPeriod === 'custom' ? (next.view ?? (namedView || undefined)) : undefined,
            tab: showsOrders ? 'orders' : undefined,
            q: address.searchParams.get('q') || undefined,
            sort: address.searchParams.get('sort') || undefined,
            dir: address.searchParams.get('dir') || undefined,
            branch_id: nextBranch || undefined,
            // No branch chosen means every branch the reader may see, even when the top bar is on one branch.
            all_branches: nextBranch ? undefined : 1,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const chooseView = (view: string, dates?: ReportDates) => {
        if (view === 'today' || view === 'month') {
            visit({ period: view });

            return;
        }
        if (view === 'day' && dates) {
            visit({ period: 'custom', from: dates.from, to: dates.from, view });

            return;
        }
        if (view === 'custom' && dates) {
            visit({ period: 'custom', from: dates.from, to: dates.to, view });

            return;
        }
        const presetDates = datesForView(view);
        if (presetDates) {
            visit({ period: 'custom', from: presetDates.from, to: presetDates.to, view });
        }
    };

    const tabHref = (tab: string | null): string => {
        const params = new URLSearchParams(address.search);
        params.delete('page');
        if (tab) {
            params.set('tab', tab);
        } else {
            params.delete('tab');
        }
        const query = params.toString();

        return query ? `${address.pathname}?${query}` : address.pathname;
    };

    const rows: BranchMetric[] = [
        ...performance.branches,
        ...(performance.unassigned.order_count > 0 ? [performance.unassigned] : []),
    ];
    const totals = performance.totals;
    const rangeTotalLabel = translate('portal', 'branch_perf_range_total');
    const exportQuery = new URLSearchParams({
        period: period.period,
        ...(period.period === 'custom' ? { from: period.from, to: period.to } : {}),
        ...(performance.selected_branch_id ? { branch_id: String(performance.selected_branch_id) } : {}),
    }).toString();
    const branchPicker = (
        <select aria-label={translate('portal', 'col_branch')} value={selectedBranch} onChange={(event) => visit({ branch_id: event.target.value })}>
            <option value="">{translate('portal', 'branch_perf_all_branches')}</option>
            {branch_options.map((branch) => (
                <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>
            ))}
        </select>
    );
    const branchName = (row: BranchMetric) => (row.id
        ? <button type="button" className="text-link" onClick={() => visit({ branch_id: String(row.id) })}><b>{row.name}</b></button>
        : <b>{row.name}</b>);
    const orderTable: MoneyTableData<PerformanceOrder> | undefined = performance.orders_table && { ...performance.orders_table, rows: performance.orders };
    const orderColumns: MoneyColumn<PerformanceOrder>[] = [
        { key: 'number', title: translate('portal', 'col_order'), sortKey: 'number', phone: 'title', render: (row) => <><b>{row.number}</b><small className="cell-sub">{row.partner_name}{row.dealer_code ? ` · ${row.dealer_code}` : ''}</small></> },
        { key: 'created_at', title: translate('portal', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.created_at) },
        { key: 'branch_name', title: translate('portal', 'col_branch'), sortKey: 'branch', phone: 'hide', render: (row) => (row.branch_code ? `${row.branch_name} · ${row.branch_code}` : (row.branch_name || '—')) },
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'sub', render: (row) => <Badge status={row.status} /> },
        { key: 'delivered_value', title: translate('portal', 'branch_perf_order_value'), sortKey: 'delivered_value', money: true, total: 'delivered_value', phone: 'amount', render: (row) => money(row.delivered_value) },
        { key: 'delivered_paid', title: translate('portal', 'branch_perf_income'), sortKey: 'delivered_paid', money: true, total: 'delivered_paid', phone: 'hide', render: (row) => money(row.delivered_paid) },
        { key: 'outstanding', title: translate('portal', 'branch_perf_outstanding'), sortKey: 'outstanding', money: true, phone: 'hide', render: (row) => money(row.outstanding) },
        { key: 'estimated_profit', title: translate('portal', 'branch_perf_profit'), sortKey: 'estimated_profit', money: true, total: 'estimated_profit', phone: 'hide', render: (row) => money(row.estimated_profit) },
    ];

    return (
        <div data-tour="branch-performance-hub">
            <OfficePage
                title={hub?.label ?? translate('portal', 'branch_perf_title')}
                subtitle={translate('portal', 'branch_perf_range', { from: date(period.from), to: date(period.to) })}
                hub={hub}
                action={canDownload ? <a className="button small" href={`/exports/branch-performance?${exportQuery}`} title={translate('portal', 'branch_perf_export_hint')}><Download size={14} aria-hidden="true" /> {translate('portal', 'acct_export_excel')}</a> : undefined}
            >
                <Figures
                    items={[
                        { key: 'order_value', label: translate('portal', 'branch_perf_order_value'), value: totals.order_value },
                        { key: 'income', label: translate('portal', 'branch_perf_income'), value: totals.income },
                        { key: 'profit', label: translate('portal', 'branch_perf_profit'), value: totals.estimated_profit },
                        { key: 'outstanding', label: translate('portal', 'branch_perf_outstanding'), value: totals.outstanding },
                    ]}
                />
                <div className="money-table">
                    <div className="mt-segments">
                        <div className="office-switch" role="group" aria-label={translate('portal', 'branch_perf_title')}>
                            <Link href={tabHref(null)} preserveState preserveScroll replace className={showsOrders ? undefined : 'is-active'} aria-current={showsOrders ? undefined : 'true'}>{translate('portal', 'branch_perf_by_branch')}</Link>
                            <Link href={tabHref('orders')} preserveState preserveScroll replace className={showsOrders ? 'is-active' : undefined} aria-current={showsOrders ? 'true' : undefined}>{translate('portal', 'branch_perf_orders_detail')} ({number(performance.orders.total ?? 0)})</Link>
                        </div>
                    </div>
                    <div className="mt-toolbar" data-tour="branch-perf-period">
                        <ReportFilterSheet><label>{translate('portal', 'col_branch')}{branchPicker}</label></ReportFilterSheet>
                        <ReportViewChips active={activeView} from={period.from} to={period.to} onChoose={chooseView} />
                        <div className="mt-filters">{branchPicker}</div>
                    </div>
                    {!showsOrders && (
                        <>
                            <div className="mt-table-wrap table-panel">
                                <Table
                                    rows={rows}
                                    totalLabel={rangeTotalLabel}
                                    columns={[
                                        { key: 'name', title: translate('portal', 'col_branch'), render: (row: BranchMetric) => <>{branchName(row)}<small className="cell-sub">{row.code}</small></> },
                                        { key: 'order_count', title: translate('portal', 'branch_perf_orders'), align: 'right', render: (row: BranchMetric) => number(row.order_count), footer: () => number(totals.order_count) },
                                        { key: 'order_value', title: translate('portal', 'branch_perf_order_value'), money: true, render: (row: BranchMetric) => money(row.order_value), footer: () => money(totals.order_value) },
                                        { key: 'income', title: translate('portal', 'branch_perf_income'), money: true, render: (row: BranchMetric) => money(row.income), footer: () => money(totals.income) },
                                        { key: 'estimated_profit', title: translate('portal', 'branch_perf_profit'), money: true, render: (row: BranchMetric) => money(row.estimated_profit), footer: () => money(totals.estimated_profit) },
                                        { key: 'outstanding', title: translate('portal', 'branch_perf_outstanding'), money: true, render: (row: BranchMetric) => money(row.outstanding), footer: () => money(totals.outstanding) },
                                    ]}
                                />
                            </div>
                            <ReportCards<BranchMetric>
                                rows={rows}
                                empty={translate('portal', 'branch_perf_no_orders')}
                                title={(row) => <>{branchName(row)}<small className="cell-sub">{row.code}</small></>}
                                lines={(row) => [
                                    `${translate('portal', 'branch_perf_orders')}: ${number(row.order_count)}`,
                                    `${translate('portal', 'branch_perf_income')}: ${money(row.income)}`,
                                    `${translate('portal', 'branch_perf_profit')}: ${money(row.estimated_profit)}`,
                                    `${translate('portal', 'branch_perf_outstanding')}: ${money(row.outstanding)}`,
                                ]}
                                amount={(row) => money(row.order_value)}
                            />
                        </>
                    )}
                </div>
                {showsOrders && (
                    <MoneyTable<PerformanceOrder>
                        data={orderTable}
                        prop="data"
                        columns={orderColumns}
                        searchPlaceholder={translate('office_reports', 'branch_perf_search')}
                        hideViewChips
                        empty={translate('portal', 'branch_perf_no_orders')}
                    />
                )}
            </OfficePage>
        </div>
    );
}
