import React, { useState } from 'react';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { FileSpreadsheet, Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Pagination, Row, Table, money } from '../components/UI';

const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());

export default function DataImports({ types, batches, preview, openingLocked, templateBase }: Row) {
    const { auth, staffActions } = usePage<any>().props;
    usePoll(10000, { only: ['batches', 'preview'] });
    const [mode, setMode] = useState('import');
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

    return <Shell section="data-imports"><Head title="Excel Data Import" />
        <div className="import-workspace"><div className="section-header"><div><h2>Bring your records together</h2><p className="muted">Bring existing business records into Madina with a validated preview and an audit trail.</p></div><FileSpreadsheet size={30} aria-hidden="true" /></div>
        <section className="panel">
            <h2>Choose how to begin</h2>
            <div className="import-options">
                <label className="import-choice"><input type="radio" name="start-mode" checked={mode === 'zero'} onChange={() => setMode('zero')} /> Start a new business from zero</label>
                <label className="import-choice"><input type="radio" name="start-mode" checked={mode === 'import'} onChange={() => setMode('import')} /> Import existing records</label>
            </div>
            {mode === 'zero' && <div className="notice"><p>No workbook is required for a new business. Create partner accounts, products and opening stock through administration. Verify the starting ledger before enabling financial posting.</p><p>This option does not delete or reset any data. Preview records remain until the agreed handover.</p></div>}
        </section>
        {mode === 'import' && <section className="panel">
            <h2>Prepare and validate a workbook</h2>
            <p>Use the template’s <strong>Data</strong> worksheet. Import sponsors before referrals, and partners before financial balances. Each workbook supports up to 5,000 rows and 10 MB.</p>
            <form className="action-form" onSubmit={event => {
                event.preventDefault();
                upload.post('/commands/operations/imports/preview', { forceFormData: true, preserveScroll: true });
            }}>
                <label>Import type<select value={upload.data.type} onChange={event => { upload.setData('type', event.target.value); upload.setData('file', null); }}>
                    {types.map((type: Row) => <option key={type.key} value={type.key}>{title(type.key)}</option>)}
                </select></label>
                <a className="button" href={templateBase + upload.data.type}><Download size={16} aria-hidden="true" /> Download Excel template</a>
                <label>Source or statement reference<input required minLength={5} maxLength={150} value={upload.data.source_reference} onChange={event => upload.setData('source_reference', event.target.value)} placeholder="For example: October opening statement" /></label>
                <label>Excel workbook (.xlsx)<input key={upload.data.type} required type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={event => upload.setData('file', event.target.files?.[0] ?? null)} /></label>
                {upload.progress && <progress max={100} value={upload.progress.percentage} aria-label="Workbook upload progress" />}
                {Object.keys(upload.errors).length > 0 && <div role="alert" className="error">{Object.values(upload.errors).join(' ')}</div>}
                <button className="button dark" disabled={upload.processing || !selectedType?.can_create || (upload.data.type === 'opening_balances' && openingLocked)}>{upload.processing ? 'Validating workbook…' : 'Validate and preview'}</button>
            </form>
            <p className="muted">Products arrive as unpublished drafts. Send password setup invitations through Accounts for imported partners. Formulas, macros and external links are rejected.</p>
            {openingLocked && <div className="notice">Opening financial imports are locked after opening verification or financial activation. Partner and product imports remain available with the required permissions.</div>}
        </section>}
        {preview && <section className="panel">
            <h2>Import preview <Badge status={preview.status} /></h2>
            <p><strong>{preview.filename}</strong> · {title(preview.type)} · {preview.source_reference}</p>
            {preview.status === 'queued' && <p className="notice" role="status">Your import is processing. This page updates automatically. Existing records remain available.</p>}
            {preview.status === 'failed' && <p className="error" role="alert">{preview.review_reason}</p>}
            <div className="profile-grid">
                <div><strong>{summary.total_rows}</strong><p>Total rows</p></div><div><strong>{summary.valid_rows}</strong><p>Valid rows</p></div><div><strong>{summary.invalid_rows}</strong><p>Rows requiring correction</p></div>
                {isOpening && ['available_commission', 'held_commission', 'historical_order_total', 'historical_order_paid'].map(key => <div key={key}><strong>{money(summary[key])}</strong><p>{title(key)}</p></div>)}
            </div>
            {preview.status === 'applied' && <p className="notice" role="status">Import completed successfully. Existing records were preserved.</p>}
            {isOpening && <div className="notice"><p>Previewing changes no balances. Approval records brought-forward amounts against opening equity. Historical orders generate no new commissions or stock movements. A separate Finance reviewer must approve this import before final opening-balance verification.</p></div>}
            {summary.invalid_rows > 0 && <p role="alert" className="error">No rows will be imported while any row is invalid. Correct the workbook and upload it again.</p>}
            <Table rows={preview.rows} columns={[
                { key: 'row', title: 'Excel row' },
                ...types.find((type: Row) => type.key === preview.type).columns.map((key: string) => ({ key, title: title(key), render: (row: Row) => String(row.data[key] ?? '') })),
                { key: 'errors', title: 'Validation', render: row => row.errors.length ? <span className="field-error">{row.errors.join(' ')}</span> : 'Valid' },
            ]} />
            {preview.row_pages > 1 && <nav aria-label="Import preview pages" style={{ display: 'flex', gap: 12, marginTop: 16 }}>
                {preview.row_page > 1 && <Link className="button" href={'?batch=' + preview.id + '&row_page=' + (preview.row_page - 1)} preserveScroll>Previous rows</Link>}
                <span>Page {preview.row_page} of {preview.row_pages}</span>
                {preview.row_page < preview.row_pages && <Link className="button" href={'?batch=' + preview.id + '&row_page=' + (preview.row_page + 1)} preserveScroll>Next rows</Link>}
            </nav>}
            <p><a className="text-link" href={'/commands/operations/imports/' + preview.id + '/source'}>Download original workbook</a></p>
            {ownOpening && preview.status === 'preview' && <p className="notice">Another authorized Finance reviewer must approve this opening import. You cannot approve your own opening entries.</p>}
            {canApply && <form className="action-form" onSubmit={event => {
                event.preventDefault();
                apply.post('/commands/operations/imports/' + preview.id + '/apply', { preserveScroll: true, onSuccess: () => apply.reset() });
            }}>
                <label>Review notes<textarea required minLength={5} maxLength={1000} rows={3} value={apply.data.reason} onChange={event => apply.setData('reason', event.target.value)} /></label>
                <label className="import-confirmation"><input type="checkbox" required checked={apply.data.confirmed} onChange={event => apply.setData('confirmed', event.target.checked)} /> I reviewed the source, rows and totals and authorize this import.</label>
                {Object.keys(apply.errors).length > 0 && <p role="alert" className="error">{Object.values(apply.errors).join(' ')}</p>}
                <button className="button dark" disabled={apply.processing}>{apply.processing ? 'Applying import…' : isOpening ? 'Approve opening entries' : 'Import validated records'}</button>
            </form>}
        </section>}
        <section className="panel"><h2>Import history</h2>
            <Table rows={batches.data} columns={[
                { key: 'filename', title: 'Workbook' }, { key: 'type', title: 'Type', render: row => title(row.type) },
                { key: 'status', title: 'Status', render: row => <Badge status={row.status} /> },
                { key: 'source_reference', title: 'Source reference' }, { key: 'created_at', title: 'Uploaded (UTC)' },
                { key: 'details', title: 'Review', render: row => <Link className="button small" href={'?batch=' + row.id} preserveScroll>View import</Link> },
            ]} /><Pagination page={batches} />
        </section>
    </div></Shell>;
}
