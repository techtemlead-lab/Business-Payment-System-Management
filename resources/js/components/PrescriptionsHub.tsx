import React, { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, FileText, History, Printer, Repeat, Search, Stethoscope, UserPlus } from 'lucide-react';
import { DateInput, Empty, FormErrors, date, money, number, Pagination, type Row } from './UI';
import SearchSelect from './SearchSelect';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { businessTodayIso, localizeDigits } from '../business-date';
import { SaveAsTemplate, TemplatesTab, type PrescriptionTools } from './PrescriptionTemplates';
import { DietTemplatesSection } from './PrescriptionDietTemplates';
import PrescriptionNotepad, { type CopySource, type NotepadData } from './PrescriptionNotepad';
import { blankPatient, PatientFields, PatientPicker, patientForServer, type PatientForm, type PatientOption } from './PrescriptionPatientStep';
import { FollowUpsPanel } from './PrescriptionFollowUps';
import { PrescriberReportTab } from './PrescriberReport';
import { ConsultationRequestsTab, PublicListingTab, type PrescriberPublicData } from './PrescriberPublicTabs';
import { ClinicQueueTab } from './ClinicQueueTab';
import { LetterheadSettings, type LetterheadSettingsData } from './LetterheadSettings';
import { PatientAccessLogList } from './PatientAccessLogList';

type Translate = ReturnType<typeof useT>;

/** Request field and error key: the owner's answer to the "company loss" warning. */
const CONFIRM_BELOW_FLOOR = 'confirm_below_floor';

export type { PatientOption };

export type PatientRow = {
    id: number;
    name: string;
    age: number | null;
    sex: string | null;
    phone: string | null;
    phone_masked: string;
    address_text: string | null;
    patient_code: string;
    place: string | null;
    notes: string | null;
    consent_at: string | null;
    allergies: string | null;
    own: boolean;
};

type PrescriptionRow = {
    id: number;
    number: string;
    kind: 'partner' | 'clinic';
    own: boolean;
    patient: string | null;
    patient_id: number;
    advice: string | null;
    prescribed_on: string;
    follow_up_on: string | null;
    diagnosis: string | null;
    investigations: string[];
    foods_allowed: string[];
    foods_avoided: string[];
    subtotal: string;
    discount_total: string;
    total: string;
    status: 'issued' | 'dispensed';
    manufacturer_share: string;
    clinic_fund: string;
    payment_method: string;
    payment_reference: string | null;
    lines: { id: number; product_id: number | null; medicine_name: string; strength: string | null; company_product: boolean; dose: string | null; frequency: string | null; duration: string | null; dose_schedule: string | null; food_timing: string | null; duration_count: number | null; duration_unit: string | null; instructions: string | null; dose_summary: string; quantity: number; unit_price: string; discount: string; line_total: string }[];
};

type ReportData = {
    from: string;
    to: string;
    count: number;
    sales: string;
    manufacturer_share: string;
    clinic_fund: string;
    days: { day: string; count: number; sales: string; manufacturer_share: string; clinic_fund: string }[];
    by_method: { method: string; count: number; sales: string }[];
};

export type PrescriptionsData = {
    mode: 'partner' | 'clinic';
    is_owner: boolean;
    patients: PatientRow[];
    patients_page: Row;
    own_patients: PatientOption[];
    prescriptions: PrescriptionRow[];
    prescriptions_page: Row;
    sexes: string[];
    registration: { type: string; number: string } | null;
    registration_types: string[];
    max_lines: number;
    letterhead: NotepadData['letterhead'];
    doctor_name: string;
    next_patient_code: string;
    today: string;
    products: { id: number; name: string; sku: string; mrp: string }[];
    /** The owners' switch: may a medicine that is not a company product be typed by hand? */
    other_medicines_allowed: boolean;
    /** Only for owners, who can change the switch. */
    other_medicines?: { enabled: boolean; version: number };
    /** The prescriber's own printed paper: header, footer and logo. */
    letterhead_settings: LetterheadSettingsData;
    branches?: { id: number; code: string; name: string }[];
    payment_methods?: string[];
    manufacturer_percent?: string;
    report?: ReportData;
};

function Tabs({ tabs, active, onChange }: { tabs: { id: string; label: string }[]; active: string; onChange: (id: string) => void }) {
    return (
        <div className="retained-detail-tabs rx-tabs" role="tablist">
            {tabs.map((tab) => (
                <button key={tab.id} type="button" role="tab" aria-selected={tab.id === active} className={tab.id === active ? 'is-active' : undefined} onClick={() => onChange(tab.id)}>
                    {tab.label}
                </button>
            ))}
        </div>
    );
}

export default function PrescriptionsHub({ data, tools, publicTools }: { data: PrescriptionsData; tools: PrescriptionTools; publicTools: PrescriberPublicData }) {
    const translate = useT();
    const clinic = data.mode === 'clinic';
    const [tab, setTab] = useState<string>(() => {
        const fromUrl = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;
        return fromUrl ?? 'prescriptions';
    });
    const tabs = [
        { id: 'prescriptions', label: translate('prescription', 'tab_prescriptions') },
        { id: 'patients', label: translate('prescription', 'tab_patients') },
        { id: 'requests', label: publicTools.new_requests > 0 ? `${translate('prescriber_public', 'tab_requests')} (${number(publicTools.new_requests)})` : translate('prescriber_public', 'tab_requests') },
        { id: 'templates', label: translate('prescription', 'tab_templates') },
        { id: 'my-report', label: translate('prescription', 'tab_my_report') },
        { id: 'queue', label: translate('clinic_records', 'queue_tab') },
        { id: 'access-log', label: translate('clinic_records', 'access_log_tab') },
        { id: 'public-listing', label: translate('prescriber_public', 'tab_listing') },
        ...(data.is_owner ? [{ id: 'report', label: translate('prescription', 'tab_report') }] : []),
        // The owner-doctor's registration, and for every owner the switch for other companies' medicines.
        { id: 'settings', label: translate('prescription', 'tab_settings') },
    ];
    const activeTab = tabs.some((item) => item.id === tab) ? tab : 'prescriptions';

    return (
        <div className="rx-hub">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2 className="ops-row is-inline"><Stethoscope size={20} /> {translate('prescription', 'title')}</h2>
                        <p className="muted">{translate('prescription', clinic ? 'intro_clinic' : 'intro')}</p>
                        {data.registration && (
                            <p className="rx-reg">
                                {translate('prescription', `type_${data.registration.type}`)} · {translate('prescription', 'print_reg')} {data.registration.number}
                            </p>
                        )}
                    </div>
                </div>
                <Tabs tabs={tabs} active={activeTab} onChange={setTab} />
            </section>
            {activeTab === 'prescriptions' && <FollowUpsPanel followUps={tools.follow_ups} translate={translate} />}
            {activeTab === 'prescriptions' && <PrescriptionsTab data={data} tools={tools} translate={translate} />}
            {activeTab === 'patients' && <PatientsTab data={data} translate={translate} />}
            {activeTab === 'templates' && <TemplatesTab templates={tools.templates} translate={translate} />}
            {activeTab === 'templates' && <DietTemplatesSection templates={tools.diet_templates} translate={translate} />}
            {activeTab === 'queue' && (
                <ClinicQueueTab<PatientOption>
                    queue={tools.queue}
                    renderPatientPicker={(value, chosen, onChange) => <PatientPicker initial={data.own_patients} value={value} chosen={chosen} onChange={onChange} translate={translate} />}
                    translate={translate}
                />
            )}
            {activeTab === 'access-log' && (
                <section className="panel">
                    <div className="panel-heading"><div><h2>{translate('clinic_records', 'access_log_title')}</h2><p className="muted">{translate('clinic_records', 'access_log_intro')}</p></div></div>
                    <PatientAccessLogList rows={tools.access_log} showPatient translate={translate} />
                </section>
            )}
            {activeTab === 'my-report' && <PrescriberReportTab report={tools.report} translate={translate} />}
            {activeTab === 'requests' && <ConsultationRequestsTab data={publicTools} translate={translate} />}
            {activeTab === 'public-listing' && <PublicListingTab data={publicTools} translate={translate} />}
            {activeTab === 'report' && data.is_owner && data.report && <ReportTab report={data.report} translate={translate} />}
            {activeTab === 'settings' && <SettingsTab data={data} translate={translate} />}
        </div>
    );
}

function PrescriptionsTab({ data, tools, translate }: { data: PrescriptionsData; tools: PrescriptionTools; translate: Translate }) {
    const [copy, setCopy] = useState<CopySource | null>(null);

    // ?repeat=<patient id> (from a patient's record) starts the notepad from that patient's last prescription.
    useEffect(() => {
        const patientId = new URLSearchParams(window.location.search).get('repeat');
        if (!patientId || !/^\d+$/.test(patientId)) {
            return;
        }
        void fetch(`/commands/prescriptions/patients/${patientId}/repeat`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((json: { patient: PatientOption; draft: { number: string; diagnosis: string | null; advice: string | null; lines: CopySource['lines'] } | null } | null) => {
                if (json) {
                    setCopy({ nonce: Date.now(), number: json.draft?.number ?? '', patient: json.patient, diagnosis: json.draft?.diagnosis ?? null, advice: json.draft?.advice ?? null, lines: json.draft?.lines ?? [] });
                }
            });
    }, []);

    const copyFrom = (row: PrescriptionRow) => {
        const patient = data.own_patients.find((option) => option.id === row.patient_id) ?? null;
        setCopy({
            nonce: Date.now(),
            number: row.number,
            patient,
            diagnosis: row.diagnosis,
            advice: row.advice,
            lines: row.lines.map((line) => ({ ...line, product_id: line.product_id, discount: line.discount })),
        });
    };

    return (
        <>
            <PrescriptionNotepad data={data} tools={tools} copy={copy} translate={translate} />
            <PrescriptionList data={data} translate={translate} onCopy={copyFrom} />
        </>
    );
}

type LineLink = { product_id: string; quantity: string; unit_price: string; discount: string };

function PrescriptionList({ data, translate, onCopy }: { data: PrescriptionsData; translate: Translate; onCopy: (row: PrescriptionRow) => void }) {
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const branches = data.branches ?? [];
    const paymentMethods = data.payment_methods ?? [];
    const [branchFor, setBranchFor] = useState<Record<number, string>>({});
    const [paymentFor, setPaymentFor] = useState<Record<number, { method: string; reference: string }>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<number | null>(null);
    // The owner-doctor's matches of typed medicines to company products, by line id.
    const [linkFor, setLinkFor] = useState<Record<number, LineLink>>({});
    const linkOf = (lineId: number): LineLink => linkFor[lineId] ?? { product_id: '', quantity: '1', unit_price: '', discount: '' };
    const setLink = (lineId: number, patch: Partial<LineLink>) => setLinkFor({ ...linkFor, [lineId]: { ...linkOf(lineId), ...patch } });
    const productOptions = (data.products ?? []).map((product) => ({ value: String(product.id), label: `${product.name} — ${translate('prescription', 'rx_mrp')} ${money(product.mrp)}`, keywords: product.sku }));
    const paymentOf = (id: number) => paymentFor[id] ?? { method: 'cash', reference: '' };
    const setPayment = (id: number, patch: Partial<{ method: string; reference: string }>) => setPaymentFor({ ...paymentFor, [id]: { ...paymentOf(id), ...patch } });

    const dispense = (row: PrescriptionRow, confirmBelowFloor = false) => {
        const branchId = branchFor[row.id] ?? String(branches[0]?.id ?? '');
        if (!branchId || (!confirmBelowFloor && !window.confirm(translate('prescription', 'dispense_confirm')))) {
            return;
        }
        const payment = paymentOf(row.id);
        const links: Record<string, { product_id: number; quantity: number; unit_price: string; discount: string }> = {};
        for (const line of row.lines) {
            const link = linkOf(line.id);
            if (line.product_id === null && link.product_id !== '') {
                links[String(line.id)] = { product_id: Number(link.product_id), quantity: Number(link.quantity) || 1, unit_price: link.unit_price || '0', discount: link.discount || '0' };
            }
        }
        setBusy(row.id);
        router.post(`/commands/prescriptions/${row.id}/dispense`, { branch_id: Number(branchId), payment_method: payment.method, payment_reference: payment.reference || null, [CONFIRM_BELOW_FLOOR]: confirmBelowFloor, ...(Object.keys(links).length > 0 ? { links } : {}) }, {
            preserveScroll: true,
            onError: (errs) => {
                // Only the owner gets this question; the server blocks everyone else outright.
                const { [CONFIRM_BELOW_FLOOR]: warning, ...others } = errs as Record<string, string>;
                setErrors(others);
                if (warning && window.confirm(warning)) {
                    window.setTimeout(() => dispense(row, true), 0);
                }
            },
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(null),
        });
    };

    return (
        <section className="panel">
            <div className="panel-heading"><h2 className="ops-row is-inline"><FileText size={18} /> {translate('prescription', 'rx_list')}</h2></div>
            <FormErrors errors={errors} />
            {data.prescriptions.length === 0 ? (
                <Empty title={translate('prescription', 'rx_empty')} body={translate('prescription', 'rx_empty_body')} />
            ) : (
                <ul className="rx-list">
                    {data.prescriptions.map((row) => (
                        <li key={row.id} className="rx-card">
                            <div className="rx-card-head">
                                <div>
                                    <b>{row.number}</b>{row.patient ? ` · ${row.patient}` : ''}
                                    <small className="cell-sub">
                                        {date(row.prescribed_on)}
                                        {row.diagnosis ? ` · ${row.diagnosis}` : ''}
                                    </small>
                                </div>
                                <span className={`rx-status is-${row.status}`}>{translate('prescription', `rx_status_${row.status}`)}</span>
                            </div>
                            {row.lines.length > 0 && (
                                <ol className="rx-card-lines">
                                    {row.lines.map((line, index) => (
                                        <li key={index}>{line.medicine_name}{line.strength ? ` ${line.strength}` : ''}{row.kind === 'clinic' && !line.company_product ? '' : line.company_product ? ` × ${number(line.quantity)}` : ''}<small>{[line.dose_summary, line.dose, line.frequency, line.duration].filter(Boolean).join(' · ')}</small></li>
                                    ))}
                                </ol>
                            )}
                            {row.investigations.length > 0 && <small><b>{translate('prescription', 'rx_tests')}:</b> {row.investigations.join(', ')}</small>}
                            {row.foods_allowed.length > 0 && <small><b>{translate('prescription', 'rx_foods_allowed')}:</b> {row.foods_allowed.join(', ')}</small>}
                            {row.foods_avoided.length > 0 && <small><b>{translate('prescription', 'rx_foods_avoided')}:</b> {row.foods_avoided.join(', ')}</small>}
                            <div className="rx-card-foot">
                                {row.lines.length > 0
                                    ? <span>{translate('prescription', 'rx_total')}: <b>{money(row.total)}</b>{Number(row.discount_total) > 0 ? ` (${translate('prescription', 'rx_discount_total')} ${money(row.discount_total)})` : ''}</span>
                                    : <span />}
                                {row.status === 'dispensed' && row.kind === 'clinic' && (
                                    <small className="muted">
                                        {translate('prescription', 'report_manufacturer')}: {money(row.manufacturer_share)} · {translate('prescription', 'report_clinic')}: {money(row.clinic_fund)}
                                        {' · '}{translate('prescription', 'payment_method')}: {translate('prescription', `payment_${row.payment_method}`)}{row.payment_reference ? ` (${translate('prescription', 'payment_ref_short')} ${row.payment_reference})` : ''}
                                    </small>
                                )}
                                <span className="rx-actions">
                                    <a className="button small" href={`${base}/prescriptions/${row.id}/print?lang=bn`} target="_blank" rel="noopener"><Printer size={15} /> {translate('prescription', 'rx_print_bn')}</a>
                                    <a className="button small" href={`${base}/prescriptions/${row.id}/print?lang=en`} target="_blank" rel="noopener"><Printer size={15} /> {translate('prescription', 'rx_print_en')}</a>
                                    <a className="button small" href={`${base}/prescriptions/${row.id}/print.pdf?lang=${document.documentElement.lang.startsWith('bn') ? 'bn' : 'en'}`} target="_blank" rel="noopener"><Printer size={15} /> {translate('prescription_notepad', 'print_pdf')}</a>
                                    <button type="button" className="button small" onClick={() => onCopy(row)}><Repeat size={15} /> {translate('prescription_notepad', 'copy')}</button>
                                    {row.own && row.lines.length > 0 && <SaveAsTemplate prescriptionId={row.id} translate={translate} />}
                                </span>
                            </div>
                            {data.mode === 'clinic' && row.kind === 'clinic' && row.own && row.status === 'issued' && row.lines.length > 0 && (
                                <div className="rx-dispense">
                                    {row.lines.some((line) => line.product_id === null) && (
                                        <div className="pad-link">
                                            <b>{translate('prescription_notepad', 'link_title')}</b>
                                            <small className="muted">{translate('prescription_notepad', 'link_help')}</small>
                                            {row.lines.filter((line) => line.product_id === null).map((line) => {
                                                const link = linkOf(line.id);
                                                return (
                                                    <div key={line.id} className="pad-link-row">
                                                        <span className="pad-link-name">{line.medicine_name}</span>
                                                        <label>{translate('prescription_notepad', 'link_product')}
                                                            <SearchSelect
                                                                value={link.product_id}
                                                                placeholder={translate('prescription_notepad', 'link_choose')}
                                                                options={productOptions}
                                                                onChange={(id) => {
                                                                    const product = (data.products ?? []).find((item) => String(item.id) === id);
                                                                    setLink(line.id, { product_id: id, unit_price: link.unit_price || (product ? String(product.mrp) : '') });
                                                                }}
                                                            />
                                                        </label>
                                                        {link.product_id !== '' && (
                                                            <>
                                                                <label>{translate('prescription_notepad', 'link_qty')}<input type="number" inputMode="numeric" min="1" step="1" value={link.quantity} onChange={(e) => setLink(line.id, { quantity: e.target.value })} /></label>
                                                                <label>{translate('prescription_notepad', 'link_price')}<input type="number" inputMode="decimal" min="0" step="0.01" value={link.unit_price} onChange={(e) => setLink(line.id, { unit_price: e.target.value })} /></label>
                                                                <label>{translate('prescription_notepad', 'link_discount')}<input type="number" inputMode="decimal" min="0" step="0.01" value={link.discount} onChange={(e) => setLink(line.id, { discount: e.target.value })} /></label>
                                                            </>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                    <label>{translate('prescription', 'dispense_branch')}
                                        <SearchSelect
                                            value={branchFor[row.id] ?? String(branches[0]?.id ?? '')}
                                            options={branches.map((branch) => ({ value: String(branch.id), label: `${branch.code} — ${branch.name}` }))}
                                            onChange={(id) => setBranchFor({ ...branchFor, [row.id]: id })}
                                        />
                                    </label>
                                    <label>{translate('prescription', 'payment_method')}
                                        <select value={paymentOf(row.id).method} onChange={(e) => setPayment(row.id, { method: e.target.value })}>
                                            {paymentMethods.map((method) => <option key={method} value={method}>{translate('prescription', `payment_${method}`)}</option>)}
                                        </select>
                                    </label>
                                    <label>{translate('prescription', 'payment_reference')}
                                        <input maxLength={80} value={paymentOf(row.id).reference} onChange={(e) => setPayment(row.id, { reference: e.target.value })} />
                                    </label>
                                    <button type="button" className="button dark" disabled={busy === row.id || branches.length === 0} onClick={() => dispense(row)}>{translate('prescription', 'dispense')}</button>
                                    <small className="muted">{translate('prescription', branches.length === 0 ? 'dispense_no_branch' : 'dispense_help')}</small>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Pagination page={data.prescriptions_page} />
        </section>
    );
}

function PatientsTab({ data, translate }: { data: PrescriptionsData; translate: Translate }) {
    const { portalBase, filters } = usePage<any>().props;
    const [editing, setEditing] = useState<PatientRow | null>(null);
    // A patient is normally added while writing their prescription; this form is for the other
    // cases, such as giving a serial to someone who has no prescription yet.
    const [adding, setAdding] = useState(false);
    const [query, setQuery] = useState(String(filters?.q ?? ''));
    const form = useForm<PatientForm>(blankPatient());

    useEffect(() => {
        if (editing) {
            form.setData({
                name: editing.name, age: editing.age === null ? '' : String(editing.age), sex: editing.sex ?? '',
                phone: editing.phone ?? '', address_text: editing.address_text ?? editing.place ?? '', notes: editing.notes ?? '', allergies: editing.allergies ?? '', patient_consent: false,
            });
        } else {
            form.setData(blankPatient());
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);

    const closeForm = () => { setEditing(null); setAdding(false); form.clearErrors(); };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => patientForServer(values));
        const options = { preserveScroll: true, onSuccess: () => { closeForm(); form.reset(); } };
        if (editing) {
            form.patch(`/commands/prescriptions/patients/${editing.id}`, options);
        } else {
            form.post('/commands/prescriptions/patients', options);
        }
    };

    return (
        <>
            {(editing || adding) && (
                <section className="panel">
                    <div className="panel-heading"><h2 className="ops-row is-inline"><UserPlus size={18} /> {translate('prescription', editing ? 'patient_edit' : 'patient_new')}</h2></div>
                    <form className="action-form rx-form" onSubmit={submit} noValidate>
                        <PatientFields
                            value={form.data}
                            onChange={(patch) => form.setData({ ...form.data, ...patch })}
                            sexes={data.sexes}
                            askConsent={!editing || !editing.consent_at}
                            consentRequired={!editing}
                            translate={translate}
                        />
                        <FormErrors errors={form.errors} />
                        <div className="rx-actions">
                            <button className="button dark" disabled={form.processing}>{translate('prescription', 'patient_save')}</button>
                            <button type="button" className="button small" onClick={closeForm}>{translate('portal', 'cancel')}</button>
                        </div>
                    </form>
                </section>
            )}
            <section className="panel">
                <div className="panel-heading">
                    <h2>{translate('prescription', 'patients')}</h2>
                    {!editing && !adding && (
                        <button type="button" className="button small" onClick={() => setAdding(true)}><UserPlus size={15} /> {translate('prescription', 'patient_add_button')}</button>
                    )}
                    <form className="rx-search" onSubmit={(e) => { e.preventDefault(); router.get(`${portalBase ?? ''}/prescriptions`, query ? { q: query, tab: 'patients' } : { tab: 'patients' }, { preserveState: true, preserveScroll: true }); }}>
                        <input type="search" value={query} placeholder={translate('prescription', 'patient_search')} aria-label={translate('prescription', 'patient_search')} onChange={(e) => setQuery(e.target.value)} />
                        <button className="icon-button" aria-label={translate('prescription', 'patient_search')}><Search size={16} /></button>
                    </form>
                </div>
                {data.patients.length === 0 ? (
                    <Empty title={translate('prescription', 'patients_empty')} body={translate('prescription', 'patients_empty_body')} />
                ) : (
                    <ul className="rx-list">
                        {data.patients.map((patient) => (
                            <li key={patient.id} className="rx-card rx-patient">
                                <div>
                                    <b>{patient.name}</b>
                                    <small className="cell-sub">
                                        {[patient.age !== null ? translate('prescription', 'print_years', { age: patient.age }) : null, patient.sex ? translate('prescription', `sex_${patient.sex}`) : null, patient.phone, patient.address_text ?? patient.place].filter(Boolean).join(' · ')}
                                    </small>
                                    {patient.allergies && <small className="cell-sub is-bad"><AlertTriangle size={13} aria-hidden="true" /> {translate('clinic_records', 'allergies')}: {patient.allergies}</small>}
                                    {patient.notes && <small className="cell-sub">{patient.notes}</small>}
                                    <small className="cell-sub">{patient.consent_at ? translate('prescriber_public', 'patient_consent_on_file', { date: date(patient.consent_at) }) : translate('prescriber_public', 'patient_consent_missing')}</small>
                                </div>
                                <span className="rx-actions">
                                    <Link className="button small" href={`${portalBase ?? ''}/prescriptions/patients/${patient.id}`}><History size={15} /> {translate('clinic_records', 'open_record')}</Link>
                                    <button type="button" className="button small" onClick={() => { setEditing(patient); window.scrollTo({ top: 0, behavior: 'smooth' }); }}>{translate('prescription', 'patient_edit')}</button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination page={data.patients_page} />
            </section>
        </>
    );
}

function ReportTab({ report, translate }: { report: ReportData; translate: Translate }) {
    const { portalBase } = usePage<any>().props;
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);

    return (
        <section className="panel">
            <div className="panel-heading"><h2>{translate('prescription', 'report_title')}</h2></div>
            <form className="rx-report-filter" onSubmit={(e) => { e.preventDefault(); router.get(`${portalBase ?? ''}/prescriptions`, { from, to, tab: 'report' }, { preserveScroll: true }); }}>
                <label>{translate('prescription', 'report_from')}<DateInput value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                <label>{translate('prescription', 'report_to')}<DateInput value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
                <button className="button dark">{translate('prescription', 'report_apply')}</button>
            </form>
            <div className="rx-stats">
                <div><small>{translate('prescription', 'report_count')}</small><b>{number(report.count)}</b></div>
                <div><small>{translate('prescription', 'report_sales')}</small><b>{money(report.sales)}</b></div>
                <div><small>{translate('prescription', 'report_manufacturer')}</small><b>{money(report.manufacturer_share)}</b></div>
                <div><small>{translate('prescription', 'report_clinic')}</small><b className={Number(report.clinic_fund) < 0 ? 'is-bad' : undefined}>{money(report.clinic_fund)}</b></div>
            </div>
            {report.by_method.length > 0 && (
                <div className="table-wrap">
                    <table className="rx-table">
                        <thead><tr>
                            <th>{translate('prescription', 'report_by_method')}</th>
                            <th className="num">{translate('prescription', 'report_count')}</th>
                            <th className="num">{translate('prescription', 'report_sales')}</th>
                        </tr></thead>
                        <tbody>
                            {report.by_method.map((row) => (
                                <tr key={row.method}>
                                    <td>{translate('prescription', `payment_${row.method}`)}</td>
                                    <td className="num">{row.count}</td>
                                    <td className="num">{money(row.sales)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {report.days.length === 0 ? (
                <Empty title={translate('prescription', 'report_empty')} />
            ) : (
                <div className="table-wrap">
                    <table className="rx-table">
                        <thead><tr>
                            <th>{translate('prescription', 'report_day')}</th>
                            <th className="num">{translate('prescription', 'report_count')}</th>
                            <th className="num">{translate('prescription', 'report_sales')}</th>
                            <th className="num">{translate('prescription', 'report_manufacturer')}</th>
                            <th className="num">{translate('prescription', 'report_clinic')}</th>
                        </tr></thead>
                        <tbody>
                            {report.days.map((day) => (
                                <tr key={day.day}>
                                    <td>{date(day.day)}</td>
                                    <td className="num">{number(day.count)}</td>
                                    <td className="num">{money(day.sales)}</td>
                                    <td className="num">{money(day.manufacturer_share)}</td>
                                    <td className="num">{money(day.clinic_fund)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function SettingsTab({ data, translate }: { data: PrescriptionsData; translate: Translate }) {
    const registration = useForm({
        registration_type: data.registration?.type ?? data.registration_types[0] ?? 'bmdc',
        registration_number: data.registration?.number ?? '',
        note: '',
    });
    const noteBox = useReasonField('clinic.registration_note');

    return (
        <>
            <LetterheadSettings settings={data.letterhead_settings} translate={translate} />
            {data.is_owner && <section className="panel">
                <div className="panel-heading"><div><h2>{translate('prescription', 'registration_title')}</h2><p className="muted">{translate('prescription', data.registration ? 'registration_hint' : 'registration_missing')}</p></div></div>
                <form className="action-form" onSubmit={(e) => { e.preventDefault(); registration.post('/commands/prescriptions/registration', { preserveScroll: true }); }}>
                    <label>{translate('prescription', 'registration_type')}
                        <select value={registration.data.registration_type} onChange={(e) => registration.setData('registration_type', e.target.value)}>
                            {data.registration_types.map((type) => <option key={type} value={type}>{translate('prescription', `type_${type}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('prescription', 'registration_number')}<input required maxLength={60} value={registration.data.registration_number} onChange={(e) => registration.setData('registration_number', e.target.value)} /></label>
                    {noteBox.shown && <label>{noteBox.label(translate('prescription', 'registration_note'))}<input maxLength={1000} required={noteBox.required} value={registration.data.note} onChange={(e) => registration.setData('note', e.target.value)} /></label>}
                    <FormErrors errors={registration.errors} />
                    <button className="button dark" disabled={registration.processing}>{translate('prescription', 'registration_save')}</button>
                </form>
            </section>}
        </>
    );
}
