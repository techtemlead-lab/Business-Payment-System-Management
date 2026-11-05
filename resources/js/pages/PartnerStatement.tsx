import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Row, Table, Pagination, money, dateTime, DateInput } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Statement = {
    opening_balance: string;
    closing_balance: string;
    total_in: string;
    total_out: string;
    line_count: number;
    lines: Row;
};

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
 * The party ledger (পার্টির হিসাব): opening balance, every money movement in
 * the chosen dates with a running balance, totals for the whole range and
 * the closing balance. Excel and PDF downloads use the same dates.
 */
export default function PartnerStatement({ partner, range, statement, staffView, statementBase }: { partner: Row; range: { from: string; to: string }; statement: Statement; staffView: boolean; statementBase: string }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);
    useEffect(() => { setFrom(range.from); setTo(range.to); }, [range.from, range.to]);
    const go = (next: { from: string; to: string }) => router.get(`${base}${statementBase}`, next, { preserveScroll: true });
    const query = new URLSearchParams({ from: range.from, to: range.to }).toString();
    const backHref = staffView ? `${base}/${partner.role === 'sub_dealer' ? 'sub-dealers' : 'accounts'}?q=${encodeURIComponent(String(partner.dealer_code || partner.name || ''))}` : `${base}/earnings`;
    const tile = (title: string, value: string, hint?: string) => <div className="metric"><span>{translate('portal', title)}</span><strong>{money(value)}</strong>{hint && <small>{hint}</small>}</div>;

    return <Shell section={staffView ? (partner.role === 'sub_dealer' ? 'sub-dealers' : 'accounts') : 'earnings'}>
        <Head title={translate('portal', 'stmt_title')} />
        <Link className="text-link" href={backHref}>{translate('portal', staffView ? 'stmt_back_accounts' : 'stmt_back_earnings')}</Link>
        <section className="panel table-panel stmt-panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('portal', 'stmt_title')}</h2>
                    <p className="muted">{partner.name}{partner.dealer_code ? ` · ${partner.dealer_code}` : ''}{partner.district ? ` · ${partner.district}` : ''}</p>
                </div>
                <div className="inline-actions">
                    <a className="button small" href={`${base}${statementBase}/xlsx?${query}`}><Download size={14} /> {translate('portal', 'stmt_download_excel')}</a>
                    <a className="button small" href={`${base}${statementBase}/pdf?${query}`}><Download size={14} /> {translate('portal', 'stmt_download_pdf')}</a>
                </div>
            </div>
            <form className="filter-bar order-filters" onSubmit={event => { event.preventDefault(); if (from && to) go({ from, to }); }}>
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
                <button className="button small dark" type="submit">{translate('portal', 'stmt_show')}</button>
            </form>
            <div className="metric-grid four">
                {tile('opening_balance', statement.opening_balance, range.from)}
                {tile('stmt_col_in', statement.total_in)}
                {tile('stmt_col_out', statement.total_out)}
                {tile('closing_balance', statement.closing_balance, range.to)}
            </div>
            <Table
                searchable={false}
                tools={false}
                totalCount={statement.line_count}
                rows={statement.lines.data ?? []}
                empty={translate('portal', 'stmt_empty')}
                columns={[
                    { key: 'occurred_at', title: translate('portal', 'col_date'), render: (r: Row) => dateTime(r.occurred_at) },
                    { key: 'description', title: translate('portal', 'stmt_col_what') },
                    { key: 'reference', title: translate('portal', 'stmt_col_reference'), render: (r: Row) => r.order_id && r.reference ? <Link className="text-link" href={`${base}/orders/${r.order_id}`}>{r.reference}</Link> : r.withdrawal_id ? <Link className="text-link" href={`${base}/withdrawals/${r.withdrawal_id}`}>{r.reference}</Link> : (r.reference || '—') },
                    { key: 'money_in', title: translate('portal', 'stmt_col_in'), align: 'right', render: (r: Row) => Number(r.money_in) > 0 ? money(r.money_in) : '' },
                    { key: 'money_out', title: translate('portal', 'stmt_col_out'), align: 'right', render: (r: Row) => Number(r.money_out) > 0 ? money(r.money_out) : '' },
                    { key: 'balance', title: translate('portal', 'stmt_col_balance'), align: 'right', render: (r: Row) => <b>{money(r.balance)}</b> },
                ]}
            />
            <Pagination page={statement.lines} />
            <p className="muted stmt-note">{translate('portal', 'stmt_note')}</p>
        </section>
    </Shell>;
}
