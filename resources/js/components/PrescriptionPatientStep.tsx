import { useEffect, useRef, useState } from 'react';
import { number } from './UI';
import SearchSelect from './SearchSelect';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

type Translate = ReturnType<typeof useT>;

export type PatientOption = { id: number; name: string; age: number | null; sex: string | null; address: string | null; code: string; phone: string | null; allergies: string | null };

/** The patient boxes as the person types them; `patientForServer` turns them into what the server expects. */
export type PatientForm = {
    name: string;
    age: string;
    sex: string;
    phone: string;
    address_text: string;
    notes: string;
    allergies: string;
    patient_consent: boolean;
};

export const blankPatient = (): PatientForm => ({ name: '', age: '', sex: '', phone: '', address_text: '', notes: '', allergies: '', patient_consent: false });

export const patientForServer = (values: PatientForm) => ({
    ...values,
    age: values.age === '' ? null : Number(values.age),
    sex: values.sex || null,
    address_text: values.address_text.trim() === '' ? null : values.address_text,
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

/**
 * The boxes for one patient. The Patients tab and the new-patient panel on the prescription form
 * both draw these, so a patient is described the same way wherever they are added.
 */
export function PatientFields({ value, onChange, sexes, errors = {}, errorPrefix = '', askConsent, consentRequired, translate }: {
    value: PatientForm;
    onChange: (patch: Partial<PatientForm>) => void;
    sexes: string[];
    errors?: Record<string, string>;
    /** "new_patient." on the prescription form, where the server names the boxes that way. */
    errorPrefix?: string;
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
            <label>{translate('prescription_notepad', 'address')}
                <textarea rows={2} maxLength={500} value={value.address_text} onChange={(e) => onChange({ address_text: e.target.value })} />
                {error('address_text')}
            </label>
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
