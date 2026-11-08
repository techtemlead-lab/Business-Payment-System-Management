import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Download, FileBarChart2 } from 'lucide-react';
import { Badge, money, date, label, Table, type Row, DateInput } from './UI';
import { ALL_RECORDS_VIEWS, AllRecordsViews, useSubView } from './PartnerHubNav';
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
    const certificateYears: number[] = report?.certificates?.years ?? [new Date().getFullYear()];
    const [certificateYear, setCertificateYear] = useState(String(certificateYears[0]));
    const [view, chooseView] = useSubView(ALL_RECORDS_VIEWS, 'commissions');

    return (
        <div className="partner-full-report">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2 className="ops-row is-inline">
                            <FileBarChart2 size={18} /> {translate('portal', 'full_report')}
                        </h2>
                        <p className="muted">{translate('portal', 'full_report_hint')}</p>
                    </div>
                    <a className="button small dark" href={`${base}/full-report/pdf${querySuffix}`} target="_blank" rel="noopener noreferrer" download="partner-statement.pdf">
                        <Download size={15} /> {translate('portal', 'download_pdf')}
                    </a>
                </div>
                <div className="certificate-links">
                    <label className="muted">
                        {translate('portal', 'cert_year_label')}
                        <select value={certificateYear} onChange={(event) => setCertificateYear(event.target.value)}>
                            {certificateYears.map((year) => <option key={year} value={year}>{year}</option>)}
                        </select>
                    </label>
                    <a className="button small" href={`${base}/income-certificate/pdf?year=${certificateYear}`} target="_blank" rel="noopener noreferrer">
                        <Download size={15} /> {translate('portal', 'download_income_certificate')}
                    </a>
                    {report?.certificates?.rank && (
                        <a className="button small" href={`${base}/rank-certificate/pdf`} target="_blank" rel="noopener noreferrer">
                            <Download size={15} /> {translate('portal', 'download_rank_certificate')}
                        </a>
                    )}
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
                    <label className="muted pfr-date">
                        {translate('portal', 'date_from')}
                        <DateInput value={from} onChange={(event) => setFrom(event.target.value)} />
                    </label>
                    <label className="muted pfr-date">
                        {translate('portal', 'date_to')}
                        <DateInput value={to} onChange={(event) => setTo(event.target.value)} />
                    </label>
                    <button className="button small" type="submit">{translate('portal', 'apply_dates')}</button>
                    {(filters.from || filters.to) && <button type="button" className="text-link" onClick={() => router.get(`${base}/full-report`)}>{translate('portal', 'clear')}</button>}
                </form>
                <div className="metric-grid four">
                    <div className="metric"><span>{translate('portal', 'report_total_ordered')}</span><strong>{money(summary.ordered)}</strong><small>{translate('portal', 'report_orders_count', { count: String(summary.orders_count ?? 0) })}</small></div>
                    <div className="metric"><span>{translate('portal', 'report_payments_received')}</span><strong>{money(summary.paid)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'report_outstanding_due')}</span><strong>{money(summary.due)}</strong></div>
                    <div className="metric"><span>{translate('portal', 'report_commission_earned')}</span><strong>{money(summary.commission)}</strong></div>
                </div>
                <AllRecordsViews active={view} onSelect={chooseView} />
            </section>

            {view === 'commissions' && <section className="panel table-panel dense-panel">
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
            </section>}

            {view === 'orders' && <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'report_order_payment_records')}</h2>
                        <p>{translate('portal', 'report_order_payment_records_hint')}</p>
                    </div>
                </div>
                <Table
                    rows={orders}
                    columns={[
                        {
                            key: 'number',
                            title: translate('portal', 'report_col_order'),
                            sortable: true,
                            sortValue: (r: Row) => r.number || '',
                            render: (order) => (
                                <>
                                    <b>{order.number}</b>
                                    <small className="cell-sub">{date(order.created_at)}</small>
                                </>
                            ),
                        },
                        {
                            key: 'status',
                            title: translate('portal', 'enquiry_status'),
                            sortable: true,
                            sortValue: (r: Row) => r.status || '',
                            render: (order) => <Badge status={order.status} />,
                        },
                        {
                            key: 'total',
                            title: translate('portal', 'report_col_ordered'),
                            sortable: true,
                            align: 'right',
                            sortValue: (r: Row) => Number(r.total) || 0,
                            render: (order) => money(order.total),
                        },
                        {
                            key: 'paid',
                            title: translate('portal', 'report_col_paid'),
                            sortable: true,
                            align: 'right',
                            sortValue: (r: Row) => Number(r.paid) || 0,
                            render: (order) => money(order.paid),
                        },
                        {
                            key: 'due',
                            title: translate('portal', 'report_col_due'),
                            sortable: true,
                            align: 'right',
                            sortValue: (r: Row) => Number(r.due) || 0,
                            render: (order) => money(order.due),
                        },
                        {
                            key: 'payments',
                            title: translate('portal', 'report_col_payments'),
                            render: (order) => (
                                <>
                                    {(order.payments ?? []).length === 0 && <span className="muted">—</span>}
                                    {(order.payments ?? []).map((payment: Row, index: number) => (
                                        <div key={`${order.id}-${index}`} className="ops-fs-2xs ops-mb-1">
                                            <b>{payment.channel}</b> {money(payment.amount)} · {label(String(payment.status ?? ""), translate)}
                                            {payment.reference ? <small className="cell-sub">{payment.reference}</small> : null}
                                        </div>
                                    ))}
                                </>
                            ),
                        },
                    ]}
                    empty={translate('portal', 'report_no_orders')}
                />
            </section>}

            {view === 'team' && <section className="panel table-panel dense-panel">
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
            </section>}

            {view === 'team_orders' && <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'report_team_transactions')}</h2>
                        <p>{translate('portal', 'report_team_transactions_hint')}</p>
                    </div>
                    <Link className="button small" href={`${base}/team-orders${filters.from || filters.to ? `?${new URLSearchParams({ ...(filters.from ? { from: String(filters.from) } : {}), ...(filters.to ? { to: String(filters.to) } : {}) }).toString()}` : ''}`}>
                        {translate('portal', 'team_orders_open')}
                    </Link>
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
            </section>}
        </div>
    );
}
