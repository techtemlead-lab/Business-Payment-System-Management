import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Download, FileBarChart2 } from 'lucide-react';
import { Badge, money, date, Table, type Row, DateInput } from './UI';
import { useT } from '../shared/i18n/t';

export default function PartnerFullReportHub({ report }: { report: Row }) {
    const { portalBase: base } = usePage<any>().props;
    const translate = useT();
    const summary = report?.summary ?? {};
    const filters = report?.filters ?? {};
    const [from, setFrom] = useState(String(filters.from ?? ''));
    const [to, setTo] = useState(String(filters.to ?? ''));
    const preset = String(filters.preset ?? '');
    const commissions: Row[] = report?.commissions ?? [];
    const orders: Row[] = report?.orders ?? [];
    const teamActivity: Row[] = report?.team_activity ?? [];
    const teamMembers: Row[] = report?.team_members ?? [];
    const query = new URLSearchParams();
    if (from) query.set('from', from);
    if (to) query.set('to', to);
    if (preset) query.set('preset', preset);
    const querySuffix = query.toString() ? `?${query.toString()}` : '';

    return (
        <div className="partner-full-report">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2 style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                            <FileBarChart2 size={18} /> {translate('portal', 'full_report')}
                        </h2>
                        <p className="muted">{translate('portal', 'full_report_hint')}</p>
                    </div>
                    <a className="button small dark" href={`${base}/full-report/pdf${querySuffix}`} target="_blank" rel="noopener noreferrer" download="partner-statement.pdf" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                        <Download size={15} /> {translate('portal', 'download_pdf')}
                    </a>
                </div>
                <form
                    className="filter-bar"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(`${base}/full-report`, { from: from || undefined, to: to || undefined }, { preserveState: true });
                    }}
                >
                    <div className="preset-chips">
                        {(['today','week','month','year'] as const).map((key) => (
                            <button key={key} type="button" className={`button small ${preset===key?'dark':''}`} onClick={() => router.get(`${base}/full-report`, { preset: key }, { preserveState: true })}>
                                {translate('portal', `preset_${key}`)}
                            </button>
                        ))}
                    </div>
                    <label className="muted" style={{ display: 'grid', gap: 4, fontSize: 11 }}>
                        {translate('portal', 'date_from')}
                        <DateInput value={from} onChange={(event) => setFrom(event.target.value)} />
                    </label>
                    <label className="muted" style={{ display: 'grid', gap: 4, fontSize: 11 }}>
                        {translate('portal', 'date_to')}
                        <DateInput value={to} onChange={(event) => setTo(event.target.value)} />
                    </label>
                    <button className="button small" type="submit">{translate('portal', 'apply_dates')}</button>
                    {(filters.from || filters.to) && <button type="button" className="text-link" onClick={() => router.get(`${base}/full-report`)}>{translate('portal', 'clear')}</button>}
                </form>
                <div className="metric-grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr))' }}>
                    <div className="metric"><span>{translate('portal', 'report_total_ordered')}</span><strong>{money(summary.ordered)}</strong><small>{translate('portal', 'report_orders_count', { count: String(summary.orders_count ?? 0) })}</small></div>
                    <div className="metric"><span>{translate('portal', 'report_payments_received')}</span><strong>{money(summary.paid)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'report_outstanding_due')}</span><strong>{money(summary.due)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'report_commission_earned')}</span><strong>{money(summary.commission)}</strong></div>
                </div>
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'report_commission_by_head')}</h2>
                        <p>{translate('portal', 'report_commission_by_head_hint')}</p>
                    </div>
                </div>
                <Table
                    rows={commissions}
                    columns={[
                        { key: 'label', title: translate('portal', 'report_col_head') },
                        { key: 'entries', title: translate('portal', 'report_col_entries') },
                        { key: 'amount', title: translate('portal', 'report_col_amount'), render: (r) => money(r.amount) },
                    ]}
                    empty={translate('portal', 'report_no_commission')}
                    emptyBody={translate('portal', 'report_no_commission_body')}
                />
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'report_order_payment_records')}</h2>
                        <p>{translate('portal', 'report_order_payment_records_hint')}</p>
                    </div>
                </div>
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{translate('portal', 'report_col_order')}</th>
                                <th>{translate('portal', 'enquiry_status')}</th>
                                <th>{translate('portal', 'report_col_ordered')}</th>
                                <th>{translate('portal', 'report_col_paid')}</th>
                                <th>{translate('portal', 'report_col_due')}</th>
                                <th>{translate('portal', 'report_col_payments')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.length === 0 && (
                                <tr><td colSpan={6} className="muted">{translate('portal', 'report_no_orders')}</td></tr>
                            )}
                            {orders.map((order) => (
                                <tr key={order.id}>
                                    <td>
                                        <b>{order.number}</b>
                                        <small className="cell-sub">{date(order.created_at)}</small>
                                    </td>
                                    <td><Badge status={order.status} /></td>
                                    <td>{money(order.total)}</td>
                                    <td>{money(order.paid)}</td>
                                    <td>{money(order.due)}</td>
                                    <td>
                                        {(order.payments ?? []).length === 0 && <span className="muted">—</span>}
                                        {(order.payments ?? []).map((payment: Row, index: number) => (
                                            <div key={`${order.id}-${index}`} style={{ fontSize: 11, marginBottom: 4 }}>
                                                <b>{payment.channel}</b> {money(payment.amount)} · {payment.status}
                                                {payment.reference ? <small className="cell-sub">{payment.reference}</small> : null}
                                            </div>
                                        ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'report_team_members')}</h2>
                        <p>{translate('portal', 'report_team_members_hint', { count: String(summary.team_members ?? 0), amount: money(summary.team_commission) })}</p>
                    </div>
                </div>
                <Table
                    rows={teamMembers}
                    columns={[
                        { key: 'name', title: translate('portal', 'report_col_member'), render: (r) => <><b>{r.name}</b><small className="cell-sub">{r.dealer_code}</small></> },
                        { key: 'role', title: translate('portal', 'partnership_type') },
                        { key: 'phone', title: translate('portal', 'label_mobile') },
                        { key: 'status', title: translate('portal', 'enquiry_status'), render: (r) => <Badge status={r.status} /> },
                    ]}
                    empty={translate('portal', 'report_no_team')}
                />
            </section>

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'report_team_transactions')}</h2>
                        <p>{translate('portal', 'report_team_transactions_hint')}</p>
                    </div>
                </div>
                <Table
                    rows={teamActivity}
                    columns={[
                        { key: 'created_at', title: translate('portal', 'report_col_when'), render: (r) => date(r.created_at) },
                        { key: 'member_name', title: translate('portal', 'report_col_member'), render: (r) => <><b>{r.member_name}</b><small className="cell-sub">{r.member_code}</small></> },
                        { key: 'number', title: translate('portal', 'report_col_order') },
                        { key: 'status', title: translate('portal', 'enquiry_status'), render: (r) => <Badge status={r.status} /> },
                        { key: 'total', title: translate('portal', 'report_col_ordered'), render: (r) => money(r.total) },
                        { key: 'paid', title: translate('portal', 'report_col_paid'), render: (r) => money(r.paid) },
                        { key: 'due', title: translate('portal', 'report_col_due'), render: (r) => money(r.due) },
                    ]}
                    empty={translate('portal', 'report_no_team_orders')}
                />
            </section>
        </div>
    );
}
