import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Pagination, Row, Table, dateTime, money } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Translate = (key: string, replace?: Record<string, string | number>) => string;

const TABS = ['statements', 'import', 'payouts', 'limit'];

function Notice({ t }: { t: Translate }) {
    return <div className="notice warning" role="note"><p><strong>{t('management_notice')}</strong></p><p>{t('journal_scope_notice')}</p></div>;
}

function Checks({ checks, t }: { checks: Row; t: Translate }) {
    const good = checks.ties_to_journal && checks.journal_balanced && checks.statement_balances;
    return <section className="panel">
        <h2>{t('check_title')}</h2>
        <div className="metric-grid two">
            <div className="metric"><span>{t('check_journal_debit')}</span><strong>{money(checks.journal_debit)}</strong></div>
            <div className="metric"><span>{t('check_journal_credit')}</span><strong>{money(checks.journal_credit)}</strong></div>
        </div>
        <p className={good ? 'notice' : 'error'} role="status">{checks.ties_to_journal ? t('check_ties') : t('check_not_ties')} {checks.journal_balanced && checks.statement_balances ? t('check_balanced') : t('check_not_balanced')}</p>
        {checks.unclassified_accounts.length > 0 && <p className="notice warning">{t('check_unclassified', { accounts: checks.unclassified_accounts.join(', ') })}</p>}
    </section>;
}

function Downloads({ type, filters, exportBase, t }: { type: string; filters: Row; exportBase: string; t: Translate }) {
    const href = (format: string) => `${exportBase}?${new URLSearchParams({ type, format, from: filters.from, to: filters.to })}`;
    return <div className="button-row">
        <a className="button small" href={href('xlsx')}><Download size={14} aria-hidden="true" /> {t('download_excel')}</a>
        <a className="button small" href={href('pdf')}><Download size={14} aria-hidden="true" /> {t('download_pdf')}</a>
    </div>;
}

function StatementLines({ title, lines, total, totalLabel, t }: { title: string; lines: Row[]; total?: string; totalLabel?: string; t: Translate }) {
    return <div className="ops-mb-4">
        <h3>{title}</h3>
        <table className="data-table"><tbody>
            {lines.length === 0 && <tr><td className="muted" colSpan={2}>{t('no_lines')}</td></tr>}
            {lines.map(line => <tr key={line.account}><td>{line.label}</td><td className="num">{money(line.amount)}</td></tr>)}
            {totalLabel && <tr><th>{totalLabel}</th><th className="num">{money(total)}</th></tr>}
        </tbody></table>
    </div>;
}

function Statements({ statements, filters, exportBase, t }: Row & { t: Translate }) {
    const trial = statements.trial_balance;
    const pl = statements.profit_loss;
    const sheet = statements.balance_sheet;
    return <>
        <section className="panel">
            <form className="filter-bar" onSubmit={event => {
                event.preventDefault();
                const fields = new FormData(event.currentTarget);
                router.get('/operations/accounting', { from: String(fields.get('from')), to: String(fields.get('to')) }, { preserveState: true });
            }}>
                <label className="muted ptl-date-field">{t('period_from')}<input type="date" name="from" defaultValue={filters.from} required /></label>
                <label className="muted ptl-date-field">{t('period_to')}<input type="date" name="to" defaultValue={filters.to} required /></label>
                <button className="button small" type="submit">{t('show')}</button>
            </form>
        </section>
        <Notice t={t} />
        <Checks checks={statements.checks} t={t} />
        <section className="panel">
            <div className="panel-heading"><div><h2>{t('statement_trial_balance')}</h2><p className="muted">{t('balance_sign_hint')}</p></div><Downloads type="trial-balance" filters={filters} exportBase={exportBase} t={t} /></div>
            <Table searchable={false} rows={trial.rows} columns={[
                { key: 'label', title: t('column_account') },
                { key: 'opening', title: t('column_opening'), money: true, render: (row: Row) => money(row.opening) },
                { key: 'debit', title: t('column_debit'), money: true, total: true, render: (row: Row) => money(row.debit) },
                { key: 'credit', title: t('column_credit'), money: true, total: true, render: (row: Row) => money(row.credit) },
                { key: 'closing', title: t('column_closing'), money: true, render: (row: Row) => money(row.closing) },
            ]} empty={t('no_lines')} />
        </section>
        <section className="panel">
            <div className="panel-heading"><h2>{t('statement_profit_loss')}</h2><Downloads type="profit-loss" filters={filters} exportBase={exportBase} t={t} /></div>
            <StatementLines title={t('section_income')} lines={pl.income} total={pl.total_income} totalLabel={t('total_income')} t={t} />
            <StatementLines title={t('section_expenses')} lines={pl.expenses} total={pl.total_expenses} totalLabel={t('total_expenses')} t={t} />
            <div className="metric"><span>{t('result')}</span><strong>{money(pl.result)}</strong></div>
        </section>
        <section className="panel">
            <div className="panel-heading"><h2>{t('statement_balance_sheet')}</h2><Downloads type="balance-sheet" filters={filters} exportBase={exportBase} t={t} /></div>
            <StatementLines title={t('section_assets')} lines={[...sheet.assets, ...sheet.unclassified]} total={sheet.total_assets} totalLabel={t('total_assets')} t={t} />
            <StatementLines title={t('section_liabilities')} lines={sheet.liabilities} total={sheet.total_liabilities} totalLabel={t('total_liabilities')} t={t} />
            <StatementLines title={t('section_equity')} lines={[...sheet.equity, { account: 'earlier', label: t('earlier_result'), amount: sheet.earlier_result }, { account: 'period', label: t('period_result'), amount: sheet.period_result }]} total={sheet.total_liabilities_and_equity} totalLabel={t('total_liabilities_and_equity')} t={t} />
        </section>
    </>;
}

function PayoutFile({ payoutBatch, filters, channels, t }: Row & { t: Translate }) {
    const query = new URLSearchParams({ month: filters.month, ...(filters.channel ? { channel: filters.channel } : {}) });
    return <section className="panel">
        <h2>{t('batch_title')}</h2>
        <p className="muted">{t('batch_hint')}</p>
        <form className="filter-bar" onSubmit={event => {
            event.preventDefault();
            const fields = new FormData(event.currentTarget);
            router.get('/operations/accounting', { tab: 'payouts', month: String(fields.get('month')), channel: String(fields.get('channel')) || undefined }, { preserveState: true });
        }}>
            <label className="muted ptl-date-field">{t('batch_month')}<input type="month" name="month" defaultValue={filters.month} required /></label>
            <label className="muted ptl-date-field">{t('batch_channel')}<select name="channel" defaultValue={filters.channel ?? ''}>
                <option value="">{t('batch_all_channels')}</option>
                {channels.map((channel: string) => <option key={channel} value={channel}>{channel}</option>)}
            </select></label>
            <button className="button small" type="submit">{t('show')}</button>
        </form>
        <div className="metric-grid two ops-mb-4">
            <div className="metric"><span>{t('batch_count')}</span><strong>{payoutBatch.count}</strong></div>
            <div className="metric"><span>{t('batch_total_net')}</span><strong>{money(payoutBatch.total_net)}</strong></div>
        </div>
        <div className="notice"><p>{t('batch_manual_note')}</p><Link className="button small" href="/payouts-hub">{t('batch_open_payouts')}</Link></div>
        {payoutBatch.count > 0 && <p><a className="button dark" href={`/commands/operations/accounting/payout-batch/export?${query}`}><Download size={16} aria-hidden="true" /> {t('batch_download')}</a></p>}
        <Table searchable={false} rows={payoutBatch.rows} columns={[
            { key: 'dealer_code', title: t('column_dealer_code') },
            { key: 'name', title: t('column_name') },
            { key: 'channel', title: t('column_channel') },
            { key: 'destination_masked', title: t('column_destination') },
            { key: 'amount', title: t('column_requested'), money: true, total: true, render: (row: Row) => money(row.amount) },
            { key: 'withheld_amount', title: t('column_tax_withheld'), money: true, total: true, render: (row: Row) => money(row.withheld_amount) },
            { key: 'net_amount', title: t('column_net_to_pay'), money: true, total: true, render: (row: Row) => <b>{money(row.net_amount)}</b> },
            { key: 'requested_on', title: t('column_requested_on') },
        ]} empty={t('batch_empty')} />
    </section>;
}

const columnLetter = (index: number) => index < 26 ? String.fromCharCode(65 + index) : `A${String.fromCharCode(65 + index - 26)}`;

function ColumnMapping({ statement, t }: { statement: Row; t: Translate }) {
    const guess = statement.guess;
    const form = useForm<{ first_data_row: number; reference_column: string; amount_column: string; date_column: string; description_column: string }>({
        first_data_row: guess.first_data_row,
        reference_column: guess.reference_column === null ? '' : String(guess.reference_column),
        amount_column: guess.amount_column === null ? '' : String(guess.amount_column),
        date_column: guess.date_column === null ? '' : String(guess.date_column),
        description_column: guess.description_column === null ? '' : String(guess.description_column),
    });
    const columns = Array.from({ length: statement.column_count }, (_, index) => index);
    const select = (name: 'reference_column' | 'amount_column' | 'date_column' | 'description_column', label: string, required: boolean) => <label>{label}<select required={required} value={form.data[name]} onChange={event => form.setData(name, event.target.value)}>
        <option value="">{required ? t('map_choose') : t('map_none')}</option>
        {columns.map(index => <option key={index} value={index}>{t('map_column', { letter: columnLetter(index) })}</option>)}
    </select></label>;
    return <section className="panel">
        <h2>{t('map_title')}</h2>
        <p className="muted">{t('map_hint')}</p>
        <div className="table-wrap"><table className="data-table">
            <thead><tr><th>{t('map_row')}</th>{columns.map(index => <th key={index}>{columnLetter(index)}</th>)}</tr></thead>
            <tbody>{statement.preview.map((row: Row) => <tr key={row.row}><td>{row.row}</td>{columns.map(index => <td key={index}>{row.cells[index] ?? ''}</td>)}</tr>)}</tbody>
        </table></div>
        <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.post(`/commands/operations/accounting/statements/${statement.id}/map`, { preserveScroll: true });
        }}>
            <label>{t('map_first_data_row')}<input required type="number" min={1} value={form.data.first_data_row} onChange={event => form.setData('first_data_row', Number(event.target.value))} /></label>
            {select('reference_column', t('map_reference_column'), true)}
            {select('amount_column', t('map_amount_column'), true)}
            {select('date_column', t('map_date_column'), false)}
            {select('description_column', t('map_description_column'), false)}
            {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
            <button className="button dark" disabled={form.processing}>{t('map_save')}</button>
        </form>
    </section>;
}

function LineDecision({ line, t }: { line: Row; t: Translate }) {
    const form = useForm({ reason: '' });
    return <div className="action-form">
        <input aria-label={t('reason_placeholder')} placeholder={t('reason_placeholder')} maxLength={1000} required={line.flags.length > 0} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
        {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
        <div className="button-row">
            <button type="button" className="button small dark" disabled={form.processing} onClick={() => form.post(`/commands/operations/accounting/statement-lines/${line.id}/confirm`, { preserveScroll: true })}>{t('confirm')}</button>
            <button type="button" className="button small" disabled={form.processing} onClick={() => form.post(`/commands/operations/accounting/statement-lines/${line.id}/dismiss`, { preserveScroll: true })}>{t('dismiss')}</button>
        </div>
    </div>;
}

function ImportLines({ statement, t }: { statement: Row; t: Translate }) {
    const summary = statement.summary;
    return <section className="panel">
        <h2>{t('lines_title')}</h2>
        <p><strong>{statement.filename}</strong> · {statement.channel} · {statement.source_reference}</p>
        <div className="metric-grid three ops-mb-4">
            {['total', 'suggested', 'amount_differs', 'no_match', 'ignored', 'flagged'].map(key => <div className="metric" key={key}><span>{t(`summary_${key}`)}</span><strong>{summary[key]}</strong></div>)}
        </div>
        <p className="notice" role="note">{t('lines_never_approves')}</p>
        <Table searchable={false} rows={statement.lines.data} columns={[
            { key: 'row_number', title: t('col_row') },
            { key: 'txn_date', title: t('col_date'), render: (row: Row) => row.txn_date ?? '—' },
            { key: 'reference', title: t('col_reference'), render: (row: Row) => <>{row.reference ?? '—'}{row.flags.length > 0 && <small className="field-error" role="alert"><b>{t('flag_title')}</b> {row.flags.map((flag: string) => t(`flag_${flag}`)).join('; ')}</small>}{row.description && <small className="cell-sub">{row.description}</small>}</> },
            { key: 'amount', title: t('col_amount'), money: true, render: (row: Row) => row.amount === null ? '—' : money(row.amount) },
            { key: 'claim', title: t('col_claim'), render: (row: Row) => row.order_number ? <>{row.order_number} · {money(row.claim_amount)}<small className="cell-sub">{[row.partner_name, row.dealer_code].filter(Boolean).join(' · ')}</small><Badge status={row.claim_status} /></> : '—' },
            { key: 'state', title: t('col_state'), render: (row: Row) => t(`state_${row.state}`) },
            { key: 'action', title: t('col_action'), render: (row: Row) => <>{row.state === 'suggested' && <LineDecision line={row} t={t} />}{row.reference && row.order_number && <Link className="text-link" href={`/payments?q=${encodeURIComponent(row.reference)}`}>{t('lines_open_payments')}</Link>}</> },
        ]} empty={t('no_lines')} />
        <Pagination page={statement.lines} />
    </section>;
}

function StatementCheck({ channels, history, statement, t }: Row & { t: Translate }) {
    const upload = useForm<{ channel: string; source_reference: string; file: File | null }>({ channel: channels[0], source_reference: '', file: null });
    return <>
        <section className="panel">
            <h2>{t('import_title')}</h2>
            <p className="muted">{t('import_hint')}</p>
            <form className="action-form" onSubmit={event => {
                event.preventDefault();
                upload.post('/commands/operations/accounting/statements', { forceFormData: true, preserveScroll: true });
            }}>
                <label>{t('import_channel')}<select value={upload.data.channel} onChange={event => upload.setData('channel', event.target.value)}>
                    {channels.map((channel: string) => <option key={channel} value={channel}>{channel}</option>)}
                </select></label>
                <label>{t('import_source_reference')}<input required minLength={3} maxLength={150} value={upload.data.source_reference} onChange={event => upload.setData('source_reference', event.target.value)} /></label>
                <label>{t('import_file')}<input required type="file" accept=".csv,.xlsx" onChange={event => upload.setData('file', event.target.files?.[0] ?? null)} /></label>
                {upload.progress && <progress max={100} value={upload.progress.percentage} aria-label={t('import_file')} />}
                {Object.keys(upload.errors).length > 0 && <p role="alert" className="error">{Object.values(upload.errors).join(' ')}</p>}
                <button className="button dark" disabled={upload.processing}>{t('import_upload')}</button>
            </form>
        </section>
        {statement?.status === 'awaiting_mapping' && <ColumnMapping statement={statement} t={t} />}
        {statement?.status === 'ready' && <ImportLines statement={statement} t={t} />}
        <section className="panel">
            <h2>{t('import_history')}</h2>
            <Table searchable={false} rows={history.data} columns={[
                { key: 'filename', title: t('import_col_file') },
                { key: 'channel', title: t('column_channel') },
                { key: 'status', title: t('import_col_status'), render: (row: Row) => t(`import_status_${row.status}`) },
                { key: 'created_at', title: t('import_col_uploaded'), render: (row: Row) => dateTime(row.created_at) },
                { key: 'open', title: '', render: (row: Row) => <Link className="button small" href={`/operations/accounting?tab=import&statement=${row.id}`} preserveScroll>{t('import_view')}</Link> },
            ]} empty={t('no_lines')} />
            <Pagination page={history} />
        </section>
    </>;
}

function ApprovalLimit({ approvalLimit, t }: Row & { t: Translate }) {
    const form = useForm({ amount: approvalLimit.amount, version: approvalLimit.version, reason: '' });
    return <section className="panel">
        <h2>{t('limit_title')}</h2>
        <p className="muted">{t('limit_hint')}</p>
        <div className="metric"><span>{t('limit_current')}</span><strong>{money(approvalLimit.amount)}</strong><small>{t('limit_default', { amount: money(approvalLimit.default) })}</small></div>
        {approvalLimit.can_edit ? <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.post('/commands/operations/accounting/approval-limit', { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
        }}>
            <label>{t('limit_new_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} /></label>
            <label>{t('limit_reason')}<textarea required minLength={5} maxLength={1000} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
            <button className="button dark" disabled={form.processing}>{t('limit_save')}</button>
        </form> : <p className="notice">{t('limit_owner_only')}</p>}
    </section>;
}

export default function Accounting(props: Row) {
    const translate = useT();
    const t: Translate = (key, replace = {}) => translate('accounting', key, replace);
    const { tab } = props;

    return <Shell section="accounting"><Head title={t('title')} />
        <div className="section-header"><div><h2>{t('title')}</h2><p className="muted">{t('intro')}</p></div></div>
        <nav className="preset-chips" aria-label={t('title')}>
            {TABS.map(name => <Link key={name} className={`button small ${tab === name ? 'dark' : ''}`} href={`/operations/accounting?tab=${name}`} preserveState>{t(`tab_${name}`)}</Link>)}
        </nav>
        {tab === 'statements' && <Statements {...props} t={t} />}
        {tab === 'import' && <StatementCheck {...props} t={t} />}
        {tab === 'payouts' && <PayoutFile {...props} t={t} />}
        {tab === 'limit' && <ApprovalLimit {...props} t={t} />}
    </Shell>;
}
