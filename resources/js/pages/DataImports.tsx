import React, { useState } from 'react';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { FileSpreadsheet, Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Pagination, Row, Table, money } from '../components/UI';
import { useT } from '../shared/i18n/t';

const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());

export default function DataImports({ types, batches, preview, openingLocked, templateBase, startup }: Row) {
    const translate = useT();
    const { auth, staffActions } = usePage<any>().props;
    usePoll(10000, { only: ['batches', 'preview'] });
    const [mode, setMode] = useState(startup?.mode === 'fresh' ? 'zero' : 'import');
    const startupForm = useForm({ mode: startup?.mode ?? (mode === 'zero' ? 'fresh' : 'import'), version: startup?.version ?? 0, reason: translate('portal', 'imp_reason_selected') });
    const upload = useForm<{ type: string; source_reference: string; file: File | null }>({
        type: types[0]?.key ?? '', source_reference: '', file: null,
    });
    const apply = useForm({ reason: '', confirmed: false });
    const selectedType = types.find((type: Row) => type.key === upload.data.type);
    const isOpening = preview?.type === 'opening_balances';
    const ownOpening = isOpening && preview.created_by === auth.user.id;
    const canApply = preview?.status === 'preview' && !ownOpening && (isOpening
        ? !openingLocked && (staffActions?.finance?.approve ?? false)
        : types.find((type: Row) => type.key === preview.type)?.can_create);
    const summary = preview?.summary;
    const chooseMode = (next: 'zero' | 'import') => {
        setMode(next);
        const saved = next === 'zero' ? 'fresh' : 'import';
        startupForm.setData({ mode: saved, version: startup?.version ?? 0, reason: next === 'zero'
            ? translate('portal', 'imp_reason_fresh')
            : translate('portal', 'imp_reason_import') });
        startupForm.post('/commands/operations/integrations/startup-mode', { preserveScroll: true });
    };

    return <Shell section="data-imports"><Head title={translate('portal','imp_head_title')} />
        <div className="import-workspace"><div className="section-header"><div><h2>{translate('portal','imp_heading')}</h2><p className="muted">{translate('portal','imp_heading_hint')}</p></div><FileSpreadsheet size={30} aria-hidden="true" /></div>
        <section className="panel">
            <h2>{translate('portal','imp_choose_title')}</h2>
            <p className="muted">{translate('portal','imp_choose_hint')} <strong>{startup?.label ?? translate('portal','imp_mode_not_selected')}</strong>.</p>
            <div className="import-options">
                <label className="import-choice"><input type="radio" name="start-mode" checked={mode === 'zero'} onChange={() => chooseMode('zero')} /> {translate('portal','imp_mode_fresh')}</label>
                <label className="import-choice"><input type="radio" name="start-mode" checked={mode === 'import'} onChange={() => chooseMode('import')} /> {translate('portal','imp_mode_import')}</label>
            </div>
            {mode === 'zero' && <div className="notice"><p>{translate('portal','imp_fresh_notice')}</p><p>{translate('portal','imp_fresh_no_reset')}</p></div>}
            {Object.keys(startupForm.errors).length > 0 && <div role="alert" className="error">{Object.values(startupForm.errors).join(' ')}</div>}
        </section>
        {mode === 'import' && <section className="panel">
            <h2>{translate('portal','imp_prepare_title')}</h2>
            <p>{translate('portal','imp_prepare_body')}</p>
            <form className="action-form" onSubmit={event => {
                event.preventDefault();
                upload.post('/commands/operations/imports/preview', { forceFormData: true, preserveScroll: true });
            }}>
                <label>{translate('portal','imp_type')}<select value={upload.data.type} onChange={event => { upload.setData('type', event.target.value); upload.setData('file', null); }}>
                    {types.map((type: Row) => <option key={type.key} value={type.key}>{title(type.key)}</option>)}
                </select></label>
                <a className="button" href={templateBase + upload.data.type}><Download size={16} aria-hidden="true" /> {translate('portal','imp_download_template')}</a>
                <label>{translate('portal','imp_source_ref')}<input required minLength={5} maxLength={150} value={upload.data.source_reference} onChange={event => upload.setData('source_reference', event.target.value)} placeholder={translate('portal','imp_source_ph')} /></label>
                <label>{translate('portal','imp_workbook')}<input key={upload.data.type} required type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={event => upload.setData('file', event.target.files?.[0] ?? null)} /></label>
                {upload.progress && <progress max={100} value={upload.progress.percentage} aria-label={translate('portal','imp_upload_progress')} />}
                {Object.keys(upload.errors).length > 0 && <div role="alert" className="error">{Object.values(upload.errors).join(' ')}</div>}
                <button className="button dark" disabled={upload.processing || !selectedType?.can_create || (upload.data.type === 'opening_balances' && openingLocked)}>{upload.processing ? translate('portal','imp_validating') : translate('portal','imp_validate')}</button>
            </form>
            <p className="muted">{translate('portal','imp_prepare_note')}</p>
            {openingLocked && <div className="notice">{translate('portal','imp_opening_locked')}</div>}
        </section>}
        {preview && <section className="panel">
            <h2>{translate('portal','imp_preview_title')} <Badge status={preview.status} /></h2>
            <p><strong>{preview.filename}</strong> · {title(preview.type)} · {preview.source_reference}</p>
            {preview.status === 'queued' && <p className="notice" role="status">{translate('portal','imp_queued')}</p>}
            {preview.status === 'failed' && <p className="error" role="alert">{preview.review_reason}</p>}
            <div className="profile-grid">
                <div><strong>{summary.total_rows}</strong><p>{translate('portal','imp_total_rows')}</p></div><div><strong>{summary.valid_rows}</strong><p>{translate('portal','imp_valid_rows')}</p></div><div><strong>{summary.invalid_rows}</strong><p>{translate('portal','imp_invalid_rows')}</p></div>
                {isOpening && ['available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'].map(key => <div key={key}><strong>{money(summary[key])}</strong><p>{title(key)}</p></div>)}
            </div>
            {preview.status === 'applied' && <p className="notice" role="status">{translate('portal','imp_applied')}</p>}
            {isOpening && <div className="notice"><p>{translate('portal','imp_opening_preview_notice')}</p></div>}
            {summary.invalid_rows > 0 && <p role="alert" className="error">{translate('portal','imp_invalid_block')}</p>}
            <Table rows={preview.rows} columns={[
                { key: 'row', title: translate('portal','imp_col_excel_row') },
                ...types.find((type: Row) => type.key === preview.type).columns.map((key: string) => ({ key, title: title(key), render: (row: Row) => String(row.data[key] ?? '') })),
                { key: 'errors', title: translate('portal','imp_col_validation'), render: row => row.errors.length ? <span className="field-error">{row.errors.join(' ')}</span> : translate('portal','imp_valid') },
            ]} />
            {preview.row_pages > 1 && <nav aria-label={translate('portal','imp_preview_pages')} style={{ display: 'flex', gap: 12, marginTop: 16 }}>
                {preview.row_page > 1 && <Link className="button" href={'?batch=' + preview.id + '&row_page=' + (preview.row_page - 1)} preserveScroll>{translate('portal','imp_prev_rows')}</Link>}
                <span>{translate('portal','imp_page_of',{page:String(preview.row_page),pages:String(preview.row_pages)})}</span>
                {preview.row_page < preview.row_pages && <Link className="button" href={'?batch=' + preview.id + '&row_page=' + (preview.row_page + 1)} preserveScroll>{translate('portal','imp_next_rows')}</Link>}
            </nav>}
            <p><a className="text-link" href={'/commands/operations/imports/' + preview.id + '/source'}>{translate('portal','imp_download_source')}</a></p>
            {ownOpening && preview.status === 'preview' && <p className="notice">{translate('portal','imp_own_opening')}</p>}
            {canApply && <form className="action-form" onSubmit={event => {
                event.preventDefault();
                apply.post('/commands/operations/imports/' + preview.id + '/apply', { preserveScroll: true, onSuccess: () => apply.reset() });
            }}>
                <label>{translate('portal','imp_review_notes')}<textarea required minLength={5} maxLength={1000} rows={3} value={apply.data.reason} onChange={event => apply.setData('reason', event.target.value)} /></label>
                <label className="import-confirmation"><input type="checkbox" required checked={apply.data.confirmed} onChange={event => apply.setData('confirmed', event.target.checked)} /> {translate('portal','imp_confirm')}</label>
                {Object.keys(apply.errors).length > 0 && <p role="alert" className="error">{Object.values(apply.errors).join(' ')}</p>}
                <button className="button dark" disabled={apply.processing}>{apply.processing ? translate('portal','imp_applying') : isOpening ? translate('portal','imp_approve_opening') : translate('portal','imp_import_records')}</button>
            </form>}
        </section>}
        <section className="panel"><h2>{translate('portal','imp_history_title')}</h2>
            <Table rows={batches.data} columns={[
                { key: 'filename', title: translate('portal','imp_col_workbook') }, { key: 'type', title: translate('portal','imp_col_type'), render: row => title(row.type) },
                { key: 'status', title: translate('portal','enquiry_status_label'), render: row => <Badge status={row.status} /> },
                { key: 'source_reference', title: translate('portal','imp_col_source') }, { key: 'created_at', title: translate('portal','imp_col_uploaded') },
                { key: 'details', title: translate('portal','imp_col_review'), render: row => <Link className="button small" href={'?batch=' + row.id} preserveScroll>{translate('portal','imp_view')}</Link> },
            ]} /><Pagination page={batches} />
        </section>
    </div></Shell>;
}
