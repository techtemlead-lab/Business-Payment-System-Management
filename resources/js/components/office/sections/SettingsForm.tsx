import { useForm } from '@inertiajs/react';
import type { Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { AutoSelect } from '../../SearchSelect';

export type SettingsField = {
    name: string;
    label: string;
    type?: string;
    required?: boolean;
    options?: { value: string; label: string }[];
    accept?: string;
};

export type SettingsAction = { title: string; action: string; fields: SettingsField[]; defaults?: Row };

type TranslateFn = (group: string, key: string, replace?: Record<string, string>) => string;

/** The way back from a settings page to the Settings list. */
export function settingsBack(translate: TranslateFn): { href: string; label: string } {
    return { href: '/settings', label: translate('office_settings', 'back_to_settings') };
}

/**
 * One column of fields, labels above, one Save at normal width. It posts the same fields
 * to the same address as the old page; an empty optional field is left out of the request.
 */
export default function SettingsForm({ action, fields, defaults = {}, submitLabel, onDone }: {
    action: string;
    fields: SettingsField[];
    defaults?: Row;
    submitLabel: string;
    onDone?: () => void;
}) {
    const translate = useT();
    const form = useForm<Row>({ ...Object.fromEntries(fields.map((field) => [field.name, field.type === 'file' ? null : ''])), ...defaults });

    return (
        <form className="action-form" aria-label={submitLabel} onSubmit={(event) => {
            event.preventDefault();
            form.transform((input) => Object.fromEntries(Object.entries(input).filter(([name, value]) => !(value === '' && !fields.find((field) => field.name === name)?.required))));
            form.post(action, { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } });
        }}>
            {fields.map((field) => {
                const value = form.data[field.name] ?? '';
                return (
                    <label key={field.name}>{field.label}
                        {field.options ? (
                            <AutoSelect
                                required={field.required}
                                value={String(value)}
                                onChange={(picked) => form.setData(field.name, picked)}
                                emptyLabel={translate('portal', 'ops_fld_select')}
                                options={field.options.map((option) => ({ value: option.value, label: option.label }))}
                            />
                        ) : field.type === 'textarea' ? (
                            <textarea rows={3} required={field.required} value={value} onChange={(event) => form.setData(field.name, event.target.value)} />
                        ) : field.type === 'file' ? (
                            <input required={field.required} type="file" accept={field.accept} onChange={(event) => form.setData(field.name, event.target.files?.[0] ?? null)} />
                        ) : (
                            <input required={field.required} type={field.type ?? 'text'} step={field.type === 'number' ? '0.01' : undefined} value={value} onChange={(event) => form.setData(field.name, event.target.value)} />
                        )}
                        {form.errors[field.name] && <span className="field-error">{String(form.errors[field.name])}</span>}
                    </label>
                );
            })}
            {Object.keys(form.errors).length > 0 && <div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
            {form.progress && <progress max={100} value={form.progress.percentage} aria-label={translate('portal', 'ops_upload_progress')} />}
            <button className="button dark set-save" disabled={form.processing}>{form.processing ? translate('portal', 'ops_recording') : submitLabel}</button>
        </form>
    );
}
