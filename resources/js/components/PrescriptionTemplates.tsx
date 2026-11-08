import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { BookmarkPlus, Trash2 } from 'lucide-react';
import { Empty } from './UI';
import { useT } from '../shared/i18n/t';
import type { AccessLogRow } from './PatientAccessLogList';
import type { LineForm } from './PrescriptionsHub';
import type { FollowUpsData } from './PrescriptionFollowUps';
import type { PrescriberReportData } from './PrescriberReport';

type Translate = ReturnType<typeof useT>;

export type TemplateRow = {
    id: number;
    name: string;
    advice: string | null;
    lines: {
        product_id: number | null;
        medicine_name: string;
        dose: string | null;
        frequency: string | null;
        duration: string | null;
        instructions: string | null;
        dose_schedule?: string | null;
        food_timing?: string | null;
        duration_count?: number | null;
        duration_unit?: string | null;
        unit_price: string;
        quantity: number;
        discount: string;
    }[];
};

export type PrescriptionTools = {
    access_log: AccessLogRow[];
    templates: TemplateRow[];
    follow_ups: FollowUpsData;
    report: PrescriberReportData;
};

/** Turns saved lines into form lines; a product no longer sold is left blank to choose again. */
export function linesFromSaved(saved: TemplateRow['lines'], products: { id: number }[]): { lines: LineForm[]; productMissing: boolean } {
    let productMissing = false;
    const lines = saved.map((line): LineForm => {
        const stillSold = line.product_id === null || products.some((product) => product.id === line.product_id);
        productMissing = productMissing || !stillSold;
        return {
            product_id: line.product_id !== null && stillSold ? String(line.product_id) : '',
            medicine_name: line.medicine_name,
            dose: line.dose ?? '',
            frequency: line.frequency ?? '',
            duration: line.duration ?? '',
            instructions: line.instructions ?? '',
            dose_schedule: line.dose_schedule ?? '',
            food_timing: line.food_timing ?? '',
            duration_count: line.duration_count ? String(line.duration_count) : '',
            duration_unit: line.duration_unit ?? '',
            unit_price: line.unit_price,
            quantity: String(line.quantity),
            discount: Number(line.discount) > 0 ? line.discount : '',
        };
    });
    return { lines, productMissing };
}

/** Fills the new-prescription form from a saved template; nothing is saved until the form is. */
export function TemplatePicker({ templates, products, isDirty, onApply, translate }: {
    templates: TemplateRow[];
    products: { id: number }[];
    isDirty: boolean;
    onApply: (draft: { advice: string; lines: LineForm[] }) => void;
    translate: Translate;
}) {
    const [productMissing, setProductMissing] = useState(false);

    if (templates.length === 0) {
        return null;
    }

    const apply = (id: string) => {
        const template = templates.find((row) => String(row.id) === id);
        if (!template || (isDirty && !window.confirm(translate('prescription', 'tpl_replace_confirm')))) {
            return;
        }
        const { lines, productMissing: missing } = linesFromSaved(template.lines, products);
        setProductMissing(missing);
        onApply({ advice: template.advice ?? '', lines });
    };

    return (
        <label>{translate('prescription', 'tpl_start_from')}
            <select value="" onChange={(e) => apply(e.target.value)}>
                <option value="">{translate('prescription', 'tpl_choose')}</option>
                {templates.map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}
            </select>
            {productMissing && <span className="field-error" role="alert">{translate('prescription', 'tpl_product_gone')}</span>}
        </label>
    );
}

/** Button on a prescription card that opens a small form asking for the template's name. */
export function SaveAsTemplate({ prescriptionId, translate }: { prescriptionId: number; translate: Translate }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', prescription_id: prescriptionId });

    if (!open) {
        return (
            <button type="button" className="button small" onClick={() => setOpen(true)}>
                <BookmarkPlus size={15} /> {translate('prescription', 'tpl_save_as')}
            </button>
        );
    }

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/commands/prescriptions/templates', {
            preserveScroll: true,
            onSuccess: () => { form.reset('name'); setOpen(false); },
        });
    };

    return (
        <form className="rx-search" onSubmit={submit}>
            <input
                required
                autoFocus
                minLength={2}
                maxLength={80}
                value={form.data.name}
                placeholder={translate('prescription', 'tpl_name')}
                aria-label={translate('prescription', 'tpl_name')}
                onChange={(e) => form.setData('name', e.target.value)}
            />
            <button className="button small dark" disabled={form.processing}>{translate('prescription', 'tpl_save')}</button>
            <button type="button" className="button small" onClick={() => setOpen(false)}>{translate('portal', 'cancel')}</button>
            {form.errors.name && <span className="field-error" role="alert">{form.errors.name}</span>}
        </form>
    );
}

export function TemplatesTab({ templates, translate }: { templates: TemplateRow[]; translate: Translate }) {
    const remove = (template: TemplateRow) => {
        if (window.confirm(translate('prescription', 'tpl_delete_confirm'))) {
            router.delete(`/commands/prescriptions/templates/${template.id}`, { preserveScroll: true });
        }
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('prescription', 'tpl_title')}</h2>
                    <p className="muted">{translate('prescription', 'tpl_intro')}</p>
                </div>
            </div>
            {templates.length === 0 ? (
                <Empty title={translate('prescription', 'tpl_empty')} body={translate('prescription', 'tpl_empty_body')} />
            ) : (
                <ul className="rx-list">
                    {templates.map((template) => (
                        <li key={template.id} className="rx-card">
                            <div className="rx-card-head">
                                <b>{template.name}</b>
                                <button type="button" className="icon-button" aria-label={translate('prescription', 'tpl_delete')} onClick={() => remove(template)}>
                                    <Trash2 size={16} />
                                </button>
                            </div>
                            <ol className="rx-card-lines">
                                {template.lines.map((line, index) => (
                                    <li key={index}>{line.medicine_name} × {line.quantity}<small>{[line.dose_schedule, line.dose, line.frequency, line.duration].filter(Boolean).join(' · ')}</small></li>
                                ))}
                            </ol>
                            {template.advice && <small className="muted">{translate('prescription', 'rx_advice')}: {template.advice}</small>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
