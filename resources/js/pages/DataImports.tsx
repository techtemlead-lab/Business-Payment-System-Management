import React, { useState } from 'react';
import { businessDateTime } from '../business-date';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { backgroundRefreshHeaders } from '../shared/backgroundRefresh';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import OfficePage from '../components/office/OfficePage';
import { settingsBack } from '../components/office/sections/SettingsForm';
import { Badge, Modal, Pagination, Row, Table, humanize, money } from '../components/UI';
import { useT } from '../shared/i18n/t';
import { useReasonFields } from '../shared/reasonFields';


export default function DataImports({ types, batches, preview, openingLocked, canApproveOwnOpening, templateBase, startup }: Row) {
    const translate = useT();
    const translated = (key: string, value: string) => {
        const localized = translate('portal', key);
        return localized === key ? humanize(value) : localized;
    };
    const typeTitle = (value: string) => translated(`imp_type_opt_${value}`, value);
    const title = (value: string) => translated(`imp_field_${value}`, value);
    const { auth, staffActions } = usePage<any>().props;
    const reasonOf = useReasonFields();
    usePoll(10000, { only: ['batches', 'preview'], headers: backgroundRefreshHeaders });
    const [mode, setMode] = useState(startup?.mode === 'fresh' ? 'zero' : 'import');
    const [uploadOpen, setUploadOpen] = useState(false);
    // The start mode has no box here: the page sends a set sentence, and leaves it out when the owners switched that reason off.
    const startupReason = reasonOf('settings.integration_startup');
    const startupForm = useForm({ mode: startup?.mode ?? (mode === 'zero' ? 'fresh' : 'import'), version: startup?.version ?? 0, reason: startupReason.shown ? translate('portal', 'imp_reason_selected') : '' });
    const upload = useForm<{ type: string; source_reference: string; file: File | null }>({
        type: types[0]?.key ?? '', source_reference: '', file: null,
    });
    const apply = useForm({ reason: '', confirmed: false, self_approve: false });
    const selectedType = types.find((type: Row) => type.key === upload.data.type);
    const isOpening = preview?.type === 'opening_balances';
    const ownOpening = isOpening && preview.created_by === auth.user.id;
    const applyReason = reasonOf('money.import_apply');
    const canApply = preview?.status === 'preview' && (!ownOpening || canApproveOwnOpening) && (isOpening
        ? !openingLocked && (staffActions?.finance?.approve ?? false)
        : types.find((type: Row) => type.key === preview.type)?.can_create);
    const summary = preview?.summary;
    const chooseMode = (next: 'zero' | 'import') => {
        setMode(next);
        const saved = next === 'zero' ? 'fresh' : 'import';
        startupForm.setData({ mode: saved, version: startup?.version ?? 0, reason: !startupReason.shown ? '' : next === 'zero'
            ? translate('portal', 'imp_reason_fresh')
            : translate('portal', 'imp_reason_import') });
        startupForm.post('/commands/operations/integrations/startup-mode', { preserveScroll: true });
    };

    const pageTitle = translate('portal', 'data_imports');
    const isPreviewing = Boolean(preview);

    return <Shell section="data-imports"><Head title={translate('portal','imp_head_title')} />
        <OfficePage
            title={isPreviewing ? preview.filename : pageTitle}
            back={isPreviewing ? { href: '/operations/data-imports', label: pageTitle } : settingsBack(translate)}
            action={!isPreviewing && mode === 'import' && <button type="button" className="button dark" onClick={() => setUploadOpen(true)}>{translate('office_settings','import_new')}</button>}
        >
            {!isPreviewing && <>
                <fieldset className="set-segments-field">
                    <legend className="sr-only">{translate('portal','imp_choose_title')}</legend>
                    <label><input type="radio" name="start-mode" checked={mode === 'zero'} onChange={() => chooseMode('zero')} /> {translate('office_settings','import_mode_fresh')}</label>
                    <label><input type="radio" name="start-mode" checked={mode === 'import'} onChange={() => chooseMode('import')} /> {translate('office_settings','import_mode_import')}</label>
                </fieldset>
                {!startup?.label && <p className="set-line">{translate('portal','imp_mode_not_selected')}</p>}
                {mode === 'zero' && <p className="set-line">{translate('portal','imp_fresh_no_reset')}</p>}
                {Object.keys(startupForm.errors).length > 0 && <div role="alert" className="error">{Object.values(startupForm.errors).join(' ')}</div>}
                {openingLocked && mode === 'import' && <p className="set-line">{translate('portal','imp_opening_locked')}</p>}
                <section className="panel table-panel">
                    <Table tools={false} rows={batches.data} columns={[
                        { key: 'filename', title: translate('portal','imp_col_workbook'), render: (row: Row) => <Link className="text-link" href={'?batch=' + row.id} preserveScroll>{row.filename}</Link> },
                        { key: 'type', title: translate('portal','imp_col_type'), render: (row: Row) => typeTitle(row.type) },
                        { key: 'status', title: translate('portal','enquiry_status_label'), render: (row: Row) => <Badge status={row.status} /> },
                        { key: 'source_reference', title: translate('portal','imp_col_source') },
                        { key: 'created_at', title: translate('portal','imp_col_uploaded'), render: (row: Row) => businessDateTime(row.created_at) },
                    ]} />
                    <Pagination page={batches} />
                </section>
            </>}
            {isPreviewing && <>
                <p className="set-line">{typeTitle(preview.type)} · {preview.source_reference} · <Badge status={preview.status} /></p>
                {preview.status === 'queued' && <p className="set-line" role="status">{translate('portal','imp_queued')}</p>}
                {preview.status === 'failed' && <p className="error" role="alert">{preview.review_reason}</p>}
                {preview.status === 'applied' && <p className="set-line" role="status">{translate('portal','imp_applied')}</p>}
                <dl className="profile-grid set-figures">
                    <div><dt>{translate('portal','imp_total_rows')}</dt><dd>{summary.total_rows}</dd></div>
                    <div><dt>{translate('portal','imp_valid_rows')}</dt><dd>{summary.valid_rows}</dd></div>
                    <div><dt>{translate('portal','imp_invalid_rows')}</dt><dd>{summary.invalid_rows}</dd></div>
                    {isOpening && ['available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'].map(key => <div key={key}><dt>{title(key)}</dt><dd>{money(summary[key])}</dd></div>)}
                </dl>
                {isOpening && <p className="set-line">{translate('portal','imp_opening_preview_notice')}</p>}
                {summary.invalid_rows > 0 && <p role="alert" className="error">{translate('portal','imp_invalid_block')}</p>}
                <section className="panel table-panel">
                    <Table tools={false} rows={preview.rows} columns={[
                        { key: 'row', title: translate('portal','imp_col_excel_row') },
                        ...types.find((type: Row) => type.key === preview.type).columns.map((key: string) => ({ key, title: title(key), render: (row: Row) => String(row.data[key] ?? '') })),
                        { key: 'errors', title: translate('portal','imp_col_validation'), render: (row: Row) => row.errors.length ? <span className="field-error">{row.errors.join(' ')}</span> : translate('portal','imp_valid') },
                    ]} />
                    {preview.row_pages > 1 && <nav aria-label={translate('portal','imp_preview_pages')} className="set-pager">
                        {preview.row_page > 1 && <Link className="button" href={'?batch=' + preview.id + '&row_page=' + (preview.row_page - 1)} preserveScroll>{translate('portal','imp_prev_rows')}</Link>}
                        <span>{translate('portal','imp_page_of',{page:String(preview.row_page),pages:String(preview.row_pages)})}</span>
                        {preview.row_page < preview.row_pages && <Link className="button" href={'?batch=' + preview.id + '&row_page=' + (preview.row_page + 1)} preserveScroll>{translate('portal','imp_next_rows')}</Link>}
                    </nav>}
                </section>
                <p><a className="text-link" href={'/commands/operations/imports/' + preview.id + '/source'}>{translate('portal','imp_download_source')}</a></p>
                {ownOpening && !canApproveOwnOpening && preview.status === 'preview' && <p className="set-line">{translate('portal','imp_own_opening')}</p>}
                {canApply && <div className="set-sheet import-workspace"><form className="action-form" onSubmit={event => {
                    event.preventDefault();
                    apply.post('/commands/operations/imports/' + preview.id + '/apply', { preserveScroll: true, onSuccess: () => apply.reset() });
                }}>
                    {applyReason.shown && <label>{applyReason.label(translate('portal','imp_review_notes'))}<textarea required={applyReason.required} minLength={applyReason.required ? 5 : undefined} maxLength={1000} rows={3} value={apply.data.reason} onChange={event => apply.setData('reason', event.target.value)} /></label>}
                    <label className="import-confirmation"><input type="checkbox" required checked={apply.data.confirmed} onChange={event => apply.setData('confirmed', event.target.checked)} /> {translate('portal','imp_confirm')}</label>
                    {ownOpening && <label className="import-confirmation"><input type="checkbox" required checked={apply.data.self_approve} onChange={event => apply.setData('self_approve', event.target.checked)} /> {translate('self_approval','confirm_label')}</label>}
                    {Object.keys(apply.errors).length > 0 && <p role="alert" className="error">{Object.values(apply.errors).join(' ')}</p>}
                    <button className="button dark set-save" disabled={apply.processing}>{apply.processing ? translate('portal','imp_applying') : isOpening ? translate('portal','imp_approve_opening') : translate('portal','imp_import_records')}</button>
                </form></div>}
            </>}
            {uploadOpen && <Modal title={translate('portal','imp_prepare_title')} onClose={() => setUploadOpen(false)}>
                <form className="action-form" onSubmit={event => {
                    event.preventDefault();
                    upload.post('/commands/operations/imports/preview', { forceFormData: true, preserveScroll: true, onSuccess: () => setUploadOpen(false) });
                }}>
                    <label>{translate('portal','imp_type')}<select value={upload.data.type} onChange={event => { upload.setData('type', event.target.value); upload.setData('file', null); }}>
                        {types.map((type: Row) => <option key={type.key} value={type.key}>{typeTitle(type.key)}</option>)}
                    </select></label>
                    <a className="button" href={templateBase + upload.data.type}><Download size={16} aria-hidden="true" /> {translate('portal','imp_download_template')}</a>
                    <label>{translate('portal','imp_source_ref')}<input required minLength={5} maxLength={150} value={upload.data.source_reference} onChange={event => upload.setData('source_reference', event.target.value)} placeholder={translate('portal','imp_source_ph')} /></label>
                    <label>{translate('portal','imp_workbook')}<input key={upload.data.type} required type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={event => upload.setData('file', event.target.files?.[0] ?? null)} /></label>
                    <p className="set-line">{translate('office_settings','import_prepare_rules')}</p>
                    {upload.data.type === 'opening_balances' && openingLocked && <p className="set-line">{translate('portal','imp_opening_locked')}</p>}
                    {upload.progress && <progress max={100} value={upload.progress.percentage} aria-label={translate('portal','imp_upload_progress')} />}
                    {Object.keys(upload.errors).length > 0 && <div role="alert" className="error">{Object.values(upload.errors).join(' ')}</div>}
                    <button className="button dark set-save" disabled={upload.processing || !selectedType?.can_create || (upload.data.type === 'opening_balances' && openingLocked)}>{upload.processing ? translate('portal','imp_validating') : translate('portal','imp_validate')}</button>
                </form>
            </Modal>}
        </OfficePage>
    </Shell>;
}
