import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { DateInput, FormErrors, Modal, Table, date, number } from '../UI';
import OfficePage from '../office/OfficePage';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import SearchSelect, { SearchCheckList } from '../SearchSelect';

type Scheme = { id: number; title: string; starts_on: string; ends_on: string; product_ids: number[]; min_quantity: number; benefit_type: string; benefit_text: string; free_product_id: number | null; free_quantity: number | null; orders_using: number; earliest_end: string | null };
type Product = { id: number; name: string; active: boolean };
type SchemesData = { schemes: Scheme[]; benefit_types: string[]; products: Product[] };
type SchemeValues = { title: string; starts_on: string; ends_on: string; product_ids: string[]; min_quantity: string; benefit_type: string; benefit_text: string; free_product_id: string; free_quantity: string };

/** Fields that show their own refusal under the box. */
const FIELDS_WITH_OWN_ERROR = ['title', 'starts_on', 'ends_on', 'product_ids', 'min_quantity', 'benefit_type', 'benefit_text', 'free_product_id', 'free_quantity', 'reason'];

function valuesOf(scheme: Scheme): SchemeValues {
    return {
        title: scheme.title,
        starts_on: scheme.starts_on,
        ends_on: scheme.ends_on,
        product_ids: scheme.product_ids.map(String),
        min_quantity: String(scheme.min_quantity),
        benefit_type: scheme.benefit_type,
        benefit_text: scheme.benefit_text,
        free_product_id: scheme.free_product_id === null ? '' : String(scheme.free_product_id),
        free_quantity: scheme.free_quantity === null ? '' : String(scheme.free_quantity),
    };
}

/** The offer itself. Once orders have got free goods from a scheme these boxes are locked and always show what is saved. */
function offerOf(scheme: Scheme): Partial<SchemeValues> {
    const { starts_on, product_ids, min_quantity, benefit_type, free_product_id, free_quantity } = valuesOf(scheme);
    return { starts_on, product_ids, min_quantity, benefit_type, free_product_id, free_quantity };
}

function SchemeFields({ values, change, errors, products, benefitTypes, offerLocked = false, endHint }: {
    values: SchemeValues;
    change: (next: Partial<SchemeValues>) => void;
    errors: Record<string, string | undefined>;
    products: Product[];
    benefitTypes: string[];
    offerLocked?: boolean;
    endHint?: string;
}) {
    const translate = useT();
    const problem = (name: string) => (errors[name] ? <span className="field-error" role="alert">{errors[name]}</span> : null);
    return (
        <>
            <label>{translate('dealer_tools', 'scheme_title_field')}<input required maxLength={150} value={values.title} onChange={(e) => change({ title: e.target.value })} />{problem('title')}</label>
            <label>{translate('dealer_tools', 'scheme_starts')}<DateInput required disabled={offerLocked} value={values.starts_on} onChange={(e) => change({ starts_on: e.target.value })} />{problem('starts_on')}</label>
            <label>{translate('dealer_tools', 'scheme_ends')}<DateInput required value={values.ends_on} onChange={(e) => change({ ends_on: e.target.value })} />{endHint && <small className="field-hint">{endHint}</small>}{problem('ends_on')}</label>
            {/* Not a <label>: every row of the list is a label of its own. */}
            <div className="ss-field">
                <span>{translate('dealer_tools', 'scheme_products')}</span>
                <SearchCheckList
                    ariaLabel={translate('dealer_tools', 'scheme_products')}
                    disabled={offerLocked}
                    values={values.product_ids}
                    onChange={(productIds) => change({ product_ids: productIds })}
                    options={products.map((product) => ({ value: String(product.id), label: product.name }))}
                />
                {problem('product_ids')}
            </div>
            <label>{translate('dealer_tools', 'scheme_min_field')}<input type="number" min={1} required disabled={offerLocked} value={values.min_quantity} onChange={(e) => change({ min_quantity: e.target.value })} />{problem('min_quantity')}</label>
            <label>{translate('dealer_tools', 'scheme_type_field')}
                <select disabled={offerLocked} value={values.benefit_type} onChange={(e) => change({ benefit_type: e.target.value, free_product_id: '', free_quantity: '' })}>
                    {benefitTypes.map((type) => <option key={type} value={type}>{translate('dealer_tools', `scheme_type_${type}`)}</option>)}
                </select>
                {problem('benefit_type')}
            </label>
            <label>{translate('dealer_tools', 'scheme_benefit_field')}<textarea required rows={3} maxLength={500} value={values.benefit_text} onChange={(e) => change({ benefit_text: e.target.value })} />{problem('benefit_text')}</label>
            {values.benefit_type === 'free_goods' && <>
                <p className="muted">{translate('schemes_commission', 'scheme_free_hint')}</p>
                <label>{translate('schemes_commission', 'scheme_free_product')}
                    <SearchSelect
                        disabled={offerLocked}
                        value={values.free_product_id}
                        onChange={(value) => change({ free_product_id: value, free_quantity: value === '' ? '' : values.free_quantity })}
                        emptyLabel={translate('schemes_commission', 'scheme_free_none')}
                        options={products.map((product) => ({ value: String(product.id), label: product.name }))}
                    />
                    {problem('free_product_id')}
                </label>
                {values.free_product_id !== '' && <label>{translate('schemes_commission', 'scheme_free_quantity')}<input type="number" min={1} required disabled={offerLocked} value={values.free_quantity} onChange={(e) => change({ free_quantity: e.target.value })} />{problem('free_quantity')}</label>}
            </>}
        </>
    );
}

function SchemeEditor({ scheme, data, onDone }: { scheme: Scheme; data: SchemesData; onDone: () => void }) {
    const translate = useT();
    const form = useForm<SchemeValues & { reason: string }>({ ...valuesOf(scheme), reason: '' });
    const why = useReasonField('partner.scheme_edit');
    const used = scheme.orders_using > 0;
    const offer = used ? offerOf(scheme) : {};
    const products = data.products.filter((product) => product.active || scheme.product_ids.includes(product.id) || scheme.free_product_id === product.id);
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ ...values, ...offer }));
        form.patch(`/commands/schemes/${scheme.id}`, { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <form className="action-form" onSubmit={submit}>
            {used && <div className="notice" role="status">{translate('dealer_tools', 'scheme_locked_note', { count: number(scheme.orders_using) })}</div>}
            <SchemeFields
                values={{ ...form.data, ...offer }}
                change={(next) => form.setData({ ...form.data, ...next })}
                errors={form.errors}
                products={products}
                benefitTypes={data.benefit_types}
                offerLocked={used}
                endHint={scheme.earliest_end ? translate('dealer_tools', 'scheme_earliest_end_hint', { date: date(scheme.earliest_end) }) : undefined}
            />
            {why.shown && <label>{why.label(translate('dealer_tools', 'edit_reason_field'))}<textarea required={why.required} rows={2} minLength={why.required ? 5 : undefined} maxLength={1000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /><small className="field-hint">{why.required ? translate('dealer_tools', 'edit_reason_hint') : translate('reason_fields', 'hint_kept_in_record')}</small>{form.errors.reason && <span className="field-error" role="alert">{form.errors.reason}</span>}</label>}
            <FormErrors errors={form.errors} besideFields={FIELDS_WITH_OWN_ERROR} />
            <button type="submit" className="button dark" disabled={form.processing}>{translate('portal', form.processing ? 'saving' : 'save_changes')}</button>
        </form>
    );
}

/** "স্কিম": dated schemes the owner defines. A free product and quantity make a scheme add free order lines. */
export default function SchemesHub({ data }: { data: SchemesData }) {
    const translate = useT();
    const hub = usePage<any>().props.data?.hub ?? null;
    const form = useForm<SchemeValues>({ title: '', starts_on: '', ends_on: '', product_ids: [], min_quantity: '1', benefit_type: 'free_goods', benefit_text: '', free_product_id: '', free_quantity: '' });
    const [adding, setAdding] = useState(false);
    const [openedId, setOpenedId] = useState<number | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const opened = data.schemes.find((scheme) => scheme.id === openedId) ?? null;
    const editing = data.schemes.find((scheme) => scheme.id === editingId) ?? null;
    const closeAdding = () => {
        setAdding(false);
        form.reset();
        form.clearErrors();
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/commands/schemes', { preserveScroll: true, onSuccess: closeAdding });
    };
    const remove = (scheme: Scheme) => {
        if (window.confirm(translate('dealer_tools', 'scheme_remove_confirm', { title: scheme.title }))) {
            router.delete(`/commands/schemes/${scheme.id}`, { preserveScroll: true, onSuccess: () => setOpenedId(null) });
        }
    };
    const nameOf = (id: number) => data.products.find((product) => product.id === id)?.name ?? `#${id}`;
    const period = (scheme: Scheme) => `${date(scheme.starts_on)} - ${date(scheme.ends_on)}`;
    const typeName = (scheme: Scheme) => translate('dealer_tools', `scheme_type_${scheme.benefit_type}`);
    const freeLine = (scheme: Scheme) => (scheme.free_product_id !== null
        ? translate('schemes_commission', 'scheme_free_summary', { quantity: number(scheme.free_quantity ?? 0), product: nameOf(scheme.free_product_id) })
        : '');

    return (
        <OfficePage
            title={hub?.label ?? translate('office_website', 'partners_title')}
            hub={hub}
            action={<button type="button" className="button dark" onClick={() => setAdding(true)}><Plus size={17} aria-hidden="true" />{translate('dealer_tools', 'scheme_add_title')}</button>}
        >
            <Table
                rows={data.schemes}
                phoneColumns={['title', 'starts_on']}
                onRowClick={(row: Scheme) => setOpenedId(row.id)}
                empty={translate('dealer_tools', 'scheme_empty_title')}
                emptyBody=""
                columns={[
                    { key: 'title', title: translate('dealer_tools', 'scheme_title_field'), sortable: true, render: (scheme: Scheme) => <><button type="button" className="text-link" onClick={() => setOpenedId(scheme.id)}>{scheme.title}</button><small className="cell-sub">{scheme.benefit_text}</small></> },
                    { key: 'starts_on', title: translate('office_website', 'col_period'), sortable: true, render: (scheme: Scheme) => period(scheme) },
                    { key: 'min_quantity', title: translate('office_website', 'col_condition'), render: (scheme: Scheme) => <>{translate('dealer_tools', 'scheme_min', { count: number(scheme.min_quantity) })}<small className="cell-sub">{typeName(scheme)}</small></> },
                    { key: 'free_product_id', title: translate('schemes_commission', 'scheme_free_product'), render: (scheme: Scheme) => freeLine(scheme) || '—' },
                    { key: 'orders_using', title: translate('office_website', 'col_orders_using'), align: 'right', sortable: true, render: (scheme: Scheme) => number(scheme.orders_using) },
                ]}
            />
            {adding && (
                <Modal title={translate('dealer_tools', 'scheme_add_title')} onClose={closeAdding}>
                    <form className="action-form" onSubmit={submit}>
                        <SchemeFields values={form.data} change={(next) => form.setData({ ...form.data, ...next })} errors={form.errors} products={data.products.filter((product) => product.active)} benefitTypes={data.benefit_types} />
                        <FormErrors errors={form.errors} besideFields={FIELDS_WITH_OWN_ERROR} />
                        <button type="submit" className="button dark" disabled={form.processing}>{translate('dealer_tools', 'scheme_save')}</button>
                    </form>
                </Modal>
            )}
            {opened && (
                <Modal variant="drawer" title={opened.title} onClose={() => setOpenedId(null)} footer={<>
                        <button type="button" className="button small dark" onClick={() => { setOpenedId(null); setEditingId(opened.id); }}><Pencil size={14} aria-hidden="true" /> {translate('office_website', 'edit')}</button>
                        <button type="button" className="button small danger" onClick={() => remove(opened)}><Trash2 size={14} aria-hidden="true" /> {translate('dealer_tools', 'scheme_remove')}</button>
                </>}>
                    <dl className="office-facts">
                        <div><dt>{translate('dealer_tools', 'scheme_benefit_field')}</dt><dd>{opened.benefit_text}</dd></div>
                        <div><dt>{translate('office_website', 'col_period')}</dt><dd>{period(opened)}</dd></div>
                        <div><dt>{translate('office_website', 'col_condition')}</dt><dd>{translate('dealer_tools', 'scheme_min', { count: number(opened.min_quantity) })} · {typeName(opened)}</dd></div>
                        <div><dt>{translate('office_website', 'col_products')}</dt><dd>{opened.product_ids.map(nameOf).join(', ')}</dd></div>
                        {opened.free_product_id !== null && <div><dt>{translate('schemes_commission', 'scheme_free_product')}</dt><dd>{freeLine(opened)}</dd></div>}
                        <div><dt>{translate('office_website', 'col_orders_using')}</dt><dd>{number(opened.orders_using)}</dd></div>
                    </dl>
                </Modal>
            )}
            {editing && (
                <Modal title={translate('office_website', 'scheme_edit_title')} onClose={() => setEditingId(null)}>
                    <SchemeEditor scheme={editing} data={data} onDone={() => setEditingId(null)} />
                </Modal>
            )}
        </OfficePage>
    );
}
