import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Download, Eye, Trash2 } from 'lucide-react';
import { ActionForm, Empty, type Field, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

type SalesKitItem = {
    id: number; title: string; kind: string; language: string; caption: string | null;
    file_name: string; is_image: boolean; view_url: string; download_url: string;
};

type SalesKitData = { can_manage: boolean; kinds: string[]; languages: string[]; items: SalesKitItem[] };

/** Approved sales materials. The owner adds and removes them; partners view and download. */
export default function SalesKitHub({ data }: { data: SalesKitData }) {
    const translate = useT();
    const [language, setLanguage] = useState('');
    const [kind, setKind] = useState('');
    const items = (data?.items ?? []).filter((item) => (!language || item.language === language) && (!kind || item.kind === kind));
    const kindLabel = (value: string) => translate('dealer_tools', `kit_kind_${value}`);
    const languageLabel = (value: string) => translate('dealer_tools', `kit_lang_${value}`);
    const fields: Field[] = [
        { name: 'title', label: translate('dealer_tools', 'kit_title_field'), required: true },
        { name: 'kind', label: translate('dealer_tools', 'kit_kind_field'), required: true, options: (data?.kinds ?? []).map((value) => ({ value, label: kindLabel(value) })) },
        { name: 'language', label: translate('dealer_tools', 'kit_language_field'), required: true, options: (data?.languages ?? []).map((value) => ({ value, label: languageLabel(value) })) },
        { name: 'caption', label: translate('dealer_tools', 'kit_caption_field'), type: 'textarea' },
        { name: 'file', label: translate('dealer_tools', 'kit_file_field'), type: 'file', required: true, accept: '.pdf,.png,.jpg,.jpeg,.webp' } as Field,
    ];
    const remove = (item: SalesKitItem) => {
        if (window.confirm(translate('dealer_tools', 'kit_remove_confirm', { title: item.title }))) {
            router.delete(`/commands/sales-kit/${item.id}`, { preserveScroll: true });
        }
    };
    const selectStyle = 'order-channel-select';
    return (
        <>
            {data?.can_manage && (
                <section className="panel narrow-panel">
                    <h2>{translate('dealer_tools', 'kit_add_title')}</h2>
                    <p className="muted">{translate('dealer_tools', 'kit_add_hint')}</p>
                    <ActionForm action="/commands/sales-kit" fields={fields} defaults={{ kind: 'poster', language: 'bn' } as Row} submit={translate('dealer_tools', 'kit_save')} />
                </section>
            )}
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
                                        {data.can_manage && <button type="button" className="button small" onClick={() => remove(item)}><Trash2 size={14} /> {translate('dealer_tools', 'kit_remove')}</button>}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
            </section>
        </>
    );
}
