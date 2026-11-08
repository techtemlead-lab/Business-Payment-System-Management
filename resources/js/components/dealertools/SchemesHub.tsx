import React from 'react';
import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { DateInput, Empty, FormErrors, date, number } from '../UI';
import { useT } from '../../shared/i18n/t';

type Scheme = { id: number; title: string; starts_on: string; ends_on: string; product_ids: number[]; min_quantity: number; benefit_type: string; benefit_text: string };
type SchemesData = { schemes: Scheme[]; benefit_types: string[]; products: { id: number; name: string }[] };

/** Owner page: define dated schemes that partners read while ordering. Display only. */
export default function SchemesHub({ data }: { data: SchemesData }) {
    const translate = useT();
    const form = useForm({ title: '', starts_on: '', ends_on: '', product_ids: [] as string[], min_quantity: '1', benefit_type: 'free_goods', benefit_text: '' });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/commands/schemes', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const remove = (scheme: Scheme) => {
        if (window.confirm(translate('dealer_tools', 'scheme_remove_confirm', { title: scheme.title }))) {
            router.delete(`/commands/schemes/${scheme.id}`, { preserveScroll: true });
        }
    };
    const nameOf = (id: number) => data.products.find((product) => product.id === id)?.name ?? `#${id}`;
    return (
        <>
            <section className="panel narrow-panel">
                <h2>{translate('dealer_tools', 'scheme_add_title')}</h2>
                <p className="muted">{translate('dealer_tools', 'scheme_owner_hint')}</p>
                <form className="action-form" onSubmit={submit}>
                    <label>{translate('dealer_tools', 'scheme_title_field')}<input required maxLength={150} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></label>
                    <label>{translate('dealer_tools', 'scheme_starts')}<DateInput value={form.data.starts_on} onChange={(value: string) => form.setData('starts_on', value)} /></label>
                    <label>{translate('dealer_tools', 'scheme_ends')}<DateInput value={form.data.ends_on} onChange={(value: string) => form.setData('ends_on', value)} /></label>
                    <label>{translate('dealer_tools', 'scheme_products')}
                        <select multiple size={6} required value={form.data.product_ids} onChange={(e) => form.setData('product_ids', Array.from(e.target.selectedOptions).map((option) => option.value))}>
                            {data.products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                        </select>
                    </label>
                    <label>{translate('dealer_tools', 'scheme_min_field')}<input type="number" min={1} required value={form.data.min_quantity} onChange={(e) => form.setData('min_quantity', e.target.value)} /></label>
                    <label>{translate('dealer_tools', 'scheme_type_field')}
                        <select value={form.data.benefit_type} onChange={(e) => form.setData('benefit_type', e.target.value)}>
                            {data.benefit_types.map((type) => <option key={type} value={type}>{translate('dealer_tools', `scheme_type_${type}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('dealer_tools', 'scheme_benefit_field')}<textarea required rows={3} maxLength={500} value={form.data.benefit_text} onChange={(e) => form.setData('benefit_text', e.target.value)} /></label>
                    <FormErrors errors={form.errors} />
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('dealer_tools', 'scheme_save')}</button>
                </form>
            </section>
            <section className="panel">
                <h2>{translate('dealer_tools', 'scheme_list_title')}</h2>
                {data.schemes.length === 0
                    ? <Empty title={translate('dealer_tools', 'scheme_empty_title')} body={translate('dealer_tools', 'scheme_empty_body')} />
                    : data.schemes.map((scheme) => (
                        <article key={scheme.id} className="notification">
                            <div>
                                <b>{scheme.title}</b>
                                <p>{scheme.benefit_text}</p>
                                <small>{date(scheme.starts_on)} - {date(scheme.ends_on)} · {translate('dealer_tools', 'scheme_min', { count: number(scheme.min_quantity) })} · {translate('dealer_tools', `scheme_type_${scheme.benefit_type}`)}</small>
                                <small className="cell-sub">{scheme.product_ids.map(nameOf).join(', ')}</small>
                                <button type="button" className="button small" onClick={() => remove(scheme)}><Trash2 size={14} /> {translate('dealer_tools', 'scheme_remove')}</button>
                            </div>
                        </article>
                    ))}
            </section>
        </>
    );
}
