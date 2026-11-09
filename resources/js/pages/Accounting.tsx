import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Pagination, Row, Table, dateTime, money } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Translate = (key: string, replace?: Record<string, string | number>) => string;

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

export default function Accounting(props: Row) {
    const translate = useT();
    const t: Translate = (key, replace = {}) => translate('accounting', key, replace);

    return <Shell section="treasury"><Head title={t('tab_import')} />
        <div className="section-header"><div><Link className="text-link" href="/treasury/work">{translate('treasury', 'tab_work')}</Link><h2>{t('tab_import')}</h2></div></div>
        <StatementCheck {...props} t={t} />
    </Shell>;
}
