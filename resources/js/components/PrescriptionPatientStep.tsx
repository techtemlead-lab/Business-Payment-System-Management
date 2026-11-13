import React, { useEffect, useRef, useState } from 'react';
import { AlertTriangle, UserCheck, UserPlus } from 'lucide-react';
import { number } from './UI';
import SearchSelect from './SearchSelect';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

type Translate = ReturnType<typeof useT>;

export type PatientOption = { id: number; name: string; age: number | null; allergies: string | null };

/** The patient boxes as the person types them; `patientForServer` turns them into what the server expects. */
export type PatientForm = {
    name: string;
    age: string;
    sex: string;
    phone: string;
    place_code_id: string;
    notes: string;
    allergies: string;
    patient_consent: boolean;
};

export const blankPatient = (): PatientForm => ({ name: '', age: '', sex: '', phone: '', place_code_id: '', notes: '', allergies: '', patient_consent: false });

export const patientForServer = (values: PatientForm) => ({
    ...values,
    age: values.age === '' ? null : Number(values.age),
    sex: values.sex || null,
    place_code_id: values.place_code_id === '' ? null : Number(values.place_code_id),
});

/** The prescriber's own patients: the newest first, and typing a name in the list reaches older ones. */
export function PatientPicker({ initial, value, chosen, onChange, translate }: {
    initial: PatientOption[];
    value: string;
    chosen: PatientOption | null;
    onChange: (id: string, patient: PatientOption | null) => void;
    translate: Translate;
}) {
    const [found, setFound] = useState<PatientOption[] | null>(null);
    const [loading, setLoading] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    const turn = useRef(0);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const search = (text: string) => {
        window.clearTimeout(timer.current);
        const term = text.trim();
        const mine = ++turn.current;
        if (term === '') {
            setFound(null);
            setLoading(false);
            return;
        }
        setLoading(true);
        timer.current = window.setTimeout(() => {
            fetch(`/commands/prescriptions/patients/options?${new URLSearchParams({ q: term })}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : { data: [] }))
                .then((json: { data: PatientOption[] }) => { if (turn.current === mine) { setFound(json.data); setLoading(false); } })
                .catch(() => { if (turn.current === mine) { setFound([]); setLoading(false); } });
        }, 250);
    };

    const listed = found ?? initial;
    const patients = chosen && !listed.some((patient) => patient.id === chosen.id) ? [chosen, ...listed] : listed;

    return (
        <label>{translate('prescription', 'rx_patient')}
            <SearchSelect
                required
                value={value}
                placeholder={translate('prescription', 'rx_choose_patient')}
                selectedLabel={chosen?.name}
                options={patients.map((patient) => ({ value: String(patient.id), label: `${patient.name}${patient.age !== null ? ` (${number(patient.age)})` : ''}` }))}
                onChange={(id) => onChange(id, patients.find((patient) => String(patient.id) === id) ?? null)}
                onSearch={search}
                loading={loading}
            />
        </label>
    );
}

type PlaceOption = { id: number; code: string; name: string; level: string };
const placeLevels = ['division', 'district', 'upazila', 'union'] as const;

/** Division → district → upazila → union, like the account form's place picker; keeps the most specific choice. */
export function PlaceCodePicker({ value, onChange, translate }: { value: string; onChange: (id: string) => void; translate: Translate }) {
    const [chosen, setChosen] = useState<string[]>(['', '', '', '']);
    const [options, setOptions] = useState<PlaceOption[][]>([[], [], [], []]);

    const load = async (levelIndex: number, parentId: string) => {
        const params = new URLSearchParams({ level: placeLevels[levelIndex] });
        if (parentId) params.set('parent_id', parentId);
        try {
            const response = await fetch(`/commands/prescriptions/places?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const json = response.ok ? await response.json() : { data: [] };
            setOptions((current) => current.map((list, i) => (i === levelIndex ? (json.data as PlaceOption[]) : i > levelIndex ? [] : list)));
        } catch {
            setOptions((current) => current.map((list, i) => (i >= levelIndex ? [] : list)));
        }
    };

    useEffect(() => { void load(0, ''); }, []);
    useEffect(() => {
        if (value === '') {
            setChosen(['', '', '', '']);
        }
    }, [value]);

    const pick = (levelIndex: number, id: string) => {
        const next = chosen.map((current, i) => (i === levelIndex ? id : i > levelIndex ? '' : current));
        setChosen(next);
        onChange(next.filter(Boolean).pop() ?? '');
        if (id && levelIndex < placeLevels.length - 1) {
            void load(levelIndex + 1, id);
        }
    };

    return (
        <div className="rx-place">
            {placeLevels.map((level, index) => (index === 0 || chosen[index - 1]) && (
                <label key={level}>{translate('prescription', `place_${level}`)}
                    <SearchSelect
                        value={chosen[index]}
                        emptyLabel={translate('prescription', 'place_choose')}
                        options={options[index].map((option) => ({ value: String(option.id), label: `${option.code} — ${option.name}` }))}
                        onChange={(id) => pick(index, id)}
                    />
                </label>
            ))}
        </div>
    );
}

/**
 * The boxes for one patient. The Patients tab and the new-patient panel on the prescription form
 * both draw these, so a patient is described the same way wherever they are added.
 */
export function PatientFields({ value, onChange, sexes, errors = {}, errorPrefix = '', currentPlace, askConsent, consentRequired, translate }: {
    value: PatientForm;
    onChange: (patch: Partial<PatientForm>) => void;
    sexes: string[];
    errors?: Record<string, string>;
    /** "new_patient." on the prescription form, where the server names the boxes that way. */
    errorPrefix?: string;
    currentPlace?: string | null;
    askConsent: boolean;
    consentRequired: boolean;
    translate: Translate;
}) {
    const notesBox = useReasonField('clinic.patient_notes');
    const error = (box: string) => {
        const message = errors[`${errorPrefix}${box}`];
        return message ? <span className="field-error" role="alert">{message}</span> : null;
    };

    return (
        <>
            <div className="rx-grid">
                <label>{translate('prescription', 'patient_name')}
                    <input required maxLength={120} autoComplete="off" value={value.name} onChange={(e) => onChange({ name: e.target.value })} />
                    {error('name')}
                </label>
                <label>{translate('prescription', 'patient_age')}
                    <input type="number" inputMode="numeric" min="0" max="130" value={value.age} onChange={(e) => onChange({ age: e.target.value })} />
                    {error('age')}
                </label>
                <label>{translate('prescription', 'patient_sex')}
                    <select value={value.sex} onChange={(e) => onChange({ sex: e.target.value })}>
                        <option value="">{translate('prescription', 'place_choose')}</option>
                        {sexes.map((sex) => <option key={sex} value={sex}>{translate('prescription', `sex_${sex}`)}</option>)}
                    </select>
                    {error('sex')}
                </label>
                <label>{translate('prescription', 'patient_phone')}
                    <input type="tel" inputMode="tel" maxLength={20} autoComplete="off" value={value.phone} onChange={(e) => onChange({ phone: e.target.value })} />
                    {error('phone')}
                </label>
            </div>
            <div>
                <span className="rx-label">{translate('prescription', 'patient_place')}</span>
                {currentPlace && <small className="muted"> {currentPlace}</small>}
                <PlaceCodePicker value={value.place_code_id} onChange={(id) => onChange({ place_code_id: id })} translate={translate} />
                {error('place_code_id')}
            </div>
            <label>{translate('clinic_records', 'allergies')}
                <textarea rows={2} maxLength={1000} value={value.allergies} onChange={(e) => onChange({ allergies: e.target.value })} />
                <small className="muted">{translate('clinic_records', 'allergies_hint')}</small>
                {error('allergies')}
            </label>
            {notesBox.shown && <label>{notesBox.label(translate('prescription', 'patient_notes'))}
                <textarea rows={2} maxLength={2000} required={notesBox.required} value={value.notes} onChange={(e) => onChange({ notes: e.target.value })} />
                {error('notes')}
            </label>}
            {askConsent && (
                <label className="rx-consent">
                    <input type="checkbox" required={consentRequired} checked={value.patient_consent} onChange={(e) => onChange({ patient_consent: e.target.checked })} />
                    <span>{translate('prescriber_public', 'patient_consent_label')}</span>
                </label>
            )}
            {error('patient_consent')}
        </>
    );
}

export type PatientMode = 'old' | 'new';

/**
 * The first step of a prescription: an old patient is picked from the list, or a new patient's
 * details are typed in a panel that folds to one line once they are in. Nothing is saved here;
 * a new patient is saved together with the prescription.
 */
export function PatientStep({
    mode, onMode, ownPatients, patientId, chosen, onPick, newPatient, onNewPatient, folded, onFolded, sexes, errors, translate,
}: {
    mode: PatientMode;
    onMode: (mode: PatientMode) => void;
    ownPatients: PatientOption[];
    patientId: string;
    chosen: PatientOption | null;
    onPick: (id: string, patient: PatientOption | null) => void;
    newPatient: PatientForm;
    onNewPatient: (patch: Partial<PatientForm>) => void;
    folded: boolean;
    onFolded: (folded: boolean) => void;
    sexes: string[];
    errors: Record<string, string>;
    translate: Translate;
}) {
    const [missing, setMissing] = useState<string | null>(null);

    const finish = () => {
        if (newPatient.name.trim() === '') {
            setMissing(translate('prescription', 'patient_need_name'));
            return;
        }
        if (!newPatient.patient_consent) {
            setMissing(translate('prescription', 'patient_need_consent'));
            return;
        }
        setMissing(null);
        onFolded(true);
    };

    const facts = [
        newPatient.age !== '' ? translate('prescription', 'print_years', { age: newPatient.age }) : null,
        newPatient.sex ? translate('prescription', `sex_${newPatient.sex}`) : null,
        newPatient.phone || null,
    ].filter(Boolean).join(' · ');

    return (
        <div className="rx-patient-step">
            <span className="rx-label">{translate('prescription', 'patient_step_title')}</span>
            <div className="rx-mode" role="group" aria-label={translate('prescription', 'patient_step_title')}>
                <button type="button" className="rx-mode-button" aria-pressed={mode === 'old'} onClick={() => onMode('old')}>
                    <UserCheck size={18} aria-hidden="true" /> {translate('prescription', 'patient_mode_old')}
                </button>
                <button type="button" className="rx-mode-button" aria-pressed={mode === 'new'} onClick={() => onMode('new')}>
                    <UserPlus size={18} aria-hidden="true" /> {translate('prescription', 'patient_new')}
                </button>
            </div>

            {mode === 'old' && (
                ownPatients.length === 0 && chosen === null ? (
                    <p className="muted rx-note">{translate('prescription', 'patient_old_none')}</p>
                ) : (
                    <div className="rx-patient-pick">
                        <PatientPicker initial={ownPatients} value={patientId} chosen={chosen} onChange={onPick} translate={translate} />
                        {errors.patient_id && <span className="field-error" role="alert">{errors.patient_id}</span>}
                    </div>
                )
            )}

            {mode === 'new' && !folded && (
                <div className="rx-patient-panel">
                    <PatientFields
                        value={newPatient}
                        onChange={(patch) => { setMissing(null); onNewPatient(patch); }}
                        sexes={sexes}
                        errors={errors}
                        errorPrefix="new_patient."
                        askConsent
                        consentRequired
                        translate={translate}
                    />
                    {missing && <span className="field-error" role="alert">{missing}</span>}
                    <div className="rx-actions">
                        <button type="button" className="button dark" onClick={finish}>{translate('prescription', 'patient_continue')}</button>
                        <small className="muted">{translate('prescription', 'patient_new_note')}</small>
                    </div>
                </div>
            )}

            {mode === 'new' && folded && (
                <div className="rx-patient-summary">
                    <div>
                        <b>{newPatient.name}</b>
                        {facts && <small className="cell-sub">{facts}</small>}
                        {newPatient.allergies.trim() !== '' && (
                            <small className="cell-sub is-bad"><AlertTriangle size={13} aria-hidden="true" /> {translate('clinic_records', 'allergies')}: {newPatient.allergies}</small>
                        )}
                    </div>
                    <button type="button" className="button small" onClick={() => onFolded(false)}>{translate('prescription', 'patient_change')}</button>
                </div>
            )}
        </div>
    );
}
