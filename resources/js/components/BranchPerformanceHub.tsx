import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { BarChart3, Building2, CalendarRange } from 'lucide-react';
import { Table, money, date, Badge, type Row } from './UI';
import { useT } from '../shared/i18n/t';

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
    total: string;
    paid: string;
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
    orders: PerformanceOrder[];
    selected_branch_id?: number | null;
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

export default function BranchPerformanceHub({ performance, branch_options = [] }: BranchPerformanceHubProps) {
    const translate = useT();
    const [period, setPeriod] = useState(performance.period.period || 'month');
    const [from, setFrom] = useState(performance.period.from);
    const [to, setTo] = useState(performance.period.to);
    const [branchId, setBranchId] = useState(performance.selected_branch_id ? String(performance.selected_branch_id) : '');

    const apply = (next?: { period?: string; from?: string; to?: string; branch_id?: string }) => {
        const selectedPeriod = next?.period ?? period;
        router.get('/branch-performance', {
            period: selectedPeriod,
            from: selectedPeriod === 'custom' ? (next?.from ?? from) : undefined,
            to: selectedPeriod === 'custom' ? (next?.to ?? to) : undefined,
            branch_id: (next?.branch_id ?? branchId) || undefined,
        }, { preserveState: true, replace: true });
    };

    const rows: BranchMetric[] = [
        ...performance.branches,
        ...(performance.unassigned.order_count > 0 ? [performance.unassigned] : []),
    ];

    return (
        <div data-tour="branch-performance-hub">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <BarChart3 size={18} /> {translate('portal', 'branch_perf_title')}
                        </h2>
                        <p className="muted">{translate('portal', 'branch_perf_hint')}</p>
                    </div>
                    <span className="badge">{performance.period.timezone}</span>
                </div>

                <form
                    className="filter-bar"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply();
                    }}
                    style={{ flexWrap: 'wrap', gap: 10 }}
                >
                    <div className="preset-chips" data-tour="branch-perf-period">
                        {[
                            { value: 'today', label: translate('portal', 'branch_perf_today') },
                            { value: 'month', label: translate('portal', 'branch_perf_month') },
                            { value: 'custom', label: translate('portal', 'branch_perf_custom') },
                        ].map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                className={`button small ${period === option.value ? 'dark' : ''}`}
                                onClick={() => {
                                    setPeriod(option.value);
                                    apply({ period: option.value });
                                }}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                    {period === 'custom' && (
                        <>
                            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                                <CalendarRange size={14} />
                                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </label>
                            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            </label>
                        </>
                    )}
                    <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                        <Building2 size={14} />
                        <select value={branchId} onChange={(e) => setBranchId(e.target.value)}>
                            <option value="">{translate('portal', 'branch_perf_all_branches')}</option>
                            {branch_options.map((branch) => (
                                <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>
                            ))}
                        </select>
                    </label>
                    <button type="submit" className="button small dark">{translate('portal', 'branch_perf_apply')}</button>
                </form>

                <div className="metric-grid" style={{ marginTop: 16 }}>
                    <div className="metric"><span>{translate('portal', 'branch_perf_orders')}</span><strong>{performance.totals.order_count}</strong></div>
                    <div className="metric"><span>{translate('portal', 'branch_perf_order_value')}</span><strong>{money(performance.totals.order_value)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'branch_perf_income')}</span><strong>{money(performance.totals.income)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'branch_perf_profit')}</span><strong>{money(performance.totals.estimated_profit)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'branch_perf_outstanding')}</span><strong>{money(performance.totals.outstanding)}</strong></div>
                </div>
                <p className="muted" style={{ marginTop: 8 }}>{translate('portal', 'branch_perf_profit_note')}</p>
            </section>

            <section className="panel table-panel">
                <div className="panel-heading">
                    <h2>{translate('portal', 'branch_perf_by_branch')}</h2>
                    <span>{translate('portal', 'branch_perf_range', { from: performance.period.from, to: performance.period.to })}</span>
                </div>
                <Table
                    rows={rows}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'col_branch'),
                            render: (r: BranchMetric) => (
                                <>
                                    <b>{r.name}</b>
                                    <small className="cell-sub">{r.code}</small>
                                </>
                            ),
                        },
                        { key: 'order_count', title: translate('portal', 'branch_perf_orders') },
                        { key: 'order_value', title: translate('portal', 'branch_perf_order_value'), render: (r: BranchMetric) => money(r.order_value) },
                        { key: 'income', title: translate('portal', 'branch_perf_income'), render: (r: BranchMetric) => money(r.income) },
                        { key: 'estimated_profit', title: translate('portal', 'branch_perf_profit'), render: (r: BranchMetric) => money(r.estimated_profit) },
                        { key: 'outstanding', title: translate('portal', 'branch_perf_outstanding'), render: (r: BranchMetric) => money(r.outstanding) },
                        {
                            key: 'action',
                            title: '',
                            render: (r: BranchMetric) => r.id ? (
                                <button
                                    type="button"
                                    className="button small"
                                    onClick={() => {
                                        setBranchId(String(r.id));
                                        apply({ branch_id: String(r.id) });
                                    }}
                                >
                                    {translate('portal', 'branch_perf_filter_branch')}
                                </button>
                            ) : '—',
                        },
                    ]}
                />
            </section>

            <section className="panel table-panel">
                <div className="panel-heading">
                    <h2>{translate('portal', 'branch_perf_orders_detail')}</h2>
                    <span>{performance.orders.length}</span>
                </div>
                <Table
                    rows={performance.orders}
                    columns={[
                        {
                            key: 'number',
                            title: translate('portal', 'col_order'),
                            render: (r: PerformanceOrder) => (
                                <>
                                    <b>{r.number}</b>
                                    <small className="cell-sub">{r.partner_name}{r.dealer_code ? ` · ${r.dealer_code}` : ''}</small>
                                </>
                            ),
                        },
                        {
                            key: 'branch_name',
                            title: translate('portal', 'col_branch'),
                            render: (r: PerformanceOrder) => r.branch_code ? `${r.branch_name} · ${r.branch_code}` : (r.branch_name || '—'),
                        },
                        { key: 'status', title: translate('portal', 'col_status'), render: (r: PerformanceOrder) => <Badge status={r.status} /> },
                        { key: 'total', title: translate('portal', 'branch_perf_order_value'), render: (r: PerformanceOrder) => money(r.total) },
                        { key: 'paid', title: translate('portal', 'branch_perf_income'), render: (r: PerformanceOrder) => money(r.paid) },
                        { key: 'estimated_profit', title: translate('portal', 'branch_perf_profit'), render: (r: PerformanceOrder) => money(r.estimated_profit) },
                        { key: 'created_at', title: translate('portal', 'col_date'), render: (r: PerformanceOrder) => date(r.created_at) },
                    ]}
                />
            </section>
        </div>
    );
}
