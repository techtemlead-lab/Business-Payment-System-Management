import React, { useEffect, useMemo, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, FileText, History, Pill, Plus, Printer, Repeat, Search, Stethoscope, Trash2, UserPlus } from 'lucide-react';
import { DateInput, Empty, FormErrors, date, money, number, Pagination, type Row } from './UI';
import SearchSelect from './SearchSelect';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { businessTodayIso, localizeDigits } from '../business-date';
import { linesFromSaved, SaveAsTemplate, TemplatePicker, TemplatesTab, type PrescriptionTools, type TemplateRow } from './PrescriptionTemplates';
import { DietTemplatesSection } from './PrescriptionDietTemplates';
import { ExtrasFields, linesOf } from './PrescriptionExtras';
import { blankPatient, PatientFields, PatientPicker, patientForServer, PatientStep, type PatientForm, type PatientMode, type PatientOption } from './PrescriptionPatientStep';
import { FollowUpsPanel } from './PrescriptionFollowUps';
import { PrescriberReportTab } from './PrescriberReport';
import { ConsultationRequestsTab, PublicListingTab, type PrescriberPublicData } from './PrescriberPublicTabs';
import { ClinicQueueTab } from './ClinicQueueTab';
import { PatientAccessLogList } from './PatientAccessLogList';
import { emptyVitals, VitalsFields, vitalsForServer } from './VitalsFields';

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
    place_code_id: number | null;
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
    lines: { medicine_name: string; strength: string | null; company_product: boolean; dose: string | null; frequency: string | null; duration: string | null; dose_summary: string; quantity: number; line_total: string }[];
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
    dose_amounts: string[];
    food_timings: string[];
    duration_units: string[];
    products: { id: number; name: string; sku: string; mrp: string }[];
    /** The owners' switch: may a medicine that is not a company product be typed by hand? */
    other_medicines_allowed: boolean;
    /** Only for owners, who can change the switch. */
    other_medicines?: { enabled: boolean; version: number };
    branches?: { id: number; code: string; name: string }[];
    payment_methods?: string[];
    manufacturer_percent?: string;
    report?: ReportData;
};

export type LineForm = {
    /** A company product, or another medicine typed by hand while the owners allow it. */
    kind: 'product' | 'other';
    product_id: string;
    medicine_name: string;
    strength: string;
    dose: string;
    frequency: string;
    duration: string;
    instructions: string;
    dose_schedule: string;
    food_timing: string;
    duration_count: string;
    duration_unit: string;
    unit_price: string;
    quantity: string;
    discount: string;
};

const emptyLine = (): LineForm => ({ kind: 'product', product_id: '', medicine_name: '', strength: '', dose: '', frequency: '', duration: '', instructions: '', dose_schedule: '', food_timing: '', duration_count: '', duration_unit: 'day', unit_price: '', quantity: '1', discount: '' });

/** The dosing fields of one line as the server expects them; an unfinished duration is left out. */
const dosingFields = (line: LineForm) => ({
    dose: line.dose,
    frequency: line.frequency,
    duration: line.duration,
    instructions: line.instructions,
    dose_schedule: line.dose_schedule || null,
    food_timing: line.food_timing || null,
    duration_count: line.duration_count === '' ? null : Number(line.duration_count),
    // A line filled from a template or an old prescription may have no unit yet; the dropdown shows days.
    duration_unit: line.duration_count === '' ? null : (line.duration_unit || 'day'),
});

/** Morning, noon and night amounts, such as 1+0+1, with the other structured dosing beside them. */
function StructuredDosing({ data, line, onChange, translate }: { data: PrescriptionsData; line: LineForm; onChange: (patch: Partial<LineForm>) => void; translate: Translate }) {
    const parts = line.dose_schedule === '' ? ['', '', ''] : line.dose_schedule.split('+');
    const setPart = (slot: number, value: string) => {
        const next = parts.map((part, index) => (index === slot ? value : part));
        onChange({ dose_schedule: next.every((part) => part === '') ? '' : next.map((part) => part || '0').join('+') });
    };

    return (
        <div className="rx-dosing">
            {['morning', 'noon', 'night'].map((slot, index) => (
                <label key={slot}>{translate('clinic_records', `slot_${slot}`)}
                    <select value={parts[index] ?? ''} onChange={(e) => setPart(index, e.target.value)}>
                        <option value="">–</option>
                        {data.dose_amounts.map((amount) => <option key={amount} value={amount}>{localizeDigits(amount)}</option>)}
                    </select>
                </label>
            ))}
            <label>{translate('clinic_records', 'food_label')}
                <select value={line.food_timing} onChange={(e) => onChange({ food_timing: e.target.value })}>
                    <option value="">–</option>
                    {data.food_timings.map((timing) => <option key={timing} value={timing}>{translate('clinic_records', `food_${timing}`)}</option>)}
                </select>
            </label>
            <label>{translate('clinic_records', 'course_length')}
                <input type="number" inputMode="numeric" min="1" max="365" step="1" value={line.duration_count} onChange={(e) => onChange({ duration_count: e.target.value })} />
            </label>
            <label>{translate('clinic_records', 'course_unit')}
                <select value={line.duration_unit || 'day'} onChange={(e) => onChange({ duration_unit: e.target.value })}>
                    {data.duration_units.map((unit) => <option key={unit} value={unit}>{translate('clinic_records', `unit_${unit}`)}</option>)}
                </select>
            </label>
        </div>
    );
}

/** Taka to paisa, so totals add up exactly like the server's. */
const paisa = (value: string | number): number => Math.round((Number(value) || 0) * 100);
const taka = (cents: number): string => (cents / 100).toFixed(2);

function lineCents(line: LineForm): { gross: number; discount: number; total: number } {
    const gross = paisa(line.unit_price) * Math.max(0, Math.floor(Number(line.quantity) || 0));
    const discount = paisa(line.discount);
    return { gross, discount, total: gross - discount };
}

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
        ...(data.is_owner || data.other_medicines ? [{ id: 'settings', label: translate('prescription', 'tab_settings') }] : []),
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
            {activeTab === 'settings' && (data.is_owner || data.other_medicines) && <SettingsTab data={data} translate={translate} />}
        </div>
    );
}

function PrescriptionsTab({ data, tools, translate }: { data: PrescriptionsData; tools: PrescriptionTools; translate: Translate }) {
    const clinic = data.mode === 'clinic';
    const products = data.products ?? [];
    const percent = Number(data.manufacturer_percent ?? '30') / 100;
    const form = useForm({
        patient_id: '',
        new_patient: blankPatient(),
        prescribed_on: businessTodayIso(),
        complaints: '',
        diagnosis: '',
        advice: '',
        follow_up_on: '',
        vitals: emptyVitals(),
        investigations: '',
        foods_allowed: '',
        foods_avoided: '',
        lines: [emptyLine()] as LineForm[],
    });
    // A prescriber with no patient yet starts on "new patient"; everyone else on the list of their own.
    const [patientMode, setPatientMode] = useState<PatientMode>(data.own_patients.length > 0 ? 'old' : 'new');
    const [newPatientFolded, setNewPatientFolded] = useState(false);
    const [chosenPatient, setChosenPatient] = useState<PatientOption | null>(null);
    const oldPatientChosen = patientMode === 'old' && form.data.patient_id !== '';
    // The writing area opens once the prescriber has said who the prescription is for.
    const patientReady = oldPatientChosen || (patientMode === 'new' && newPatientFolded);
    const patientAllergies = oldPatientChosen && chosenPatient && String(chosenPatient.id) === form.data.patient_id ? chosenPatient.allergies : null;
    const [repeatNote, setRepeatNote] = useState<string | null>(null);
    const lines = form.data.lines;
    const totals = useMemo(() => lines.reduce((sum, line) => {
        const cents = lineCents(line);
        return { gross: sum.gross + cents.gross, discount: sum.discount + cents.discount };
    }, { gross: 0, discount: 0 }), [lines]);

    const setLine = (index: number, patch: Partial<LineForm>) => {
        form.setData('lines', lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    };
    const productMrp = (id: string): number => paisa(products.find((product) => String(product.id) === id)?.mrp ?? 0);
    const productOptions = useMemo(() => products.map((product) => ({
        value: String(product.id),
        label: `${product.name} — ${translate('prescription', 'rx_mrp')} ${money(product.mrp)}`,
        keywords: product.sku,
    })), [products, translate]);
    const belowFloor = (line: LineForm): boolean => clinic && line.product_id !== ''
        && lineCents(line).total < Math.round(productMrp(line.product_id) * Math.max(1, Number(line.quantity) || 0) * percent);
    const error = (key: string) => (form.errors as Record<string, string>)[key];

    /** Fills the form from the patient's newest prescription; nothing is saved until the form is. */
    const repeatLast = async (patientId: string, askFirst: boolean) => {
        const response = await fetch(`/commands/prescriptions/patients/${patientId}/repeat`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
        if (!response?.ok) {
            setRepeatNote(translate('clinic_records', 'repeat_failed'));
            return;
        }
        const json = await response.json() as { patient: PatientOption; draft: { number: string; diagnosis: string | null; advice: string | null; foods_allowed?: string[]; foods_avoided?: string[]; lines: TemplateRow['lines'] } | null };
        setChosenPatient(json.patient);
        setPatientMode('old');
        if (json.draft === null) {
            form.setData('patient_id', String(json.patient.id));
            setRepeatNote(translate('clinic_records', 'repeat_none'));
            return;
        }
        if (askFirst && lines.some((line) => line.product_id !== '' || line.medicine_name !== '') && !window.confirm(translate('prescription', 'tpl_replace_confirm'))) {
            return;
        }
        const { lines: repeated, productMissing, otherDropped } = linesFromSaved(json.draft.lines, products, data.other_medicines_allowed);
        form.setData({
            ...form.data,
            patient_id: String(json.patient.id),
            diagnosis: json.draft.diagnosis ?? '',
            advice: json.draft.advice ?? '',
            // The diet usually goes on; tests belong to one visit, so they start empty.
            foods_allowed: (json.draft.foods_allowed ?? []).join('\n'),
            foods_avoided: (json.draft.foods_avoided ?? []).join('\n'),
            lines: repeated,
        });
        setRepeatNote(`${translate('clinic_records', 'repeat_filled', { number: json.draft.number })}${productMissing ? ` ${translate('prescription', 'tpl_product_gone')}` : ''}${otherDropped ? ` ${translate('prescription', 'rx_other_dropped')}` : ''}`);
    };

    useEffect(() => {
        const patientId = new URLSearchParams(window.location.search).get('repeat');
        if (patientId && /^\d+$/.test(patientId)) {
            void repeatLast(patientId, false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const send = (confirmBelowFloor: boolean) => {
        form.transform((values) => ({
            ...values,
            [CONFIRM_BELOW_FLOOR]: confirmBelowFloor,
            // One or the other: an old patient by id, or a new patient saved by this same request.
            patient_id: patientMode === 'old' ? values.patient_id : null,
            new_patient: patientMode === 'new' ? patientForServer(values.new_patient) : null,
            follow_up_on: values.follow_up_on || null,
            vitals: vitalsForServer(values.vitals),
            investigations: linesOf(values.investigations),
            foods_allowed: linesOf(values.foods_allowed),
            foods_avoided: linesOf(values.foods_avoided),
            lines: values.lines.map((line) => {
                const priced = { unit_price: line.unit_price || '0', quantity: line.quantity || '1', discount: line.discount || '0' };
                return line.kind === 'product'
                    ? { product_id: Number(line.product_id) || null, ...dosingFields(line), ...priced }
                    // In the clinic another medicine is bought elsewhere; the server prices it at nothing.
                    : { medicine_name: line.medicine_name, strength: line.strength || null, ...dosingFields(line), ...(clinic ? { unit_price: '0', quantity: '1', discount: '0' } : priced) };
            }),
        }));
        form.post('/commands/prescriptions', {
            preserveScroll: true,
            // A patient saved with this prescription is an old patient from now on.
            onSuccess: () => { form.reset(); setChosenPatient(null); setRepeatNote(null); setPatientMode('old'); setNewPatientFolded(false); },
            onError: (errors) => {
                if (Object.keys(errors).some((key) => key.startsWith('new_patient'))) {
                    // The message belongs to a patient box, so the folded panel opens again to show it.
                    setNewPatientFolded(false);
                }
                // Only the owner gets this question; the server blocks everyone else outright.
                const warning = (errors as Record<string, string>)[CONFIRM_BELOW_FLOOR];
                if (warning && window.confirm(warning)) {
                    window.setTimeout(() => send(true), 0);
                }
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (!patientReady) {
            // Enter inside the patient step must not send a prescription that has no patient yet.
            return;
        }
        send(false);
    };

    return (
        <>
            <section className="panel">
                <div className="panel-heading"><h2 className="ops-row is-inline"><Plus size={18} /> {translate('prescription', 'rx_new')}</h2></div>
                <form className="action-form rx-form" onSubmit={submit}>
                    <PatientStep
                        mode={patientMode}
                        onMode={(mode) => { setPatientMode(mode); setRepeatNote(null); }}
                        ownPatients={data.own_patients}
                        patientId={form.data.patient_id}
                        chosen={chosenPatient}
                        onPick={(id, patient) => { setChosenPatient(patient); setRepeatNote(null); form.setData('patient_id', id); }}
                        newPatient={form.data.new_patient}
                        onNewPatient={(patch: Partial<PatientForm>) => form.setData('new_patient', { ...form.data.new_patient, ...patch })}
                        folded={newPatientFolded}
                        onFolded={setNewPatientFolded}
                        sexes={data.sexes}
                        errors={form.errors as Record<string, string>}
                        translate={translate}
                    />
                    {!patientReady && <p className="muted rx-note">{translate('prescription', 'patient_pick_first')}</p>}
                    {patientReady && <div className="rx-writing">
                        <TemplatePicker
                            templates={tools.templates}
                            products={products}
                            otherAllowed={data.other_medicines_allowed}
                            isDirty={lines.some((line) => line.product_id !== '' || line.medicine_name !== '') || form.data.advice !== ''}
                            onApply={(draft) => form.setData({ ...form.data, advice: draft.advice, lines: draft.lines })}
                            translate={translate}
                        />
                        <div className="rx-grid">
                            <label>{translate('prescription', 'rx_date')}
                                <DateInput required value={form.data.prescribed_on} onChange={(e) => form.setData('prescribed_on', e.target.value)} />
                            </label>
                        </div>
                        {oldPatientChosen && (
                            <div className="rx-actions">
                                <button type="button" className="button small" onClick={() => void repeatLast(form.data.patient_id, true)}><Repeat size={15} /> {translate('clinic_records', 'repeat_last')}</button>
                                {repeatNote && <small className="muted">{repeatNote}</small>}
                            </div>
                        )}
                        {patientAllergies && (
                            <div className="rx-allergy" role="alert">
                                <AlertTriangle size={20} aria-hidden="true" />
                                <div>
                                    <b>{translate('clinic_records', 'allergy_warning_title')}</b>
                                    <p>{patientAllergies}</p>
                                    <small>{translate('clinic_records', 'allergy_warning_body')}</small>
                                </div>
                            </div>
                        )}
                        <div className="rx-grid">
                            <label>{translate('prescription', 'rx_complaints')}
                                <textarea rows={2} value={form.data.complaints} onChange={(e) => form.setData('complaints', e.target.value)} />
                            </label>
                            <label>{translate('prescription', 'rx_diagnosis')}
                                <textarea rows={2} value={form.data.diagnosis} onChange={(e) => form.setData('diagnosis', e.target.value)} />
                            </label>
                        </div>
                        <VitalsFields value={form.data.vitals} errors={form.errors as Record<string, string>} onChange={(next) => form.setData('vitals', next)} translate={translate} />
                        <fieldset className="rx-lines">
                            <legend><Pill size={16} /> {translate('prescription', 'rx_lines')}</legend>
                            {!clinic && <p className="muted rx-note">{translate('prescription', 'rx_no_stock_note')}</p>}
                            {lines.map((line, index) => {
                                const cents = lineCents(line);
                                // While the switch is off a line saved as another medicine still shows, so it can be changed.
                                const other = line.kind === 'other';
                                const unpriced = clinic && other;
                                return (
                                    <div className="rx-line" key={index}>
                                        {(data.other_medicines_allowed || other) && (
                                            <div className="rx-kind" role="group" aria-label={translate('prescription', 'rx_lines')}>
                                                <button type="button" aria-pressed={!other} onClick={() => setLine(index, { kind: 'product', medicine_name: '', strength: '' })}>{translate('prescription', 'rx_kind_product')}</button>
                                                {data.other_medicines_allowed && (
                                                    <button type="button" aria-pressed={other} onClick={() => setLine(index, { kind: 'other', product_id: '', ...(clinic ? { unit_price: '', discount: '' } : {}) })}>{translate('prescription', 'rx_kind_other')}</button>
                                                )}
                                            </div>
                                        )}
                                        <div className="rx-line-head">
                                            <b>{number(index + 1)}.</b>
                                            {!other ? (
                                                <label className="rx-wide">{translate('prescription', 'rx_product')}
                                                    <SearchSelect
                                                        required
                                                        value={line.product_id}
                                                        placeholder={translate('prescription', 'rx_choose_product')}
                                                        options={productOptions}
                                                        onChange={(id) => {
                                                            const product = products.find((item) => String(item.id) === id);
                                                            setLine(index, { product_id: id, unit_price: line.unit_price || (product ? String(product.mrp) : '') });
                                                        }}
                                                    />
                                                </label>
                                            ) : (
                                                <>
                                                    <label className="rx-wide">{translate('prescription', 'rx_medicine')}
                                                        <input required maxLength={160} value={line.medicine_name} onChange={(e) => setLine(index, { medicine_name: e.target.value })} />
                                                    </label>
                                                    <label>{translate('prescription', 'rx_strength')}
                                                        <input maxLength={80} value={line.strength} placeholder={translate('prescription', 'rx_strength_hint')} onChange={(e) => setLine(index, { strength: e.target.value })} />
                                                    </label>
                                                </>
                                            )}
                                            <button type="button" className="icon-button" aria-label={translate('prescription', 'rx_remove_line')} onClick={() => form.setData('lines', lines.filter((_, i) => i !== index))}>
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                        {unpriced && <p className="muted rx-note">{translate('prescription', 'rx_other_clinic_note')}</p>}
                                        <StructuredDosing data={data} line={line} onChange={(patch) => setLine(index, patch)} translate={translate} />
                                        <div className="rx-line-grid">
                                            <label>{translate('prescription', 'rx_dose')}<input maxLength={80} value={line.dose} onChange={(e) => setLine(index, { dose: e.target.value })} /></label>
                                            <label>{translate('prescription', 'rx_frequency')}<input maxLength={80} value={line.frequency} onChange={(e) => setLine(index, { frequency: e.target.value })} /></label>
                                            <label>{translate('prescription', 'rx_duration')}<input maxLength={80} value={line.duration} onChange={(e) => setLine(index, { duration: e.target.value })} /></label>
                                            <label className="rx-wide">{translate('prescription', 'rx_instructions')}<input maxLength={300} value={line.instructions} onChange={(e) => setLine(index, { instructions: e.target.value })} /></label>
                                            {!unpriced && <>
                                                <label>{translate('prescription', 'rx_unit_price')}<input type="number" inputMode="decimal" min="0" step="0.01" required value={line.unit_price} onChange={(e) => setLine(index, { unit_price: e.target.value })} /></label>
                                                <label>{translate('prescription', 'rx_quantity')}<input type="number" inputMode="numeric" min="1" step="1" required value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} /></label>
                                                <label>{translate('prescription', 'rx_discount')}<input type="number" inputMode="decimal" min="0" step="0.01" value={line.discount} onChange={(e) => setLine(index, { discount: e.target.value })} /></label>
                                                <div className="rx-line-total">
                                                    <small>{translate('prescription', 'rx_line_total')}</small>
                                                    <b className={cents.total < 0 ? 'is-bad' : undefined}>{money(taka(Math.max(0, cents.total)))}</b>
                                                    {belowFloor(line) && <span className="rx-floor is-bad">{translate('prescription', 'rx_below_floor_warn')}</span>}
                                                </div>
                                            </>}
                                        </div>
                                        {Object.entries(form.errors).filter(([key]) => key.startsWith(`lines.${index}.`)).map(([key, message]) => <span key={key} className="field-error" role="alert">{String(message)}</span>)}
                                    </div>
                                );
                            })}
                            {lines.length === 0 && <p className="muted rx-note">{translate('prescription', 'rx_no_medicine')}</p>}
                            {lines.length < data.max_lines && (
                                <button type="button" className="button small" onClick={() => form.setData('lines', [...lines, emptyLine()])}><Plus size={16} /> {translate('prescription', 'rx_add_line')}</button>
                            )}
                            {lines.length > 0 && (
                                <dl className="rx-totals">
                                    <div><dt>{translate('prescription', 'rx_subtotal')}</dt><dd>{money(taka(totals.gross))}</dd></div>
                                    <div><dt>{translate('prescription', 'rx_discount_total')}</dt><dd>− {money(taka(totals.discount))}</dd></div>
                                    <div className="is-grand"><dt>{translate('prescription', 'rx_total')}</dt><dd>{money(taka(Math.max(0, totals.gross - totals.discount)))}</dd></div>
                                </dl>
                            )}
                        </fieldset>
                        <ExtrasFields
                            value={{ investigations: form.data.investigations, foods_allowed: form.data.foods_allowed, foods_avoided: form.data.foods_avoided }}
                            onChange={(patch) => form.setData({ ...form.data, ...patch })}
                            dietTemplates={tools.diet_templates}
                            errors={form.errors as Record<string, string>}
                            translate={translate}
                        />
                        <div className="rx-grid">
                            <label>{translate('prescription', 'rx_advice')}
                                <textarea rows={2} value={form.data.advice} onChange={(e) => form.setData('advice', e.target.value)} />
                            </label>
                            <label>{translate('prescription', 'rx_follow_up')}
                                <DateInput value={form.data.follow_up_on} min={form.data.prescribed_on} onChange={(e) => form.setData('follow_up_on', e.target.value)} />
                            </label>
                        </div>
                        {error('lines') && <div className="error" role="alert">{error('lines')}</div>}
                        <button className="button dark" disabled={form.processing}>{form.processing ? translate('prescription', 'rx_saving') : translate('prescription', 'rx_save')}</button>
                    </div>}
                </form>
            </section>
            <PrescriptionList data={data} translate={translate} />
        </>
    );
}

function PrescriptionList({ data, translate }: { data: PrescriptionsData; translate: Translate }) {
    const { portalBase } = usePage<any>().props;
    const base = String(portalBase ?? '');
    const branches = data.branches ?? [];
    const paymentMethods = data.payment_methods ?? [];
    const [branchFor, setBranchFor] = useState<Record<number, string>>({});
    const [paymentFor, setPaymentFor] = useState<Record<number, { method: string; reference: string }>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<number | null>(null);
    const paymentOf = (id: number) => paymentFor[id] ?? { method: 'cash', reference: '' };
    const setPayment = (id: number, patch: Partial<{ method: string; reference: string }>) => setPaymentFor({ ...paymentFor, [id]: { ...paymentOf(id), ...patch } });

    const dispense = (row: PrescriptionRow, confirmBelowFloor = false) => {
        const branchId = branchFor[row.id] ?? String(branches[0]?.id ?? '');
        if (!branchId || (!confirmBelowFloor && !window.confirm(translate('prescription', 'dispense_confirm')))) {
            return;
        }
        const payment = paymentOf(row.id);
        setBusy(row.id);
        router.post(`/commands/prescriptions/${row.id}/dispense`, { branch_id: Number(branchId), payment_method: payment.method, payment_reference: payment.reference || null, [CONFIRM_BELOW_FLOOR]: confirmBelowFloor }, {
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
                                        <li key={index}>{line.medicine_name}{line.strength ? ` ${line.strength}` : ''}{row.kind === 'clinic' && !line.company_product ? '' : ` × ${number(line.quantity)}`}<small>{[line.dose_summary, line.dose, line.frequency, line.duration].filter(Boolean).join(' · ')}</small></li>
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
                                    {row.own && row.lines.length > 0 && <SaveAsTemplate prescriptionId={row.id} translate={translate} />}
                                </span>
                            </div>
                            {data.mode === 'clinic' && row.kind === 'clinic' && row.own && row.status === 'issued' && row.lines.length > 0 && (
                                <div className="rx-dispense">
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
                phone: editing.phone ?? '', place_code_id: editing.place_code_id ? String(editing.place_code_id) : '', notes: editing.notes ?? '', allergies: editing.allergies ?? '', patient_consent: false,
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
                    <form className="action-form rx-form" onSubmit={submit}>
                        <PatientFields
                            value={form.data}
                            onChange={(patch) => form.setData({ ...form.data, ...patch })}
                            sexes={data.sexes}
                            currentPlace={editing?.place}
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
                                        {[patient.age !== null ? translate('prescription', 'print_years', { age: patient.age }) : null, patient.sex ? translate('prescription', `sex_${patient.sex}`) : null, patient.phone, patient.place].filter(Boolean).join(' · ')}
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
            {data.other_medicines && <OtherMedicinesSwitch setting={data.other_medicines} translate={translate} />}
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

/** The owners' switch: may a medicine that is not a company product be typed on a prescription? */
function OtherMedicinesSwitch({ setting, translate }: { setting: { enabled: boolean; version: number }; translate: Translate }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const change = () => {
        const next = !setting.enabled;
        if (!window.confirm(translate('prescription', next ? 'other_confirm_on' : 'other_confirm_off'))) {
            return;
        }
        setBusy(true);
        router.post('/commands/owner/prescription-other-medicines', { enabled: next ? '1' : '0', version: setting.version }, {
            preserveScroll: true,
            onSuccess: () => setError(null),
            onError: (errors) => setError(String(Object.values(errors)[0] ?? '')),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <section className="panel">
            <div className="panel-heading"><div><h2>{translate('prescription', 'other_title')}</h2><p className="muted">{translate('prescription', 'other_intro')}</p></div></div>
            <div className="rx-switch">
                <b className={setting.enabled ? 'is-on' : 'is-off'}>{translate('prescription', setting.enabled ? 'other_state_on' : 'other_state_off')}</b>
                <button type="button" className={`button ${setting.enabled ? '' : 'dark'}`} disabled={busy} onClick={change}>
                    {translate('prescription', setting.enabled ? 'other_turn_off' : 'other_turn_on')}
                </button>
            </div>
            {error && <span className="field-error" role="alert">{error}</span>}
        </section>
    );
}
