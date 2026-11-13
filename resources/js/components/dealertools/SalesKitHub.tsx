import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Download, Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { ActionForm, Empty, Modal, Table, type Field, type Row } from '../UI';
import OfficePage from '../office/OfficePage';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';

type SalesKitItem = {
    id: number; title: string; kind: string; language: string; caption: string | null;
    file_name: string; is_image: boolean; view_url: string; download_url: string;
};

type SalesKitData = { can_manage: boolean; kinds: string[]; languages: string[]; items: SalesKitItem[] };

/** What a partner sees: approved files to view and download. */
function PartnerKit({ data }: { data: SalesKitData }) {
    const translate = useT();
    const [language, setLanguage] = useState('');
    const [kind, setKind] = useState('');
    const items = (data?.items ?? []).filter((item) => (!language || item.language === language) && (!kind || item.kind === kind));
    const kindLabel = (value: string) => translate('dealer_tools', `kit_kind_${value}`);
    const languageLabel = (value: string) => translate('dealer_tools', `kit_lang_${value}`);
    const selectStyle = 'order-channel-select';
    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('dealer_tools', 'kit_list_title')}</h2>
                    <p className="muted">{translate('dealer_tools', 'kit_list_hint')}</p>
                </div>
            </div>
            <div className="filter-bar">
                <select className={selectStyle} aria-label={translate('dealer_tools', 'kit_language_field')} value={language} onChange={(e) => setLanguage(e.target.value)}>
                    <option value="">{translate('dealer_tools', 'kit_any_language')}</option>
                    {(data?.languages ?? []).map((value) => <option key={value} value={value}>{languageLabel(value)}</option>)}
                </select>
                <select className={selectStyle} aria-label={translate('dealer_tools', 'kit_kind_field')} value={kind} onChange={(e) => setKind(e.target.value)}>
                    <option value="">{translate('dealer_tools', 'kit_any_kind')}</option>
                    {(data?.kinds ?? []).map((value) => <option key={value} value={value}>{kindLabel(value)}</option>)}
                </select>
            </div>
            {items.length === 0
                ? <Empty title={translate('dealer_tools', 'kit_empty_title')} body={translate('dealer_tools', 'kit_empty_body')} />
                : (
                    <div className="metric-grid three">
                        {items.map((item) => (
                            <article key={item.id} className="metric">
                                {item.is_image && <img src={item.view_url} alt="" loading="lazy" style={{ width: '100%', borderRadius: 8, marginBottom: 8 }} />}
                                <span>{kindLabel(item.kind)} · {languageLabel(item.language)}</span>
                                <strong>{item.title}</strong>
                                {item.caption && <p className="ptl-prewrap">{item.caption}</p>}
                                <div className="ops-row is-wrap">
                                    <a className="button small" href={item.view_url} target="_blank" rel="noreferrer"><Eye size={14} /> {translate('dealer_tools', 'kit_view')}</a>
                                    <a className="button small dark" href={item.download_url}><Download size={14} /> {translate('dealer_tools', 'kit_download')}</a>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
        </section>
    );
}

/** The office's list of files. A row opens the file and the buttons to change it. */
function OfficeKit({ data }: { data: SalesKitData }) {
    const translate = useT();
    const hub = usePage<any>().props.data?.hub ?? null;
    const why = useReasonField('partner.sales_kit_edit');
    const [adding, setAdding] = useState(false);
    const [openedId, setOpenedId] = useState<number | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const items = data?.items ?? [];
    const opened = items.find((item) => item.id === openedId) ?? null;
    const editing = items.find((item) => item.id === editingId) ?? null;
    const kindLabel = (value: string) => translate('dealer_tools', `kit_kind_${value}`);
    const languageLabel = (value: string) => translate('dealer_tools', `kit_lang_${value}`);
    const fields: Field[] = [
        { name: 'title', label: translate('dealer_tools', 'kit_title_field'), required: true },
        { name: 'kind', label: translate('dealer_tools', 'kit_kind_field'), required: true, options: (data?.kinds ?? []).map((value) => ({ value, label: kindLabel(value) })) },
        { name: 'language', label: translate('dealer_tools', 'kit_language_field'), required: true, options: (data?.languages ?? []).map((value) => ({ value, label: languageLabel(value) })) },
        { name: 'caption', label: translate('dealer_tools', 'kit_caption_field'), type: 'textarea' },
        { name: 'file', label: translate('dealer_tools', 'kit_file_field'), type: 'file', required: true, accept: '.pdf,.png,.jpg,.jpeg,.webp' } as Field,
    ];
    const editFields: Field[] = editing ? [
        ...fields.filter((field) => field.name !== 'file'),
        { name: 'file', label: translate('dealer_tools', 'kit_replace_file_field'), type: 'file', accept: '.pdf,.png,.jpg,.jpeg,.webp', hint: translate('dealer_tools', 'kit_replace_file_hint', { name: editing.file_name }) },
        { name: 'reason', label: translate('dealer_tools', 'edit_reason_field'), type: 'textarea', required: true, place: 'partner.sales_kit_edit', hint: why.required ? translate('dealer_tools', 'edit_reason_hint') : translate('reason_fields', 'hint_kept_in_record') },
    ] : [];
    const remove = (item: SalesKitItem) => {
        if (window.confirm(translate('dealer_tools', 'kit_remove_confirm', { title: item.title }))) {
            router.delete(`/commands/sales-kit/${item.id}`, { preserveScroll: true, onSuccess: () => setOpenedId(null) });
        }
    };

    return (
        <OfficePage
            title={hub?.label ?? translate('office_website', 'partners_title')}
            hub={hub}
            action={<button type="button" className="button dark" onClick={() => setAdding(true)}><Plus size={17} aria-hidden="true" />{translate('dealer_tools', 'kit_add_title')}</button>}
        >
            <Table
                rows={items}
                phoneColumns={['title', 'kind']}
                onRowClick={(row: SalesKitItem) => setOpenedId(row.id)}
                empty={translate('dealer_tools', 'kit_empty_title')}
                emptyBody=""
                filters={[
                    { key: 'language', label: translate('dealer_tools', 'kit_any_language'), options: (data?.languages ?? []).map((value) => ({ value, label: languageLabel(value) })) },
                    { key: 'kind', label: translate('dealer_tools', 'kit_any_kind'), options: (data?.kinds ?? []).map((value) => ({ value, label: kindLabel(value) })) },
                ]}
                columns={[
                    { key: 'title', title: translate('dealer_tools', 'kit_title_field'), sortable: true, render: (item: SalesKitItem) => <><button type="button" className="text-link" onClick={() => setOpenedId(item.id)}>{item.title}</button>{item.caption && <small className="cell-sub">{item.caption}</small>}</> },
                    { key: 'kind', title: translate('dealer_tools', 'kit_kind_field'), sortable: true, render: (item: SalesKitItem) => kindLabel(item.kind) },
                    { key: 'language', title: translate('dealer_tools', 'kit_language_field'), sortable: true, render: (item: SalesKitItem) => languageLabel(item.language) },
                    { key: 'file_name', title: translate('dealer_tools', 'kit_file_field') },
                ]}
            />
            {adding && (
                <Modal title={translate('dealer_tools', 'kit_add_title')} onClose={() => setAdding(false)}>
                    <ActionForm action="/commands/sales-kit" fields={fields} defaults={{ kind: 'poster', language: 'bn' } as Row} submit={translate('dealer_tools', 'kit_save')} onDone={() => setAdding(false)} />
                </Modal>
            )}
            {opened && (
                <Modal variant="drawer" title={opened.title} onClose={() => setOpenedId(null)} footer={<>
                        <a className="button small" href={opened.view_url} target="_blank" rel="noreferrer"><Eye size={14} aria-hidden="true" /> {translate('dealer_tools', 'kit_view')}</a>
                        <a className="button small" href={opened.download_url}><Download size={14} aria-hidden="true" /> {translate('dealer_tools', 'kit_download')}</a>
                        <button type="button" className="button small dark" onClick={() => { setOpenedId(null); setEditingId(opened.id); }}><Pencil size={14} aria-hidden="true" /> {translate('office_website', 'edit')}</button>
                        <button type="button" className="button small danger" onClick={() => remove(opened)}><Trash2 size={14} aria-hidden="true" /> {translate('dealer_tools', 'kit_remove')}</button>
                </>}>
                    {opened.is_image && <img className="ow-drawer-image" src={opened.view_url} alt="" loading="lazy" />}
                    <dl className="office-facts">
                        <div><dt>{translate('dealer_tools', 'kit_kind_field')}</dt><dd>{kindLabel(opened.kind)}</dd></div>
                        <div><dt>{translate('dealer_tools', 'kit_language_field')}</dt><dd>{languageLabel(opened.language)}</dd></div>
                        <div><dt>{translate('dealer_tools', 'kit_file_field')}</dt><dd>{opened.file_name}</dd></div>
                        {opened.caption && <div><dt>{translate('dealer_tools', 'kit_caption_field')}</dt><dd className="ptl-prewrap">{opened.caption}</dd></div>}
                    </dl>
                </Modal>
            )}
            {editing && (
                <Modal title={translate('office_website', 'kit_edit_title')} onClose={() => setEditingId(null)}>
                    <ActionForm
                        action={`/commands/sales-kit/${editing.id}`}
                        fields={editFields}
                        defaults={{ title: editing.title, kind: editing.kind, language: editing.language, caption: editing.caption ?? '' } as Row}
                        onDone={() => setEditingId(null)}
                    />
                </Modal>
            )}
        </OfficePage>
    );
}

/** Approved sales materials. The owner adds, edits and removes them; partners view and download. */
export default function SalesKitHub({ data }: { data: SalesKitData }) {
    return data?.can_manage ? <OfficeKit data={data} /> : <PartnerKit data={data} />;
}
