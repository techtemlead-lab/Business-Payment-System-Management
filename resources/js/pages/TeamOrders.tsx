import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Row, Table, Pagination, money, dateTime, DateInput } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Filters = { from: string; to: string; member: number | null; status: string | null };
type Totals = { orders: number; buyers: number; ordered: string; paid: string; due: string };
type Member = { id: number; name: string; dealer_code: string | null };

/** Dhaka calendar date as YYYY-MM-DD. */
function dhakaDay(value: Date): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(value);
}

/** Quick date ranges in Dhaka days. */
function presetRange(preset: string): { from: string; to: string } {
    const today = dhakaDay(new Date());
    const [year, month] = today.split('-').map(Number);
    if (preset === 'last_month') {
        const first = new Date(Date.UTC(year, month - 2, 1));
        const last = new Date(Date.UTC(year, month - 1, 0));
        return { from: first.toISOString().slice(0, 10), to: last.toISOString().slice(0, 10) };
    }
    if (preset === 'year') {
        return { from: `${year}-01-01`, to: today };
    }
    return { from: today.slice(0, 8) + '01', to: today };
}

/**
 * Team orders (টিমের অর্ডার): every confirmed order by the people under a partner
 * in the chosen dates, with member and status filters, totals for the whole range
 * and an Excel download of the same list.
 */
export default function TeamOrders({ partner, filters, statuses, orders, totals, members, staffView, reportBase }: { partner: Row; filters: Filters; statuses: string[]; orders: Row; totals: Totals; members: Member[]; staffView: boolean; reportBase: string }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [member, setMember] = useState(filters.member ? String(filters.member) : '');
    const [status, setStatus] = useState(filters.status ?? '');
    useEffect(() => {
        setFrom(filters.from);
        setTo(filters.to);
        setMember(filters.member ? String(filters.member) : '');
        setStatus(filters.status ?? '');
    }, [filters.from, filters.to, filters.member, filters.status]);
    const params = (next: Partial<{ from: string; to: string; member: string; status: string }>) => {
        const merged = { from, to, member, status, ...next };
        return Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== '' && value !== null && value !== undefined)) as Record<string, string>;
    };
    const go = (next: Partial<{ from: string; to: string; member: string; status: string }> = {}) => router.get(`${base}${reportBase}`, params(next), { preserveScroll: true });
    const query = new URLSearchParams(params({ from: filters.from, to: filters.to, member: filters.member ? String(filters.member) : '', status: filters.status ?? '' })).toString();
    const backHref = staffView ? `${base}/${partner.role === 'sub_dealer' ? 'sub-dealers' : 'accounts'}` : `${base}/my-money`;
    const tile = (title: string, value: string, hint?: string) => <div className="metric"><span>{translate('portal', title)}</span><strong>{value}</strong>{hint && <small>{hint}</small>}</div>;

    return <Shell section={staffView ? (partner.role === 'sub_dealer' ? 'sub-dealers' : 'accounts') : 'team-orders'}>
        <Head title={translate('portal', 'team_orders_title')} />
        <Link className="text-link" href={backHref}>{translate('portal', staffView ? 'team_orders_back_statement' : 'team_orders_back_report')}</Link>
        <section className="panel table-panel stmt-panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('portal', 'team_orders_title')}</h2>
                    <p className="muted">{partner.name}{partner.dealer_code ? ` · ${partner.dealer_code}` : ''}{partner.district ? ` · ${partner.district}` : ''}</p>
                    <p className="muted">{translate('portal', 'team_orders_hint')}</p>
                </div>
                <div className="inline-actions">
                    <a className="button small" href={`${base}${reportBase}/xlsx?${query}`}><Download size={14} /> {translate('portal', 'stmt_download_excel')}</a>
                </div>
            </div>
            <form className="filter-bar order-filters" onSubmit={event => { event.preventDefault(); if (from && to) go(); }}>
                <select className="order-period-select" aria-label={translate('portal', 'stmt_quick_range')} value="" onChange={event => { if (event.target.value) go(presetRange(event.target.value)); }}>
                    <option value="">{translate('portal', 'stmt_quick_range')}</option>
                    <option value="month">{translate('portal', 'orders_period_month')}</option>
                    <option value="last_month">{translate('portal', 'orders_period_last_month')}</option>
                    <option value="year">{translate('portal', 'orders_period_year')}</option>
                </select>
                <div className="order-dates">
                    <DateInput aria-label={translate('portal', 'stmt_from')} value={from} onChange={event => setFrom(event.target.value)} />
                    <DateInput aria-label={translate('portal', 'stmt_to')} value={to} onChange={event => setTo(event.target.value)} />
                </div>
                <select aria-label={translate('portal', 'team_orders_member')} value={member} onChange={event => { setMember(event.target.value); go({ member: event.target.value }); }}>
                    <option value="">{translate('portal', 'team_orders_all_members')}</option>
                    {members.map(item => <option key={item.id} value={item.id}>{item.name}{item.dealer_code ? ` (${item.dealer_code})` : ''}</option>)}
                </select>
                <select aria-label={translate('portal', 'col_status')} value={status} onChange={event => { setStatus(event.target.value); go({ status: event.target.value }); }}>
                    <option value="">{translate('portal', 'team_orders_all_statuses')}</option>
                    {statuses.map(item => <option key={item} value={item}>{translate('portal', `status_${item}`)}</option>)}
                </select>
                <button className="button small dark" type="submit">{translate('portal', 'stmt_show')}</button>
            </form>
            <div className="metric-grid four">
                {tile('team_orders_total_orders', String(totals.orders), translate('portal', 'team_orders_buyers_count', { count: String(totals.buyers) }))}
                {tile('report_col_ordered', money(totals.ordered))}
                {tile('report_col_paid', money(totals.paid))}
                {tile('report_col_due', money(totals.due))}
            </div>
            <p className="muted">{translate('portal', 'team_orders_counted_note')}</p>
            <Table
                searchable={false}
                tools={false}
                totalCount={orders.total}
                rows={orders.data ?? []}
                empty={translate('portal', 'team_orders_empty')}
                columns={[
                    { key: 'number', title: translate('portal', 'report_col_order'), render: (r: Row) => <>{staffView ? <Link className="text-link" href={`${base}/orders/${r.id}`}>{r.number}</Link> : <b>{r.number}</b>}<small className="cell-sub">{dateTime(r.created_at)}</small></> },
                    { key: 'member_name', title: translate('portal', 'report_col_member'), render: (r: Row) => <><b>{r.member_name}</b><small className="cell-sub">{r.member_code}</small></> },
                    { key: 'status', title: translate('portal', 'col_status'), render: (r: Row) => <Badge status={r.status} /> },
                    { key: 'total', title: translate('portal', 'report_col_ordered'), align: 'right', render: (r: Row) => money(r.total) },
                    { key: 'paid', title: translate('portal', 'report_col_paid'), align: 'right', render: (r: Row) => money(r.paid) },
                    { key: 'due', title: translate('portal', 'report_col_due'), align: 'right', render: (r: Row) => money(r.due) },
                ]}
            />
            <Pagination page={orders} />
        </section>
    </Shell>;
}
