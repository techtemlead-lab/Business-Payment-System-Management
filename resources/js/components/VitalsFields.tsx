import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type VitalsForm = { bp_systolic: string; bp_diastolic: string; weight_kg: string; pulse: string; temperature_f: string };

export const emptyVitals = (): VitalsForm => ({ bp_systolic: '', bp_diastolic: '', weight_kg: '', pulse: '', temperature_f: '' });

/** Blank readings are sent as nothing at all, so a visit without vitals stores none. */
export const vitalsForServer = (vitals: VitalsForm): Record<string, string | null> =>
    Object.fromEntries(Object.entries(vitals).map(([field, value]) => [field, value === '' ? null : value]));

const fields: { name: keyof VitalsForm; label: string; step: string; min: string; max: string }[] = [
    { name: 'bp_systolic', label: 'vital_bp_systolic', step: '1', min: '40', max: '300' },
    { name: 'bp_diastolic', label: 'vital_bp_diastolic', step: '1', min: '20', max: '200' },
    { name: 'weight_kg', label: 'vital_weight_input', step: '0.01', min: '0.5', max: '400' },
    { name: 'pulse', label: 'vital_pulse_input', step: '1', min: '20', max: '300' },
    { name: 'temperature_f', label: 'vital_temperature_input', step: '0.1', min: '85', max: '115' },
];

export function VitalsFields({ value, errors, onChange, translate }: { value: VitalsForm; errors: Record<string, string>; onChange: (next: VitalsForm) => void; translate: Translate }) {
    const messages = Object.entries(errors).filter(([key]) => key.startsWith('vitals.'));

    return (
        <fieldset className="rx-lines">
            <legend>{translate('clinic_records', 'vitals_title')}</legend>
            <div className="rx-vitals">
                {fields.map((field) => (
                    <label key={field.name}>{translate('clinic_records', field.label)}
                        <input type="number" inputMode="decimal" step={field.step} min={field.min} max={field.max} value={value[field.name]} onChange={(e) => onChange({ ...value, [field.name]: e.target.value })} />
                    </label>
                ))}
            </div>
            {messages.map(([key, message]) => <span key={key} className="field-error" role="alert">{message}</span>)}
        </fieldset>
    );
}
