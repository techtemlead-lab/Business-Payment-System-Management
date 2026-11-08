import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, History, Printer, Repeat } from 'lucide-react';
import Shell from '../components/Shell';
import { Empty, date, money, number } from '../components/UI';
import type { PatientRow } from '../components/PrescriptionsHub';
import { PatientAccessLogList, type AccessLogRow } from '../components/PatientAccessLogList';
import { useT } from '../shared/i18n/t';

type HistoryLine = { medicine_name: string; dose: string | null; frequency: string | null; duration: string | null; instructions: string | null; dose_summary: string; quantity: number };

type HistoryRow = {
    id: number;
    number: string;
    kind: 'partner' | 'clinic';
    status: 'issued' | 'dispensed';
    prescribed_on: string;
    follow_up_on: string | null;
    complaints: string | null;
    diagnosis: string | null;
    advice: string | null;
    total: string;
    lines: HistoryLine[];
};

type PatientRecordData = {
    patient: PatientRow;
    prescriptions: HistoryRow[];
    access_log: AccessLogRow[];
};

export default function PatientRecord({ record }: { record: PatientRecordData }) {
    const translate = useT();
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const { patient, prescriptions } = record;
    const facts = [
        patient.age !== null ? translate('prescription', 'print_years', { age: patient.age }) : null,
        patient.sex ? translate('prescription', `sex_${patient.sex}`) : null,
        patient.phone,
        patient.place,
    ].filter(Boolean).join(' · ');

    return (
        <Shell section="prescriptions">
            <Head title={patient.name} />
            <div className="rx-hub">
                <Link className="text-link" href={`${base}/prescriptions?tab=patients`}>{translate('clinic_records', 'back_to_patients')}</Link>
                <section className="panel">
                    <div className="panel-heading">
                        <div>
                            <h2 className="ops-row is-inline"><History size={20} /> {patient.name}</h2>
                            {facts && <p className="muted">{facts}</p>}
                            {patient.notes && <p className="muted">{patient.notes}</p>}
                        </div>
                        {prescriptions.length > 0 && (
                            <Link className="button small dark" href={`${base}/prescriptions?tab=prescriptions&repeat=${patient.id}`}>
                                <Repeat size={15} /> {translate('clinic_records', 'repeat_last')}
                            </Link>
                        )}
                    </div>
                    {patient.allergies && (
                        <div className="rx-allergy" role="alert">
                            <AlertTriangle size={20} aria-hidden="true" />
                            <div>
                                <b>{translate('clinic_records', 'allergy_warning_title')}</b>
                                <p>{patient.allergies}</p>
                            </div>
                        </div>
                    )}
                </section>

                <section className="panel">
                    <div className="panel-heading"><h2>{translate('clinic_records', 'history_title')}</h2></div>
                    {prescriptions.length === 0 ? (
                        <Empty title={translate('clinic_records', 'history_empty')} body={translate('clinic_records', 'history_empty_body')} />
                    ) : (
                        <ul className="rx-list">
                            {prescriptions.map((row) => (
                                <li key={row.id} className="rx-card">
                                    <div className="rx-card-head">
                                        <div>
                                            <b>{row.number}</b>
                                            <small className="cell-sub">{date(row.prescribed_on)}{row.follow_up_on ? ` · ${translate('prescription', 'rx_follow_up')}: ${date(row.follow_up_on)}` : ''}</small>
                                        </div>
                                        <span className={`rx-status is-${row.status}`}>{translate('prescription', `rx_status_${row.status}`)}</span>
                                    </div>
                                    {row.complaints && <small><b>{translate('prescription', 'rx_complaints')}:</b> {row.complaints}</small>}
                                    {row.diagnosis && <small><b>{translate('prescription', 'rx_diagnosis')}:</b> {row.diagnosis}</small>}
                                    <ol className="rx-card-lines">
                                        {row.lines.map((line, index) => (
                                            <li key={index}>
                                                {line.medicine_name} × {number(line.quantity)}
                                                <small>{[line.dose_summary, line.dose, line.frequency, line.duration, line.instructions].filter(Boolean).join(' · ')}</small>
                                            </li>
                                        ))}
                                    </ol>
                                    {row.advice && <small><b>{translate('prescription', 'rx_advice')}:</b> {row.advice}</small>}
                                    <div className="rx-card-foot">
                                        <span>{translate('prescription', 'rx_total')}: <b>{money(row.total)}</b></span>
                                        <span className="rx-actions">
                                            <a className="button small" href={`${base}/prescriptions/${row.id}/print?lang=bn`} target="_blank" rel="noopener"><Printer size={15} /> {translate('prescription', 'rx_print_bn')}</a>
                                            <a className="button small" href={`${base}/prescriptions/${row.id}/print?lang=en`} target="_blank" rel="noopener"><Printer size={15} /> {translate('prescription', 'rx_print_en')}</a>
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="panel">
                    <div className="panel-heading"><div><h2>{translate('clinic_records', 'access_log_patient_title')}</h2><p className="muted">{translate('clinic_records', 'access_log_intro')}</p></div></div>
                    <PatientAccessLogList rows={record.access_log} showPatient={false} translate={translate} />
                </section>
            </div>
        </Shell>
    );
}
