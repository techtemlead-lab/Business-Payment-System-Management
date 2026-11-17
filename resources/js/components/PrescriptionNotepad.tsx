import { useEffect, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, ChevronDown, ChevronUp, Plus, Repeat, Trash2 } from 'lucide-react';
import { DateInput } from './UI';
import { blankPatient, patientForServer, PatientPicker, type PatientOption } from './PrescriptionPatientStep';
import { linesOf } from './PrescriptionExtras';
import { TemplatePicker, type PrescriptionTools, type TemplateRow } from './PrescriptionTemplates';
import { useT } from '../shared/i18n/t';
import { isBanglaDocument, localizeDigits } from '../business-date';

type Translate = ReturnType<typeof useT>;

/** The letterhead keys this screen shows; the full set is owned by the letterhead settings. */
export type Letterhead = Partial<Record<
    'doctor_name_bn' | 'doctor_name_en' | 'degrees_bn' | 'degrees_en' | 'specialities_bn' | 'specialities_en'
    | 'registration_bn' | 'registration_en' | 'chamber_bn' | 'chamber_en', string | null
>> & { logo_url?: string | null };

export type NotepadData = {
    mode: 'partner' | 'clinic';
    own_patients: PatientOption[];
    sexes: string[];
    max_lines: number;
    letterhead: Letterhead | null;
    doctor_name: string;
    next_patient_code: string;
    today: string;
};

/** One medicine line as typed. The three dose boxes hold whole numbers; empty means not touched. */
export type LineForm = {
    medicine_name: string;
    morning: string;
    noon: string;
    night: string;
    /** Free-text dose for anything the three numbers cannot say, such as "1 spoon". */
    dose: string;
    food_timing: string;
    duration_count: string;
    duration_unit: string;
    duration_custom: boolean;
    instructions: string;
};

/** What "copy this prescription" hands over from the list below the notepad. */
export type CopySource = {
    nonce: number;
    number: string;
    patient: PatientOption | null;
    diagnosis: string | null;
    advice: string | null;
    lines: TemplateRow['lines'];
};

const QUICK_DURATIONS: { count: number; unit: string; key: string }[] = [
    { count: 1, unit: 'day', key: 'dur_1_day' },
    { count: 3, unit: 'day', key: 'dur_3_day' },
    { count: 1, unit: 'week', key: 'dur_1_week' },
    { count: 15, unit: 'day', key: 'dur_15_day' },
    { count: 1, unit: 'month', key: 'dur_1_month' },
];

/** Every morning-noon-night pattern made of 0 and 1, the usual choices. */
const DOSE_PATTERNS = ['1+0+0', '0+1+0', '0+0+1', '1+1+0', '1+0+1', '0+1+1', '1+1+1'];

const SLOTS = ['morning', 'noon', 'night'] as const;

export const emptyLine = (): LineForm => ({ medicine_name: '', morning: '', noon: '', night: '', dose: '', food_timing: '', duration_count: '', duration_unit: 'day', duration_custom: false, instructions: '' });

const isQuick = (count: string, unit: string) => QUICK_DURATIONS.some((quick) => String(quick.count) === count && quick.unit === unit);

/** Turns saved lines (from a template or an old prescription) into notepad lines. */
export function linesFromSaved(saved: TemplateRow['lines']): LineForm[] {
    return saved.map((line): LineForm => {
        const parts = (line.dose_schedule ?? '').split('+');
        const hasSchedule = parts.length === 3;
        const count = line.duration_count ? String(line.duration_count) : '';
        const unit = line.duration_unit ?? 'day';
        return {
            medicine_name: [line.medicine_name, line.strength].filter(Boolean).join(' '),
            morning: hasSchedule ? parts[0] : '',
            noon: hasSchedule ? parts[1] : '',
            night: hasSchedule ? parts[2] : '',
            // An old line's loose text is kept as the free-text dose so nothing written is lost.
            dose: [line.dose, line.frequency, line.duration].filter(Boolean).join(' · '),
            food_timing: line.food_timing ?? '',
            duration_count: count,
            duration_unit: unit,
            duration_custom: count !== '' && !isQuick(count, unit),
            instructions: line.instructions ?? '',
        };
    });
}

/** The server's dose pattern: 1+0+1, with an untouched box as 0; nothing when no box was used. */
const doseSchedule = (line: LineForm): string | null => {
    const parts = [line.morning, line.noon, line.night];
    if (parts.every((part) => part === '' || Number(part) === 0)) {
        return null;
    }
    return parts.map((part) => (part === '' ? '0' : String(Number(part)))).join('+');
};

const addDays = (iso: string, days: number): string => {
    const [year, month, day] = iso.split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
};

/** A small number box with its own up and down arrows, so it works the same on a phone. */
function Spinner({ value, label, upLabel, downLabel, onChange }: { value: string; label: string; upLabel: string; downLabel: string; onChange: (next: string) => void }) {
    const number = value === '' ? 0 : Number(value);
    const set = (next: number) => onChange(String(Math.min(20, Math.max(0, next))));
    return (
        <div className="pad-spin" role="group" aria-label={label}>
            <small>{label}</small>
            <div className="pad-spin-box">
                <input
                    type="text"
                    inputMode="numeric"
                    aria-label={label}
                    value={value === '' ? '' : localizeDigits(value)}
                    placeholder={localizeDigits('0')}
                    onChange={(e) => {
                        const digits = e.target.value.replace(/[০-৯]/g, (ch) => String('০১২৩৪৫৬৭৮৯'.indexOf(ch))).replace(/\D/g, '');
                        onChange(digits === '' ? '' : String(Math.min(20, Number(digits))));
                    }}
                />
                <span>
                    <button type="button" aria-label={upLabel} onClick={() => set(number + 1)}><ChevronUp size={14} aria-hidden="true" /></button>
                    <button type="button" aria-label={downLabel} onClick={() => set(number - 1)}><ChevronDown size={14} aria-hidden="true" /></button>
                </span>
            </div>
        </div>
    );
}

function LetterheadBlock({ letterhead, doctorName, translate }: { letterhead: Letterhead | null; doctorName: string; translate: Translate }) {
    const bn = isBanglaDocument();
    const pick = (key: string): string => String((letterhead as Record<string, string | null | undefined> | null)?.[`${key}_${bn ? 'bn' : 'en'}`] ?? '').trim();
    const name = pick('doctor_name');
    if (letterhead === null || name === '') {
        return (
            <div className="pad-letterhead is-missing">
                <b>{doctorName}</b>
                <small className="muted">{translate('prescription_notepad', 'letterhead_missing')}</small>
            </div>
        );
    }
    return (
        <div className="pad-letterhead">
            {letterhead.logo_url && <img src={letterhead.logo_url} alt="" className="pad-logo" />}
            <div>
                <b>{name}</b>
                {['degrees', 'specialities', 'registration'].map((key) => pick(key) !== '' && <span key={key}>{pick(key)}</span>)}
            </div>
            {pick('chamber') !== '' && <div className="pad-chamber">{pick('chamber')}</div>}
        </div>
    );
}

/** One medicine on the notepad: free-text name, dose, meal timing and duration. */
function MedicineLine({ line, index, count, onChange, onMove, onRemove, translate }: {
    line: LineForm;
    index: number;
    count: number;
    onChange: (patch: Partial<LineForm>) => void;
    onMove: (direction: -1 | 1) => void;
    onRemove: () => void;
    translate: Translate;
}) {
    const t = (key: string, replace?: Record<string, string>) => translate('prescription_notepad', key, replace);
    const pattern = DOSE_PATTERNS.find((candidate) => candidate === SLOTS.map((slot) => (line[slot] === '' ? '0' : String(Number(line[slot])))).join('+')) ?? '';
    const quick = QUICK_DURATIONS.find((item) => String(item.count) === line.duration_count && item.unit === line.duration_unit);
    const customActive = line.duration_custom || (line.duration_count !== '' && !quick);
    const slotName = (slot: (typeof SLOTS)[number]) => t(`dose_${slot}`);

    return (
        <li className="pad-line">
            <div className="pad-line-head">
                <b>{localizeDigits(String(index + 1))}.</b>
                <label className="pad-grow">
                    <span className="sr-only">{t('medicine')}</span>
                    <input
                        required={index === 0}
                        maxLength={160}
                        autoComplete="off"
                        value={line.medicine_name}
                        placeholder={t('medicine_hint')}
                        onChange={(e) => onChange({ medicine_name: e.target.value })}
                    />
                </label>
                <span className="pad-line-tools">
                    <button type="button" className="icon-button" aria-label={t('move_up')} disabled={index === 0} onClick={() => onMove(-1)}><ArrowUp size={16} /></button>
                    <button type="button" className="icon-button" aria-label={t('move_down')} disabled={index === count - 1} onClick={() => onMove(1)}><ArrowDown size={16} /></button>
                    <button type="button" className="icon-button" aria-label={t('remove_line')} onClick={onRemove}><Trash2 size={16} /></button>
                </span>
            </div>

            <div className="pad-dose">
                <span className="rx-label">{t('dose_title')}</span>
                <label className="pad-pattern">
                    <span className="sr-only">{t('dose_pick')}</span>
                    <select
                        value={pattern}
                        onChange={(e) => {
                            const parts = e.target.value === '' ? ['', '', ''] : e.target.value.split('+');
                            onChange({ morning: parts[0], noon: parts[1], night: parts[2] });
                        }}
                    >
                        <option value="">{t('dose_pick_none')}</option>
                        {DOSE_PATTERNS.map((candidate) => {
                            const parts = candidate.split('+');
                            const names = SLOTS.filter((_, i) => parts[i] === '1').map(slotName).join(' + ');
                            return <option key={candidate} value={candidate}>{localizeDigits(candidate)} ({names})</option>;
                        })}
                    </select>
                </label>
                <div className="pad-spinners">
                    {SLOTS.map((slot, position) => (
                        <span key={slot} className="pad-spin-wrap">
                            {position > 0 && <b className="pad-plus" aria-hidden="true">+</b>}
                            <Spinner
                                value={line[slot]}
                                label={slotName(slot)}
                                upLabel={t('dose_up', { slot: slotName(slot) })}
                                downLabel={t('dose_down', { slot: slotName(slot) })}
                                onChange={(next) => onChange({ [slot]: next } as Partial<LineForm>)}
                            />
                        </span>
                    ))}
                </div>
                <div className="rx-mode pad-meal" role="group" aria-label={t('meal_after')}>
                    {(['before', 'after'] as const).map((timing) => (
                        <button
                            key={timing}
                            type="button"
                            className="rx-mode-button"
                            aria-pressed={line.food_timing === timing}
                            onClick={() => onChange({ food_timing: line.food_timing === timing ? '' : timing })}
                        >
                            {t(`meal_${timing}`)}
                        </button>
                    ))}
                </div>
            </div>

            <div className="pad-duration">
                <span className="rx-label">{t('duration')}</span>
                <div className="pad-chips" role="group" aria-label={t('duration')}>
                    {QUICK_DURATIONS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            className="pad-chip"
                            aria-pressed={!customActive && quick === item}
                            onClick={() => onChange(!customActive && quick === item
                                ? { duration_count: '', duration_custom: false }
                                : { duration_count: String(item.count), duration_unit: item.unit, duration_custom: false })}
                        >
                            {t(item.key)}
                        </button>
                    ))}
                    <button type="button" className="pad-chip" aria-pressed={customActive} onClick={() => onChange({ duration_custom: !customActive, ...(customActive ? { duration_count: '' } : {}) })}>{t('dur_custom')}</button>
                </div>
                {customActive && (
                    <div className="pad-custom">
                        <input
                            type="number"
                            inputMode="numeric"
                            min="1"
                            max="365"
                            step="1"
                            aria-label={t('duration')}
                            value={line.duration_count}
                            onChange={(e) => onChange({ duration_count: e.target.value, duration_custom: true })}
                        />
                        <select aria-label={t('duration')} value={line.duration_unit || 'day'} onChange={(e) => onChange({ duration_unit: e.target.value, duration_custom: true })}>
                            {['day', 'week', 'month'].map((unit) => <option key={unit} value={unit}>{translate('clinic_records', `unit_${unit}`)}</option>)}
                        </select>
                    </div>
                )}
            </div>

            <div className="pad-extra">
                <label>{t('dose_other')}
                    <input maxLength={80} value={line.dose} placeholder={t('dose_other_hint')} onChange={(e) => onChange({ dose: e.target.value })} />
                </label>
                <label>{t('line_note')}
                    <input maxLength={300} value={line.instructions} onChange={(e) => onChange({ instructions: e.target.value })} />
                </label>
            </div>
        </li>
    );
}

/**
 * The notepad: a prescription written the way it is on paper. Letterhead on top, the patient's
 * details in a bar, complaints and findings on the left, the medicines (Rx) on the right,
 * advice, next visit and signature below.
 */
export default function PrescriptionNotepad({ data, tools, copy, translate }: { data: NotepadData; tools: PrescriptionTools; copy: CopySource | null; translate: Translate }) {
    const t = (key: string, replace?: Record<string, string>) => translate('prescription_notepad', key, replace);
    const form = useForm({
        patient_id: '',
        new_patient: blankPatient(),
        complaints: '',
        examination: '',
        diagnosis: '',
        investigations: '',
        advice: [''] as string[],
        follow_up_on: '',
        lines: [emptyLine()] as LineForm[],
    });
    const [visit, setVisit] = useState<'new' | 'old'>(data.own_patients.length > 0 ? 'old' : 'new');
    const [chosen, setChosen] = useState<PatientOption | null>(null);
    const [afterDays, setAfterDays] = useState('');
    const [note, setNote] = useState<string | null>(null);
    const errors = form.errors as Record<string, string>;
    const lines = form.data.lines;
    const oldChosen = visit === 'old' && chosen !== null && form.data.patient_id === String(chosen.id);
    const patient = form.data.new_patient;

    const setLine = (index: number, patch: Partial<LineForm>) => form.setData('lines', lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const moveLine = (index: number, direction: -1 | 1) => {
        const target = index + direction;
        if (target < 0 || target >= lines.length) {
            return;
        }
        const next = [...lines];
        [next[index], next[target]] = [next[target], next[index]];
        form.setData('lines', next);
    };
    const setPatient = (patch: Partial<typeof patient>) => form.setData('new_patient', { ...patient, ...patch });
    const setAdvice = (next: string[]) => form.setData('advice', next.length === 0 ? [''] : next);

    const pickOld = (id: string, option: PatientOption | null) => {
        setChosen(option);
        setNote(null);
        form.setData('patient_id', id);
    };

    /** Fills the diagnosis, advice and medicines from a draft; nothing is saved until the form is. */
    const fillFrom = (source: { diagnosis: string | null; advice: string | null; lines: TemplateRow['lines'] }, message: string) => {
        const filled = linesFromSaved(source.lines);
        form.setData({
            ...form.data,
            diagnosis: source.diagnosis ?? '',
            advice: linesOf(source.advice ?? '').length > 0 ? linesOf(source.advice ?? '') : [''],
            lines: filled.length > 0 ? filled : [emptyLine()],
        });
        setNote(message);
    };

    const repeatLast = async () => {
        if (!oldChosen || !chosen) {
            return;
        }
        const response = await fetch(`/commands/prescriptions/patients/${chosen.id}/repeat`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
        if (!response?.ok) {
            setNote(translate('clinic_records', 'repeat_failed'));
            return;
        }
        const json = await response.json() as { draft: { number: string; diagnosis: string | null; advice: string | null; lines: TemplateRow['lines'] } | null };
        if (json.draft === null) {
            setNote(translate('clinic_records', 'repeat_none'));
            return;
        }
        if (lines.some((line) => line.medicine_name !== '') && !window.confirm(translate('prescription', 'tpl_replace_confirm'))) {
            return;
        }
        fillFrom(json.draft, t('copied', { number: json.draft.number }));
    };

    // "Write a new one from this" on a prescription in the list below: same patient, same medicines.
    useEffect(() => {
        if (copy === null) {
            return;
        }
        if (copy.patient) {
            setVisit('old');
            setChosen(copy.patient);
            form.setData('patient_id', String(copy.patient.id));
        }
        fillFrom(copy, t('copied', { number: copy.number }));
        window.scrollTo({ top: 0, behavior: 'smooth' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [copy?.nonce]);

    const reset = () => {
        form.reset();
        form.clearErrors();
        setChosen(null);
        setAfterDays('');
        setNote(null);
        setVisit('old');
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (visit === 'old' && form.data.patient_id === '') {
            setNote(t('old_pick_first'));
            return;
        }
        form.transform((values) => ({
            patient_id: visit === 'old' ? values.patient_id : null,
            new_patient: visit === 'new' ? patientForServer(values.new_patient) : null,
            visit_type: visit,
            prescribed_on: data.today,
            complaints: values.complaints,
            examination: values.examination,
            diagnosis: values.diagnosis,
            investigations: linesOf(values.investigations),
            advice: values.advice.map((row) => row.trim()).filter(Boolean).join('\n'),
            follow_up_on: values.follow_up_on || null,
            lines: values.lines.map((line) => ({
                medicine_name: line.medicine_name,
                dose_schedule: doseSchedule(line),
                dose: line.dose,
                food_timing: line.food_timing || null,
                duration_count: line.duration_count === '' ? null : Number(line.duration_count),
                duration_unit: line.duration_count === '' ? null : (line.duration_unit || 'day'),
                instructions: line.instructions,
            })),
        }));
        form.post('/commands/prescriptions', {
            preserveScroll: true,
            onSuccess: reset,
            onError: (serverErrors) => {
                if (Object.keys(serverErrors).some((key) => key.startsWith('new_patient'))) {
                    setVisit('new');
                }
            },
        });
    };

    const followDate = form.data.follow_up_on;
    const sexOf = (value: string | null) => (value ? translate('prescription', `sex_${value}`) : '');
    const lineErrors = useMemo(() => Object.entries(errors).filter(([key]) => key.startsWith('lines')), [errors]);

    return (
        <section className="panel pad">
            <div className="panel-heading">
                <div><h2>{t('pad_title')}</h2><p className="muted">{t('pad_intro')}</p></div>
            </div>
            <form className="pad-sheet" onSubmit={submit}>
                <LetterheadBlock letterhead={data.letterhead} doctorName={data.doctor_name} translate={translate} />

                <div className="pad-bar">
                    <div className="rx-mode pad-visit" role="group" aria-label={t('visit_new')}>
                        {(['new', 'old'] as const).map((kind) => (
                            <button key={kind} type="button" className="rx-mode-button" aria-pressed={visit === kind} onClick={() => { setVisit(kind); setNote(null); }}>
                                {t(`visit_${kind}`)}
                            </button>
                        ))}
                    </div>
                    {visit === 'old' && (
                        <div className="pad-old">
                            <PatientPicker initial={data.own_patients} value={form.data.patient_id} chosen={chosen} onChange={pickOld} translate={translate} />
                            {errors.patient_id && <span className="field-error" role="alert">{errors.patient_id}</span>}
                            {oldChosen && <button type="button" className="button small" onClick={() => void repeatLast()}><Repeat size={15} /> {t('repeat_last')}</button>}
                        </div>
                    )}
                    <div className="pad-info">
                        <label className="pad-name">{t('patient_name')}
                            {visit === 'new'
                                ? <input required maxLength={120} autoComplete="off" value={patient.name} onChange={(e) => setPatient({ name: e.target.value })} />
                                : <input readOnly value={oldChosen && chosen ? chosen.name : ''} />}
                            {errors['new_patient.name'] && <span className="field-error" role="alert">{errors['new_patient.name']}</span>}
                        </label>
                        <label>{t('patient_id')}
                            <input readOnly value={visit === 'new' ? `${localizeDigits(data.next_patient_code)} (${t('patient_id_auto')})` : (oldChosen && chosen ? localizeDigits(chosen.code) : '')} />
                        </label>
                        <label>{t('date')}
                            <input readOnly value={localizeDigits(data.today.split('-').reverse().join('/'))} />
                        </label>
                        <label>{t('age')}
                            {visit === 'new'
                                ? <input type="number" inputMode="numeric" min="0" max="130" value={patient.age} onChange={(e) => setPatient({ age: e.target.value })} />
                                : <input readOnly value={oldChosen && chosen && chosen.age !== null ? `${localizeDigits(String(chosen.age))} ${t('age_years')}` : ''} />}
                        </label>
                        <label>{t('sex')}
                            {visit === 'new' ? (
                                <select value={patient.sex} onChange={(e) => setPatient({ sex: e.target.value })}>
                                    <option value="">–</option>
                                    {data.sexes.map((sex) => <option key={sex} value={sex}>{translate('prescription', `sex_${sex}`)}</option>)}
                                </select>
                            ) : <input readOnly value={oldChosen && chosen ? sexOf(chosen.sex) : ''} />}
                        </label>
                        <label className="pad-address">{t('address')}
                            {visit === 'new'
                                ? <input maxLength={500} autoComplete="off" value={patient.address_text} onChange={(e) => setPatient({ address_text: e.target.value })} />
                                : <input readOnly value={oldChosen && chosen ? (chosen.address ?? '') : ''} />}
                        </label>
                        {visit === 'new' && (
                            <>
                                <label>{t('phone')}
                                    <input type="tel" inputMode="tel" maxLength={20} autoComplete="off" value={patient.phone} onChange={(e) => setPatient({ phone: e.target.value })} />
                                    {errors['new_patient.phone'] && <span className="field-error" role="alert">{errors['new_patient.phone']}</span>}
                                </label>
                                <label className="pad-address">{translate('clinic_records', 'allergies')}
                                    <input maxLength={1000} value={patient.allergies} onChange={(e) => setPatient({ allergies: e.target.value })} />
                                </label>
                                <label className="rx-consent pad-consent">
                                    <input type="checkbox" required checked={patient.patient_consent} onChange={(e) => setPatient({ patient_consent: e.target.checked })} />
                                    <span>{translate('prescriber_public', 'patient_consent_label')}</span>
                                </label>
                                {errors['new_patient.patient_consent'] && <span className="field-error" role="alert">{errors['new_patient.patient_consent']}</span>}
                            </>
                        )}
                    </div>
                    {oldChosen && chosen?.allergies && (
                        <div className="rx-allergy" role="alert">
                            <AlertTriangle size={20} aria-hidden="true" />
                            <div>
                                <b>{translate('clinic_records', 'allergy_warning_title')}</b>
                                <p>{chosen.allergies}</p>
                                <small>{translate('clinic_records', 'allergy_warning_body')}</small>
                            </div>
                        </div>
                    )}
                </div>

                <TemplatePicker
                    templates={tools.templates}
                    isDirty={lines.some((line) => line.medicine_name !== '') || form.data.advice.some((row) => row !== '')}
                    onApply={(draft) => form.setData({ ...form.data, advice: linesOf(draft.advice).length > 0 ? linesOf(draft.advice) : [''], lines: draft.lines.length > 0 ? draft.lines : [emptyLine()] })}
                    translate={translate}
                />
                {note && <p className="muted rx-note" role="status">{note}</p>}

                <div className="pad-body">
                    <div className="pad-left">
                        <label><h3>{t('complaints')}</h3>
                            <textarea rows={4} maxLength={3000} value={form.data.complaints} onChange={(e) => form.setData('complaints', e.target.value)} />
                        </label>
                        <label><h3>{t('examination')}</h3>
                            <textarea rows={4} maxLength={3000} value={form.data.examination} onChange={(e) => form.setData('examination', e.target.value)} />
                        </label>
                        <label><h3>{t('diagnosis')}</h3>
                            <textarea rows={3} maxLength={3000} value={form.data.diagnosis} onChange={(e) => form.setData('diagnosis', e.target.value)} />
                        </label>
                        <label><h3>{t('investigation')}</h3>
                            <textarea rows={5} value={form.data.investigations} placeholder={t('investigation_hint')} onChange={(e) => form.setData('investigations', e.target.value)} />
                            {Object.keys(errors).some((key) => key.startsWith('investigations')) && <span className="field-error" role="alert">{Object.entries(errors).find(([key]) => key.startsWith('investigations'))?.[1]}</span>}
                        </label>
                    </div>

                    <div className="pad-right">
                        <h3 className="pad-rx">{t('rx')}</h3>
                        <ol className="pad-lines">
                            {lines.map((line, index) => (
                                <MedicineLine
                                    key={index}
                                    line={line}
                                    index={index}
                                    count={lines.length}
                                    onChange={(patch) => setLine(index, patch)}
                                    onMove={(direction) => moveLine(index, direction)}
                                    onRemove={() => form.setData('lines', lines.length === 1 ? [emptyLine()] : lines.filter((_, i) => i !== index))}
                                    translate={translate}
                                />
                            ))}
                        </ol>
                        {lineErrors.map(([key, message]) => <span key={key} className="field-error" role="alert">{String(message)}</span>)}
                        {lines.length < data.max_lines && (
                            <button type="button" className="button small pad-add" onClick={() => form.setData('lines', [...lines, emptyLine()])}><Plus size={16} /> {t('add_line')}</button>
                        )}
                    </div>
                </div>

                <div className="pad-foot">
                    <div className="pad-advice">
                        <h3>{t('advice')}</h3>
                        <ol>
                            {form.data.advice.map((row, index) => (
                                <li key={index}>
                                    <input maxLength={300} value={row} placeholder={t('advice_hint')} aria-label={t('advice_hint')} onChange={(e) => setAdvice(form.data.advice.map((item, i) => (i === index ? e.target.value : item)))} />
                                    <button type="button" className="icon-button" aria-label={t('advice_remove')} onClick={() => setAdvice(form.data.advice.filter((_, i) => i !== index))}><Trash2 size={16} /></button>
                                </li>
                            ))}
                        </ol>
                        <button type="button" className="button small" onClick={() => setAdvice([...form.data.advice, ''])}><Plus size={16} /> {t('advice_add')}</button>
                    </div>
                    <div className="pad-next">
                        <h3>{t('next_visit')}</h3>
                        <div className="pad-next-row">
                            <label>
                                <input
                                    type="number"
                                    inputMode="numeric"
                                    min="1"
                                    max="365"
                                    aria-label={t('after_days')}
                                    value={afterDays}
                                    onChange={(e) => {
                                        setAfterDays(e.target.value);
                                        const days = Number(e.target.value);
                                        form.setData('follow_up_on', days >= 1 && days <= 365 ? addDays(data.today, days) : '');
                                    }}
                                />
                                <span>{t('after_days')}</span>
                            </label>
                            <label>{t('or_date')}
                                <DateInput value={followDate} min={data.today} onChange={(e) => { setAfterDays(''); form.setData('follow_up_on', e.target.value); }} />
                            </label>
                        </div>
                        {errors.follow_up_on && <span className="field-error" role="alert">{errors.follow_up_on}</span>}
                    </div>
                    <div className="pad-sign">
                        <span className="pad-sign-line" />
                        <b>{data.doctor_name}</b>
                        <small>{t('signature')}</small>
                    </div>
                </div>

                {errors.lines && <div className="error" role="alert">{errors.lines}</div>}
                <div className="rx-actions">
                    <button className="button dark" disabled={form.processing}>{form.processing ? t('saving') : t('save')}</button>
                    <button type="button" className="button" onClick={reset}>{t('clear')}</button>
                </div>
            </form>
        </section>
    );
}
