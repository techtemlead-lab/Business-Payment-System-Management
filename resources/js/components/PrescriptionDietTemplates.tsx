import React, { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Salad, Trash2 } from 'lucide-react';
import { Empty, FormErrors } from './UI';
import { linesOf, type DietTemplateRow } from './PrescriptionExtras';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

/** The prescriber's own food templates on the Templates tab: write one, change it, delete it. */
export function DietTemplatesSection({ templates, translate }: { templates: DietTemplateRow[]; translate: Translate }) {
    const [editing, setEditing] = useState<DietTemplateRow | null>(null);
    const form = useForm({ name: '', foods_allowed: '', foods_avoided: '' });
    const hint = translate('prescription', 'rx_one_per_line');

    useEffect(() => {
        form.clearErrors();
        form.setData(editing
            ? { name: editing.name, foods_allowed: editing.foods_allowed.join('\n'), foods_avoided: editing.foods_avoided.join('\n') }
            : { name: '', foods_allowed: '', foods_avoided: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ name: values.name, foods_allowed: linesOf(values.foods_allowed), foods_avoided: linesOf(values.foods_avoided) }));
        const options = { preserveScroll: true, onSuccess: () => { setEditing(null); form.reset(); } };
        if (editing) {
            form.patch(`/commands/prescriptions/diet-templates/${editing.id}`, options);
        } else {
            form.post('/commands/prescriptions/diet-templates', options);
        }
    };

    const remove = (template: DietTemplateRow) => {
        if (window.confirm(translate('prescription', 'diet_delete_confirm'))) {
            router.delete(`/commands/prescriptions/diet-templates/${template.id}`, { preserveScroll: true, onSuccess: () => { if (editing?.id === template.id) setEditing(null); } });
        }
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2 className="ops-row is-inline"><Salad size={18} /> {translate('prescription', 'diet_title')}</h2>
                    <p className="muted">{translate('prescription', 'diet_intro')}</p>
                </div>
            </div>
            <form className="action-form rx-form" onSubmit={submit}>
                <b>{translate('prescription', editing ? 'diet_edit' : 'diet_new')}</b>
                <label>{translate('prescription', 'diet_name')}
                    <input required minLength={2} maxLength={80} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </label>
                <div className="rx-grid">
                    <label>{translate('prescription', 'rx_foods_allowed')}
                        <textarea rows={4} value={form.data.foods_allowed} placeholder={hint} onChange={(e) => form.setData('foods_allowed', e.target.value)} />
                    </label>
                    <label>{translate('prescription', 'rx_foods_avoided')}
                        <textarea rows={4} value={form.data.foods_avoided} placeholder={hint} onChange={(e) => form.setData('foods_avoided', e.target.value)} />
                    </label>
                </div>
                <FormErrors errors={form.errors} />
                <div className="rx-actions">
                    <button className="button dark" disabled={form.processing}>{translate('prescription', 'diet_save')}</button>
                    {editing && <button type="button" className="button small" onClick={() => setEditing(null)}>{translate('portal', 'cancel')}</button>}
                </div>
            </form>
            {templates.length === 0 ? (
                <Empty title={translate('prescription', 'diet_empty')} body={translate('prescription', 'diet_empty_body')} />
            ) : (
                <ul className="rx-list rx-diet-list">
                    {templates.map((template) => (
                        <li key={template.id} className="rx-card">
                            <div className="rx-card-head">
                                <b>{template.name}</b>
                                <span className="rx-actions">
                                    <button type="button" className="icon-button" aria-label={translate('prescription', 'diet_edit')} onClick={() => { setEditing(template); }}>
                                        <Pencil size={16} />
                                    </button>
                                    <button type="button" className="icon-button" aria-label={translate('prescription', 'tpl_delete')} onClick={() => remove(template)}>
                                        <Trash2 size={16} />
                                    </button>
                                </span>
                            </div>
                            {template.foods_allowed.length > 0 && <small><b>{translate('prescription', 'rx_foods_allowed')}:</b> {template.foods_allowed.join(', ')}</small>}
                            {template.foods_avoided.length > 0 && <small><b>{translate('prescription', 'rx_foods_avoided')}:</b> {template.foods_avoided.join(', ')}</small>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
