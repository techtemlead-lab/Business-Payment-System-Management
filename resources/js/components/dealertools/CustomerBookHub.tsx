import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircle2, Pencil, Trash2 } from 'lucide-react';
import { ActionForm, Badge, Empty, Pagination, date, type Field, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

type CustomerBookData = { customers: Row; today: string; due_count: number };

/** A partner's own customers. Nobody else can open this page, not even the owner. */
export default function CustomerBookHub({ data, filters }: { data: CustomerBookData; filters: Row }) {
    const translate = useT();
    const [editing, setEditing] = useState<number | null>(null);
    const [search, setSearch] = useState(String(filters?.q ?? ''));
    const status = String(filters?.status ?? '');
    const rows: Row[] = data?.customers?.data ?? [];
    const fields: Field[] = [
        { name: 'name', label: translate('dealer_tools', 'customer_name'), required: true },
        { name: 'phone', label: translate('dealer_tools', 'customer_phone') },
        { name: 'notes', label: translate('dealer_tools', 'customer_notes'), type: 'textarea' },
        { name: 'follow_up_on', label: translate('dealer_tools', 'customer_follow_up'), type: 'date' },
    ];
    const go = (next: Row) => router.get('/customers', Object.fromEntries(Object.entries({ q: search, status, ...next }).filter(([, value]) => value !== '' && value != null)) as Record<string, string>, { preserveState: true, preserveScroll: true });
    const followUpDone = (id: number) => router.post(`/commands/customers/${id}/follow-up-done`, {}, { preserveScroll: true });
    const remove = (row: Row) => {
        if (window.confirm(translate('dealer_tools', 'customer_remove_confirm', { name: String(row.name) }))) {
            router.delete(`/commands/customers/${row.id}`, { preserveScroll: true });
        }
    };
    return (
        <>
            <section className="panel narrow-panel">
                <h2>{translate('dealer_tools', 'customer_add_title')}</h2>
                <p className="muted">{translate('dealer_tools', 'customer_private_hint')}</p>
                <ActionForm action="/commands/customers" fields={fields} submit={translate('dealer_tools', 'customer_save')} />
            </section>
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('dealer_tools', 'customer_list_title')}</h2>
                        <p className="muted">{translate('dealer_tools', 'customer_due_count', { count: String(data?.due_count ?? 0) })}</p>
                    </div>
                </div>
                <form className="filter-bar" onSubmit={(event) => { event.preventDefault(); go({}); }}>
                    <input aria-label={translate('dealer_tools', 'customer_search')} placeholder={translate('dealer_tools', 'customer_search')} value={search} onChange={(event) => setSearch(event.target.value)} />
                    <button type="submit" className="button small">{translate('dealer_tools', 'customer_search')}</button>
                    <button type="button" className="button small" aria-pressed={status === 'due'} onClick={() => go({ status: status === 'due' ? '' : 'due' })}>{translate('dealer_tools', 'customer_due_only')}</button>
                </form>
                {rows.length === 0
                    ? <Empty title={translate('dealer_tools', 'customer_empty_title')} body={translate('dealer_tools', 'customer_empty_body')} />
                    : rows.map((row) => {
                        const isDue = row.follow_up_on && String(row.follow_up_on).slice(0, 10) <= data.today;
                        return (
                            <article key={row.id} className="notification">
                                <div>
                                    <b>{row.name}</b>
                                    {row.phone && <> · <a className="text-link" href={`tel:${row.phone}`}>{row.phone}</a></>}
                                    {row.notes && <p className="ptl-prewrap">{row.notes}</p>}
                                    {row.follow_up_on && <small>{translate('dealer_tools', 'customer_follow_up')}: {date(row.follow_up_on)} {isDue && <Badge status="due" />}</small>}
                                    <div className="ops-row is-wrap">
                                        {row.follow_up_on && <button type="button" className="button small" onClick={() => followUpDone(row.id)}><CheckCircle2 size={14} /> {translate('dealer_tools', 'customer_mark_done')}</button>}
                                        <button type="button" className="button small" onClick={() => setEditing(editing === row.id ? null : row.id)}><Pencil size={14} /> {translate('dealer_tools', 'customer_edit')}</button>
                                        <button type="button" className="button small" onClick={() => remove(row)}><Trash2 size={14} /> {translate('dealer_tools', 'customer_remove')}</button>
                                    </div>
                                    {editing === row.id && (
                                        <ActionForm action={`/commands/customers/${row.id}`} method="patch" fields={fields} defaults={{ name: row.name, phone: row.phone ?? '', notes: row.notes ?? '', follow_up_on: row.follow_up_on ? String(row.follow_up_on).slice(0, 10) : '' }} submit={translate('dealer_tools', 'customer_save')} onDone={() => setEditing(null)} />
                                    )}
                                </div>
                            </article>
                        );
                    })}
                <Pagination page={data?.customers} />
            </section>
        </>
    );
}
