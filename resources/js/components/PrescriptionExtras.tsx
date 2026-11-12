import { useState } from 'react';
import { router } from '@inertiajs/react';
import { BookmarkPlus, ClipboardList } from 'lucide-react';
import SearchSelect from './SearchSelect';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type DietTemplateRow = { id: number; name: string; foods_allowed: string[]; foods_avoided: string[] };

/** The three lists as the person types them, one row on each line. */
export type ExtrasForm = { investigations: string; foods_allowed: string; foods_avoided: string };

/** The typed rows of a list box: blank lines dropped, spaces trimmed. */
export const linesOf = (text: string): string[] => text.split('\n').map((line) => line.trim()).filter(Boolean);

/** Adds rows to a list box, skipping a row that is already there in any letter case. */
export function addLines(text: string, rows: string[]): string {
    const kept = linesOf(text);
    const seen = new Set(kept.map((row) => row.toLowerCase()));
    for (const row of rows) {
        const clean = row.trim();
        if (clean !== '' && !seen.has(clean.toLowerCase())) {
            seen.add(clean.toLowerCase());
            kept.push(clean);
        }
    }
    return kept.join('\n');
}

/** Keeps the two food boxes as a named template, without leaving the prescription being written. */
function KeepAsDietTemplate({ value, translate }: { value: ExtrasForm; translate: Translate }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    if (linesOf(value.foods_allowed).length === 0 && linesOf(value.foods_avoided).length === 0) {
        return null;
    }
    if (!open) {
        return (
            <button type="button" className="button small" onClick={() => setOpen(true)}>
                <BookmarkPlus size={15} /> {translate('prescription', 'diet_keep')}
            </button>
        );
    }

    const save = () => {
        if (name.trim().length < 2 || busy) {
            return;
        }
        setBusy(true);
        // A plain request, not a form: this sits inside the prescription form, which must not be sent.
        router.post('/commands/prescriptions/diet-templates', { name, foods_allowed: linesOf(value.foods_allowed), foods_avoided: linesOf(value.foods_avoided) }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { setName(''); setError(null); setOpen(false); },
            onError: (errors) => setError(String(Object.values(errors)[0] ?? '')),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="rx-search">
            <input
                autoFocus
                minLength={2}
                maxLength={80}
                value={name}
                placeholder={translate('prescription', 'diet_name')}
                aria-label={translate('prescription', 'diet_name')}
                onChange={(e) => setName(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }}
            />
            <button type="button" className="button small dark" disabled={busy || name.trim().length < 2} onClick={save}>{translate('prescription', 'tpl_save')}</button>
            <button type="button" className="button small" onClick={() => setOpen(false)}>{translate('portal', 'cancel')}</button>
            {error && <span className="field-error" role="alert">{error}</span>}
        </div>
    );
}

/** Tests to have done, and foods to eat and to avoid, on the new-prescription form. */
export function ExtrasFields({ value, onChange, dietTemplates, errors, translate }: {
    value: ExtrasForm;
    onChange: (patch: Partial<ExtrasForm>) => void;
    dietTemplates: DietTemplateRow[];
    errors: Record<string, string>;
    translate: Translate;
}) {
    const [added, setAdded] = useState<string | null>(null);
    const hint = translate('prescription', 'rx_one_per_line');
    const listErrors = (field: keyof ExtrasForm) => Object.entries(errors)
        .filter(([key]) => key === field || key.startsWith(`${field}.`))
        .map(([key, message]) => <span key={key} className="field-error" role="alert">{String(message)}</span>);

    const addFrom = (id: string) => {
        const template = dietTemplates.find((row) => String(row.id) === id);
        if (!template) {
            return;
        }
        onChange({
            foods_allowed: addLines(value.foods_allowed, template.foods_allowed),
            foods_avoided: addLines(value.foods_avoided, template.foods_avoided),
        });
        setAdded(translate('prescription', 'diet_added', { name: template.name }));
    };

    return (
        <fieldset className="rx-lines rx-extras">
            <legend><ClipboardList size={16} /> {translate('prescription', 'rx_other_parts')}</legend>
            <label>{translate('prescription', 'rx_tests')}
                <textarea rows={3} value={value.investigations} placeholder={hint} onChange={(e) => onChange({ investigations: e.target.value })} />
                {listErrors('investigations')}
            </label>
            {dietTemplates.length > 0 && (
                <label className="rx-diet-pick">{translate('prescription', 'diet_pick')}
                    <SearchSelect
                        value=""
                        placeholder={translate('prescription', 'tpl_choose')}
                        options={dietTemplates.map((template) => ({ value: String(template.id), label: template.name, keywords: [...template.foods_allowed, ...template.foods_avoided].join(' ') }))}
                        onChange={(id) => addFrom(id)}
                    />
                    {added && <small className="muted" role="status">{added}</small>}
                </label>
            )}
            <div className="rx-grid">
                <label>{translate('prescription', 'rx_foods_allowed')}
                    <textarea rows={4} value={value.foods_allowed} placeholder={hint} onChange={(e) => onChange({ foods_allowed: e.target.value })} />
                    {listErrors('foods_allowed')}
                </label>
                <label>{translate('prescription', 'rx_foods_avoided')}
                    <textarea rows={4} value={value.foods_avoided} placeholder={hint} onChange={(e) => onChange({ foods_avoided: e.target.value })} />
                    {listErrors('foods_avoided')}
                </label>
            </div>
            <KeepAsDietTemplate value={value} translate={translate} />
        </fieldset>
    );
}
