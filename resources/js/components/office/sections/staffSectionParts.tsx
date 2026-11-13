import React, { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Empty, FormErrors, Modal, Table, label, type Row, type TableProps } from '../../UI';
import { randomUuid } from '../../../shared/randomUuid';
import { useT } from '../../../shared/i18n/t';
import { usePlacedFields, type ReasonPlace } from '../../../shared/reasonFields';
import { AutoSelect } from '../../SearchSelect';

export type StaffFieldOption = { value: string; label: string; channel?: string };
export type StaffField = { name: string; label: string; type?: string; options?: StaffFieldOption[]; search?: boolean; required?: boolean; value?: string; place?: ReasonPlace };
export type StaffAction = { title: string; action: string; fields: StaffField[]; defaults?: Row };
export type StaffTableFilter = { searchable?: boolean; statuses?: string[]; q?: string; status?: string };
export type StaffSectionTable = { key: string; page: Row & { data: Row[]; total?: number; last_page?: number }; filter?: StaffTableFilter };

export const commandRoot = '/commands/operations';

export const findTable = (tables: StaffSectionTable[], key: string): StaffSectionTable | undefined => tables.find((table) => table.key === key);

export const textField = (name: string, title: string, type = 'text', required = true): StaffField => ({ name, label: title, type, required });

export const pickField = (rows: Row[], name: string, title: string, required = true): StaffField => ({
    name,
    label: title,
    required,
    // People and products are searched; the few company payment accounts stay a plain list.
    search: name !== 'payment_account_id',
    options: rows.map((row) => ({ value: String(row.id), label: row.dealer_code ? `${row.name} · ${row.dealer_code}` : (row.name ?? `${row.label} · ${row.channel} · ${row.account_number}`) })),
});

/** Which of a page's views is showing. It lives in the address, so a refresh or a saved form comes back to it. */
export function currentView<T extends string>(url: string, views: T[], fallback: T): T {
    const chosen = new URLSearchParams(url.split('?')[1] ?? '').get('view') as T | null;

    return chosen !== null && views.includes(chosen) ? chosen : fallback;
}

/** The one "( A | B )" switch of a page. Every choice keeps the rest of the address. */
export function ViewSwitch<T extends string>({ views, active, fallback, label: groupLabel }: { views: { key: T; label: string }[]; active: T; fallback: T; label: string }) {
    const { url } = usePage();

    if (views.length < 2) {
        return null;
    }

    const hrefFor = (view: T): string => {
        const [path, query] = url.split('?');
        const params = new URLSearchParams(query ?? '');
        [...params.keys()].filter((key) => key === 'page' || /^page_\d+$/.test(key)).forEach((key) => params.delete(key));
        if (view === fallback) {
            params.delete('view');
        } else {
            params.set('view', view);
        }
        const text = params.toString();

        return text ? `${path}?${text}` : path;
    };

    return (
        <div className="office-switch" role="group" aria-label={groupLabel} style={{ maxWidth: "100%", overflowX: "auto" }}>
            {views.map((view) => (
                <Link key={view.key} href={hrefFor(view.key)} className={view.key === active ? 'is-active' : undefined} aria-current={view.key === active ? 'true' : undefined} preserveState preserveScroll replace only={['view']}>
                    {view.label}
                </Link>
            ))}
        </div>
    );
}

/** Search box and status choice for one paged table; both run on the server, so they cover every page. */
export function SectionTableSearch({ table }: { table: StaffSectionTable }) {
    const translate = useT();
    const filter = table.filter;
    const [words, setWords] = useState(filter?.q ?? '');
    const statuses = filter?.statuses ?? [];

    if (!filter?.searchable && statuses.length === 0) {
        return null;
    }

    const apply = (next: { q?: string; status?: string }) => {
        const params = new URLSearchParams(window.location.search);
        [...params.keys()].filter((key) => /^page_\d+$/.test(key)).forEach((key) => params.delete(key));
        const values = { q: next.q ?? words, status: next.status ?? filter?.status ?? '' };
        for (const [name, value] of Object.entries(values)) {
            if (value) {
                params.set(`${table.key}_${name}`, value);
            } else {
                params.delete(`${table.key}_${name}`);
            }
        }
        router.get(window.location.pathname, Object.fromEntries(params.entries()), { preserveState: true, preserveScroll: true });
    };

    return (
        <form className="filter-bar order-filters" onSubmit={(event) => { event.preventDefault(); apply({ q: words }); }}>
            {filter?.searchable && (
                <div className="order-search">
                    <div className="search-input">
                        <input aria-label={translate('office_staff', 'table_search')} placeholder={translate('office_staff', 'table_search')} value={words} onChange={(event) => setWords(event.target.value)} />
                    </div>
                    <button className="button small dark" type="submit">{translate('portal', 'search')}</button>
                </div>
            )}
            {statuses.length > 0 && (
                <select className="order-status-select" aria-label={translate('office_staff', 'col_status')} value={filter?.status ?? ''} onChange={(event) => apply({ status: event.target.value })}>
                    <option value="">{translate('office_staff', 'col_status')}</option>
                    {statuses.map((status) => <option key={status} value={status}>{label(status, translate)}</option>)}
                </select>
            )}
            {Boolean(filter?.q || filter?.status) && (
                <div className="order-filter-actions">
                    <button type="button" className="text-link" onClick={() => { setWords(''); apply({ q: '', status: '' }); }}>{translate('portal', 'clear')}</button>
                </div>
            )}
        </form>
    );
}

/** One form inside a dialog: same address and same fields as the page form it replaced. */
export function StaffActionForm({ title, action, fields, defaults = {}, onDone, onCancel }: StaffAction & { onDone?: () => void; onCancel: () => void }) {
    const translate = useT();
    fields = usePlacedFields(fields);
    const form = useForm<Row>({ ...Object.fromEntries(fields.map((field) => [field.name, field.type === 'file' ? null : (field.value ?? '')])), ...defaults });
    const [itemCount, setItemCount] = useState(1);
    const itemFields = fields.filter((field) => field.name.startsWith('items.0.'));
    const visibleFields = fields.flatMap((field) => field.name.startsWith('items.0.')
        ? Array.from({ length: itemCount }, (_, index) => ({ ...field, name: field.name.replace('items.0.', `items.${index}.`), label: `${field.label} ${index + 1}` }))
        : [field]);
    const setValue = (name: string, value: unknown) => form.setData((current: Row) => ({ ...current, [name]: value }));
    const change = (name: string, value: unknown) => {
        if (name === 'channel' && 'payment_account_id' in form.data) {
            form.setData((current: Row) => ({ ...current, channel: value, payment_account_id: '' }));
        } else {
            setValue(name, value);
        }
    };
    const addItem = () => {
        form.setData((current: Row) => {
            const next = { ...current };
            for (const field of itemFields) {
                next[field.name.replace('items.0.', `items.${itemCount}.`)] = field.name.endsWith('.quantity') ? '1' : '';
            }

            return next;
        });
        setItemCount(itemCount + 1);
    };
    const removeLastItem = () => {
        form.setData((current: Row) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith(`items.${itemCount - 1}.`))));
        setItemCount(itemCount - 1);
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((input) => {
            const data: Row = {};
            for (const [name, value] of Object.entries(input)) {
                const required = visibleFields.find((field) => field.name === name)?.required;
                if (value === '' && !required) {
                    continue;
                }
                const path = name.split('.');
                let target = data;
                path.forEach((part, index) => {
                    if (index === path.length - 1) {
                        target[part] = value;
                    } else {
                        target[part] ??= /^\d+$/.test(path[index + 1]) ? [] : {};
                        target = target[part];
                    }
                });
            }

            return data;
        });
        form.post(action, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setItemCount(1);
                for (const key of ['idempotency_key', 'request_key']) {
                    if (key in form.data) {
                        setValue(key, randomUuid());
                    }
                }
                onDone?.();
            },
        });
    };

    return (
        <form className="action-form" onSubmit={submit} aria-label={title}>
            {visibleFields.map((field) => {
                const choices = field.name === 'payment_account_id' ? field.options?.filter((option) => option.channel === undefined || option.channel === form.data.channel) : field.options;
                const value = form.data[field.name] ?? field.value ?? '';
                const fileTypes = 'application/pdf,image/jpeg,image/png';

                return (
                    <label key={field.name}>
                        {field.label}
                        {choices ? (
                            <AutoSelect
                                search={field.search}
                                required={field.required}
                                value={String(value)}
                                onChange={(picked) => change(field.name, picked)}
                                emptyLabel={translate('portal', 'ops_fld_select')}
                                options={choices.map((option) => ({ value: option.value, label: option.label }))}
                            />
                        ) : field.type === 'textarea' ? (
                            <textarea rows={3} required={field.required} value={value} onChange={(event) => change(field.name, event.target.value)} />
                        ) : field.type === 'file' ? (
                            <input required={field.required} type="file" accept={fileTypes} onChange={(event) => change(field.name, event.target.files?.[0] ?? null)} />
                        ) : (
                            <input required={field.required} type={field.type ?? 'text'} step={field.type === 'number' ? (field.name.endsWith('.quantity') ? '1' : '0.01') : undefined} value={value} onChange={(event) => change(field.name, event.target.value)} />
                        )}
                        {form.errors[field.name] && <span className="field-error">{String(form.errors[field.name])}</span>}
                    </label>
                );
            })}
            {itemFields.length > 0 && (
                <div className="ops-row">
                    <button className="button" type="button" disabled={itemCount >= 100} onClick={addItem}>{translate('portal', 'ops_add_product')}</button>
                    {itemCount > 1 && <button className="button" type="button" onClick={removeLastItem}>{translate('portal', 'ops_remove_last_product')}</button>}
                </div>
            )}
            <FormErrors errors={form.errors} besideFields={visibleFields.map((field) => field.name)} />
            {form.progress && <progress max={100} value={form.progress.percentage} aria-label={translate('portal', 'ops_upload_progress')} />}
            <div className="ops-row is-end is-loose">
                <button type="button" className="button" disabled={form.processing} onClick={onCancel}>{translate('portal', 'emp_cancel')}</button>
                <button className="button dark" disabled={form.processing}>{form.processing ? translate('portal', 'ops_recording') : title}</button>
            </div>
        </form>
    );
}

/** A dialog titled with its action. */
export function StaffActionDialog({ action, onClose, wide = false, before }: { action: StaffAction; onClose: () => void; wide?: boolean; before?: React.ReactNode }) {
    return (
        <Modal title={action.title} onClose={onClose} variant={wide ? 'wide' : 'dialog'}>
            {before}
            <StaffActionForm {...action} onDone={onClose} onCancel={onClose} />
        </Modal>
    );
}

/** Stored details, shown as plain label and value lines. */
export function StoredDetails({ value }: { value: unknown }): React.ReactNode {
    const translate = useT();

    if (value === null || value === undefined) {
        return '—';
    }
    if (typeof value !== 'object') {
        return typeof value === 'boolean' ? translate('portal', value ? 'ops_fld_yes' : 'ops_fld_no') : String(value);
    }

    return <dl className="opr-detail">{Object.entries(value).map(([key, item]) => <div key={key}><dt>{label(key, translate)}</dt><dd><StoredDetails value={item} /></dd></div>)}</dl>;
}

/** Stored JSON from the database, or the text as it is when it cannot be read. */
export function readStored(value: unknown): unknown {
    if (typeof value !== 'string') {
        return value;
    }
    try {
        return JSON.parse(value);
    } catch {
        return value;
    }
}

/** Label and value lines for a side drawer. A value left empty is not shown. */
export function DrawerLines({ lines }: { lines: { label: string; value: React.ReactNode }[] }) {
    const shown = lines.filter((line) => line.value !== null && line.value !== undefined && line.value !== '');

    return (
        <dl className="office-facts">
            {shown.map((line) => <div key={line.label}><dt>{line.label}</dt><dd>{line.value}</dd></div>)}
        </dl>
    );
}

/** A server-paged table with no search of its own; with no rows it is one quiet line. */
export function StaffTable({ empty, rows, ...rest }: TableProps<Row> & { empty: string }) {
    if (!rows || rows.length === 0) {
        return <Empty line title={empty} />;
    }

    return <Table rows={rows} {...rest} />;
}
