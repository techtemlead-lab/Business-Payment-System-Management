import React from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import { actionAllowed } from '../components/action-permissions';
import { Badge, Pagination, Row, Table, dateTime, money } from '../components/UI';
import { useT } from '../shared/i18n/t';
import { useReasonFields } from '../shared/reasonFields';

type Translate = (key: string, replace?: Record<string, string | number>) => string;

/** Reading the bank check is open to view-only owners; working on it is not. */
const useCanWorkOnStatements = (): boolean => actionAllowed('/commands/operations/accounting/statements', usePage<any>().props.staffActions as never, 'post');

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
    const translate = useT();
    const reasonOf = useReasonFields();
    if (!useCanWorkOnStatements()) {
        return null;
    }
    // One box serves both buttons: it shows when either step asks for a note, and each step sends it only when its own place is on.
    const note = reasonOf(['payment.statement_confirm', 'payment.statement_dismiss']);
    const noteLabel = note.label(translate('portal', 'note'));
    const decide = (step: 'confirm' | 'dismiss') => {
        const asked = reasonOf(step === 'confirm' ? 'payment.statement_confirm' : 'payment.statement_dismiss').shown;
        form.transform(data => ({ reason: asked ? data.reason : '' }));
        form.post(`/commands/operations/accounting/statement-lines/${line.id}/${step}`, { preserveScroll: true });
    };
    return <div className="action-form">
        {note.shown && <input aria-label={noteLabel} placeholder={noteLabel} maxLength={1000} required={note.required} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />}
        {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
        <div className="button-row">
            <button type="button" className="button small dark" disabled={form.processing} onClick={() => decide('confirm')}>{t('confirm')}</button>
            <button type="button" className="button small" disabled={form.processing} onClick={() => decide('dismiss')}>{t('dismiss')}</button>
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
    const translate = useT();
    const upload = useForm<{ channel: string; source_reference: string; file: File | null }>({ channel: channels[0], source_reference: '', file: null });
    const canWork = useCanWorkOnStatements();
    const channelLabel = (channel: string) => {
        const key = `pay_channel_${channel.toLowerCase()}`;
        const text = translate('portal', key);

        return text === key ? channel : text;
    };
    return <>
        {canWork && <section className="panel">
            <h2>{t('import_title')}</h2>
            <p className="muted">{t('import_hint')}</p>
            <form className="action-form" onSubmit={event => {
                event.preventDefault();
                upload.post('/commands/operations/accounting/statements', { forceFormData: true, preserveScroll: true });
            }}>
                <label>{t('import_channel')}<select value={upload.data.channel} onChange={event => upload.setData('channel', event.target.value)}>
                    {channels.map((channel: string) => <option key={channel} value={channel}>{channelLabel(channel)}</option>)}
                </select></label>
                <label>{t('import_source_reference')}<input required minLength={3} maxLength={150} value={upload.data.source_reference} onChange={event => upload.setData('source_reference', event.target.value)} /></label>
                <label className="file-picker">{t('import_file')}
                    <input required type="file" accept=".csv,.xlsx" onChange={event => upload.setData('file', event.target.files?.[0] ?? null)} />
                    <span className="file-picker-face">{upload.data.file?.name ?? t('import_file_empty')}</span>
                </label>
                {upload.progress && <progress max={100} value={upload.progress.percentage} aria-label={t('import_file')} />}
                {Object.keys(upload.errors).length > 0 && <p role="alert" className="error">{Object.values(upload.errors).join(' ')}</p>}
                <button className="button dark" disabled={upload.processing}>{t('import_upload')}</button>
            </form>
        </section>}
        {canWork && statement?.status === 'awaiting_mapping' && <ColumnMapping statement={statement} t={t} />}
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

const newRequestKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now().toString(16).padStart(8, '0').slice(-8)}-0000-4000-8000-${Math.random().toString(16).slice(2, 14).padEnd(12, '0')}`);

function RemittanceDecision({ row, t }: { row: Row; t: Translate }) {
    const form = useForm({ decision: '', reason: '' });
    const send = (path: string, decision: string) => {
        form.transform(data => ({ ...data, decision }));
        form.post(path, { preserveScroll: true });
    };
    return <div className="action-form">
        <input aria-label={t('payable_reason')} placeholder={t('payable_reason')} required minLength={5} maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
        {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
        <div className="button-row">
            <button type="button" className="button small dark" disabled={form.processing} onClick={() => send(`/commands/operations/accounting/payables/${row.id}/review`, 'approved')}>{t('payable_approve')}</button>
            <button type="button" className="button small" disabled={form.processing} onClick={() => send(`/commands/operations/accounting/payables/${row.id}/review`, 'rejected')}>{t('payable_reject')}</button>
            {row.is_mine && <button type="button" className="button small" disabled={form.processing} onClick={() => send(`/commands/operations/accounting/payables/${row.id}/cancel`, '')}>{t('payable_cancel')}</button>}
        </div>
    </div>;
}

function Payables({ payables, t }: { payables: Row; t: Translate }) {
    const canWork = useCanWorkOnStatements();
    const form = useForm({ kind: payables.kinds[0]?.kind ?? 'withheld_tax', amount: '', paid_on: new Date().toISOString().slice(0, 10), reference: '', note: '', request_key: newRequestKey() });
    return <section className="panel">
        <h2>{t('payables_title')}</h2>
        <p className="muted">{t('payables_hint')}</p>
        <div className="metric-grid three ops-mb-4">
            {payables.kinds.map((kind: Row) => <div className="metric" key={kind.kind}>
                <span>{t(`payable_kind_${kind.kind}`)}</span>
                <strong>{money(kind.balance)}</strong>
                <small>{t('payable_pending', { amount: money(kind.pending) })}</small>
                {kind.oldest_unpaid_days !== null && <small className={kind.oldest_unpaid_days > payables.stale_days ? 'field-error' : undefined}>{t('payable_oldest', { days: kind.oldest_unpaid_days })}</small>}
            </div>)}
        </div>
        {canWork && <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.post('/commands/operations/accounting/payables', { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, amount: '', reference: '', note: '', request_key: newRequestKey() }) });
        }}>
            <label>{t('payable_kind')}<select value={form.data.kind} onChange={event => form.setData('kind', event.target.value)}>
                {payables.kinds.map((kind: Row) => <option key={kind.kind} value={kind.kind}>{t(`payable_kind_${kind.kind}`)} ({money(kind.available)})</option>)}
            </select></label>
            <label>{t('payable_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} /></label>
            <label>{t('payable_paid_on')}<input required type="date" value={form.data.paid_on} onChange={event => form.setData('paid_on', event.target.value)} /></label>
            <label>{t('payable_reference')}<input maxLength={100} value={form.data.reference} onChange={event => form.setData('reference', event.target.value)} /></label>
            <label>{t('payable_note')}<input required minLength={5} maxLength={1000} value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></label>
            {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
            <button className="button dark" disabled={form.processing}>{t('payable_record')}</button>
        </form>}
        <h3>{t('payables_waiting')}</h3>
        <Table searchable={false} rows={payables.waiting} columns={[
            { key: 'kind', title: t('payable_kind'), render: (row: Row) => t(`payable_kind_${row.kind}`) },
            { key: 'amount', title: t('payable_amount'), money: true, render: (row: Row) => money(row.amount) },
            { key: 'paid_on', title: t('payable_paid_on') },
            { key: 'reference', title: t('payable_reference'), render: (row: Row) => <>{row.reference ?? '—'}<small className="cell-sub">{row.note}</small></> },
            { key: 'maker_name', title: t('payable_recorded_by') },
            { key: 'action', title: t('col_action'), render: (row: Row) => canWork ? <RemittanceDecision row={row} t={t} /> : '—' },
        ]} empty={t('payables_none_waiting')} />
        <h3>{t('payables_monthly')}</h3>
        {payables.kinds.map((kind: Row) => <div key={kind.kind} className="ops-mb-4">
            <h4>{t(`payable_kind_${kind.kind}`)}</h4>
            <Table searchable={false} rows={kind.months.map((month: Row) => ({ ...month, id: month.month }))} columns={[
                { key: 'month', title: t('payable_month') },
                { key: 'withheld', title: t('payable_withheld'), money: true, render: (row: Row) => money(row.withheld) },
                { key: 'remitted', title: t('payable_remitted'), money: true, render: (row: Row) => money(row.remitted) },
                { key: 'still_payable', title: t('payable_still_payable'), money: true, render: (row: Row) => money(row.still_payable) },
            ]} empty={t('payables_no_months')} />
        </div>)}
    </section>;
}

function ClinicCash({ clinicCash, t }: { clinicCash: Row; t: Translate }) {
    const translate = useT();
    const canWork = useCanWorkOnStatements();
    const p = (key: string, replace: Record<string, string | number> = {}) => translate('prescription', key, replace);
    const form = useForm({ amount: '', deposited_on: new Date().toISOString().slice(0, 10), reference: '', note: '', request_key: newRequestKey() });
    return <section className="panel">
        <h2>{p('cash_title')}</h2>
        <p className="muted">{p('cash_hint')}</p>
        <div className="metric"><span>{p('cash_balance')}</span><strong>{money(clinicCash.balance)}</strong></div>
        {canWork && <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.post('/commands/operations/accounting/clinic-cash/deposits', { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, amount: '', reference: '', note: '', request_key: newRequestKey() }) });
        }}>
            <label>{t('payable_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} /></label>
            <label>{p('cash_deposited_on')}<input required type="date" value={form.data.deposited_on} onChange={event => form.setData('deposited_on', event.target.value)} /></label>
            <label>{p('cash_reference')}<input maxLength={100} value={form.data.reference} onChange={event => form.setData('reference', event.target.value)} /></label>
            <label>{p('cash_note')}<input required minLength={5} maxLength={1000} value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></label>
            {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
            <button className="button dark" disabled={form.processing}>{p('cash_deposit')}</button>
        </form>}
        <Table searchable={false} rows={clinicCash.deposits} columns={[
            { key: 'deposited_on', title: p('cash_deposited_on') },
            { key: 'amount', title: t('payable_amount'), money: true, render: (row: Row) => money(row.amount) },
            { key: 'reference', title: p('cash_reference'), render: (row: Row) => <>{row.reference ?? '—'}<small className="cell-sub">{row.note}</small></> },
            { key: 'recorded_by', title: t('payable_recorded_by') },
        ]} empty={p('cash_no_deposits')} />
    </section>;
}

export default function Accounting(props: Row) {
    const translate = useT();
    const t: Translate = (key, replace = {}) => translate('accounting', key, replace);

    return <Shell section="treasury"><Head title={t('tab_import')} />
        <div className="section-header"><div><Link className="text-link" href="/treasury/work">{translate('treasury', 'tab_work')}</Link><h2>{t('tab_import')}</h2></div></div>
        <StatementCheck {...props} t={t} />
        {props.payables && <Payables payables={props.payables} t={t} />}
        {props.clinicCash && <ClinicCash clinicCash={props.clinicCash} t={t} />}
    </Shell>;
}
