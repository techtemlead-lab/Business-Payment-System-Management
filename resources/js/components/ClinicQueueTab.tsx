import React, { useState, type ReactNode } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { DateInput, Empty, FormErrors, number } from './UI';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

type Translate = ReturnType<typeof useT>;

export type QueueRow = { id: number; token: number; patient: string | null; patient_id: number; time: string | null; status: 'waiting' | 'serving' | 'done' | 'cancelled'; note: string | null };

export type QueueData = { date: string; today: string; rows: QueueRow[] };

type PickerOption = { id: number };

/** Today's tokens: give one to a patient, then call them in order. */
export function ClinicQueueTab<Option extends PickerOption>({ queue, renderPatientPicker, translate }: {
    queue: QueueData;
    renderPatientPicker: (value: string, chosen: Option | null, onChange: (id: string, patient: Option | null) => void) => ReactNode;
    translate: Translate;
}) {
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const [chosen, setChosen] = useState<Option | null>(null);
    const form = useForm({ patient_id: '', appointment_time: '', note: '' });
    const noteBox = useReasonField('clinic.appointment_note');
    const canBook = queue.date >= queue.today;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ ...values, appointment_on: queue.date, appointment_time: values.appointment_time || null, note: values.note || null }));
        form.post('/commands/prescriptions/appointments', { preserveScroll: true, onSuccess: () => { form.reset(); setChosen(null); } });
    };

    const setStatus = (row: QueueRow, status: string) => {
        router.post(`/commands/prescriptions/appointments/${row.id}/status`, { status }, { preserveScroll: true });
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('clinic_records', 'queue_title')}</h2>
                    <p className="muted">{translate('clinic_records', 'queue_intro')}</p>
                </div>
                <label>{translate('clinic_records', 'queue_date')}
                    <DateInput value={queue.date} onChange={(e) => router.get(`${base}/prescriptions`, { tab: 'queue', queue_date: e.target.value }, { preserveScroll: true })} />
                </label>
            </div>
            {canBook && (
                <form className="action-form rx-form" onSubmit={submit}>
                    <div className="rx-grid">
                        {renderPatientPicker(form.data.patient_id, chosen, (id, patient) => { setChosen(patient); form.setData('patient_id', id); })}
                        <label>{translate('clinic_records', 'queue_time')}<input type="time" value={form.data.appointment_time} onChange={(e) => form.setData('appointment_time', e.target.value)} /></label>
                        {noteBox.shown && <label>{noteBox.label(translate('reason_fields', 'box_appointment_reason'))}<input maxLength={500} required={noteBox.required} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></label>}
                    </div>
                    <FormErrors errors={form.errors} />
                    <button className="button dark" disabled={form.processing || form.data.patient_id === ''}>{translate('clinic_records', 'queue_add')}</button>
                </form>
            )}
            {queue.rows.length === 0 ? (
                <Empty title={translate('clinic_records', 'queue_empty')} />
            ) : (
                <ol className="rx-list">
                    {queue.rows.map((row) => (
                        <li key={row.id} className={`rx-card rx-patient rx-queue is-${row.status}`}>
                            <div>
                                <b>{translate('clinic_records', 'queue_token', { token: number(row.token) })}</b>
                                {' · '}<Link href={`${base}/prescriptions/patients/${row.patient_id}`}>{row.patient}</Link>
                                <small className="cell-sub">{[row.time, translate('clinic_records', `queue_status_${row.status}`), row.note].filter(Boolean).join(' · ')}</small>
                            </div>
                            {(row.status === 'waiting' || row.status === 'serving') && (
                                <span className="rx-actions">
                                    {row.status === 'waiting'
                                        ? <button type="button" className="button small dark" onClick={() => setStatus(row, 'serving')}>{translate('clinic_records', 'queue_call')}</button>
                                        : <button type="button" className="button small dark" onClick={() => setStatus(row, 'done')}>{translate('clinic_records', 'queue_finish')}</button>}
                                    <button type="button" className="button small" onClick={() => setStatus(row, 'cancelled')}>{translate('clinic_records', 'queue_cancel')}</button>
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
