import React from 'react';
import { router, useForm } from '@inertiajs/react';
import { Download, Paperclip, Trash2 } from 'lucide-react';
import { Empty, FormErrors, date } from './UI';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type AttachmentRow = { id: number; kind: string; name: string; mime: string; size_bytes: number; created_at: string | null };

const megabytes = (bytes: number): string => `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

/** Lab reports and old prescriptions on one patient. */
export function PatientAttachmentsPanel({ patientId, kinds, rows, translate }: { patientId: number; kinds: string[]; rows: AttachmentRow[]; translate: Translate }) {
    const url = `/commands/prescriptions/patients/${patientId}/attachments`;
    const form = useForm<{ kind: string; file: File | null }>({ kind: kinds[0] ?? 'lab_report', file: null });

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const element = event.currentTarget;
        form.post(url, { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset('file'); element.reset(); } });
    };

    const remove = (row: AttachmentRow) => {
        if (window.confirm(translate('clinic_records', 'file_delete_confirm'))) {
            router.delete(`${url}/${row.id}`, { preserveScroll: true });
        }
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2 className="ops-row is-inline"><Paperclip size={18} /> {translate('clinic_records', 'files_title')}</h2>
                    <p className="muted">{translate('clinic_records', 'files_intro')}</p>
                </div>
            </div>
            <form className="action-form rx-form" onSubmit={submit}>
                <div className="rx-grid">
                    <label>{translate('clinic_records', 'file_kind')}
                        <select value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                            {kinds.map((kind) => <option key={kind} value={kind}>{translate('clinic_records', `file_kind_${kind}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('clinic_records', 'file_choose')}
                        <input required type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                    </label>
                </div>
                <FormErrors errors={form.errors} />
                <button className="button dark" disabled={form.processing || form.data.file === null}>{translate('clinic_records', 'file_upload')}</button>
            </form>
            {rows.length === 0 ? (
                <Empty title={translate('clinic_records', 'files_empty')} />
            ) : (
                <ul className="rx-list">
                    {rows.map((row) => (
                        <li key={row.id} className="rx-card rx-patient">
                            <div>
                                <b>{row.name}</b>
                                <small className="cell-sub">{[translate('clinic_records', `file_kind_${row.kind}`), megabytes(row.size_bytes), row.created_at ? date(row.created_at) : null].filter(Boolean).join(' · ')}</small>
                            </div>
                            <span className="rx-actions">
                                <a className="button small" href={`${url}/${row.id}`}><Download size={15} /> {translate('clinic_records', 'file_download')}</a>
                                <button type="button" className="icon-button" aria-label={translate('clinic_records', 'file_delete')} onClick={() => remove(row)}><Trash2 size={16} /></button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
