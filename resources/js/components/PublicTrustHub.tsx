import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Empty } from './UI';

type DocumentRow = { id: number; title: string; original_name: string; is_published: boolean };
type ArticleRow = { id: number; title: string | null; body: string | null; title_bn: string | null; body_bn: string | null; image_url: string | null; published: boolean };

type TrustData = {
    values: Record<string, string>;
    text_keys: string[];
    identity_keys: string[];
    contact_keys: string[];
    pages: string[];
    drafts: { en: Record<string, string>; bn: Record<string, string> };
    labels: Record<string, string>;
    documents: DocumentRow[];
    articles: ArticleRow[];
};

type TabId = 'pages' | 'identity' | 'grievance' | 'documents' | 'articles';

/** Text boxes on the Pages tab, grouped by the page they belong to. */
const PAGE_GROUPS: { group: string; page: string; fields: string[] }[] = [
    { group: 'refund', page: 'refund-policy', fields: ['refund_title', 'refund_lead', 'refund_body'] },
    { group: 'delivery', page: 'delivery-policy', fields: ['delivery_title', 'delivery_lead', 'delivery_body'] },
    { group: 'conduct', page: 'code-of-conduct', fields: ['conduct_title', 'conduct_lead', 'conduct_body'] },
    { group: 'exit', page: 'partner-exit-policy', fields: ['exit_title', 'exit_lead', 'exit_body'] },
    { group: 'verify', page: 'verify-dealer', fields: ['verify_title', 'verify_lead', 'verify_warning'] },
    { group: 'grievance', page: 'grievance', fields: ['grievance_title', 'grievance_lead'] },
    { group: 'quality_documents', page: 'quality-documents', fields: ['quality_documents_title', 'quality_documents_lead'] },
    { group: 'articles', page: 'articles', fields: ['articles_title', 'articles_lead', 'articles_disclaimer'] },
];

const fieldLabelKey = (field: string): string => {
    if (field.endsWith('_contact_note')) {
        return 'admin_field_contact_note';
    }
    return `admin_field_${field.split('_').pop()}`;
};

const isLongField = (field: string): boolean => /_(body|warning|disclaimer)$/.test(field);

/** Website staff edit the public trust pages, upload quality documents and write health articles. */
export default function PublicTrustHub({ data }: { data?: TrustData | null }) {
    const [tab, setTab] = useState<TabId>('pages');
    if (!data) {
        return null;
    }
    const label = (key: string): string => data.labels[key] ?? key;
    const tabs: TabId[] = ['pages', 'identity', 'grievance', 'documents', 'articles'];

    return (
        <section className="panel" data-tour="public-trust">
            <div className="panel-heading">
                <div>
                    <h2>{label('admin_title')}</h2>
                    <p className="muted">{label('admin_intro')}</p>
                </div>
            </div>
            <div className="toolbar ops-mb-4" role="tablist">
                {tabs.map((id) => (
                    <button key={id} type="button" role="tab" aria-selected={tab === id} className={`button small${tab === id ? ' dark' : ''}`} onClick={() => setTab(id)}>
                        {label(`admin_tab_${id}`)}
                    </button>
                ))}
            </div>
            {tab === 'documents' && <DocumentsTab data={data} label={label} />}
            {tab === 'articles' && <ArticlesTab data={data} label={label} />}
            {(tab === 'pages' || tab === 'identity' || tab === 'grievance') && <TextTab key={tab} tab={tab} data={data} label={label} />}
        </section>
    );
}

type TabProps = { data: TrustData; label: (key: string) => string };

/** Starting values: a blank saved box shows its built-in draft so it can be edited in place. */
function initialValues(data: TrustData): Record<string, string> {
    const values = { ...data.values };
    data.text_keys.forEach((key) => {
        values[`trust_${key}`] = data.values[`trust_${key}`] || data.drafts.en[key] || '';
        values[`trust_${key}_bn`] = data.values[`trust_${key}_bn`] || data.drafts.bn[key] || '';
    });
    return values;
}

function TextTab({ tab, data, label }: TabProps & { tab: 'pages' | 'identity' | 'grievance' }) {
    const form = useForm<{ values: Record<string, string> }>({ values: initialValues(data) });
    const setValue = (key: string, value: string) => form.setData('values', { ...form.data.values, [key]: value });
    const errors = form.errors as Record<string, string>;

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((current) => {
            const values = { ...current.values };
            // Wording left as the draft is stored blank, so it keeps following the built-in draft.
            data.text_keys.forEach((key) => {
                if (values[`trust_${key}`].trim() === (data.drafts.en[key] ?? '').trim()) {
                    values[`trust_${key}`] = '';
                }
                if (values[`trust_${key}_bn`].trim() === (data.drafts.bn[key] ?? '').trim()) {
                    values[`trust_${key}_bn`] = '';
                }
            });
            return { values };
        });
        form.post('/commands/public-trust/text', { preserveScroll: true });
    };

    const bilingualField = (field: string) => (
        <div key={field} className="ops-mb-4">
            <strong>{label(fieldLabelKey(field))}</strong>
            {(['', '_bn'] as const).map((suffix) => {
                const key = `trust_${field}${suffix}`;
                const caption = label(suffix === '' ? 'admin_english' : 'admin_bangla');
                return (
                    <label key={key}>
                        {caption}
                        {isLongField(field) ? (
                            <textarea rows={field.endsWith('_body') ? 12 : 4} maxLength={field.endsWith('_body') ? 20000 : 2000} value={form.data.values[key] ?? ''} onChange={(event) => setValue(key, event.target.value)} />
                        ) : (
                            <input maxLength={2000} value={form.data.values[key] ?? ''} onChange={(event) => setValue(key, event.target.value)} />
                        )}
                        {errors[`values.${key}`] && <small className="field-error">{errors[`values.${key}`]}</small>}
                    </label>
                );
            })}
        </div>
    );

    const plainField = (key: string, caption: string, type = 'text') => (
        <label key={key}>
            {caption}
            <input type={type} maxLength={2000} value={form.data.values[`trust_${key}`] ?? ''} onChange={(event) => setValue(`trust_${key}`, event.target.value)} />
            {errors[`values.trust_${key}`] && <small className="field-error">{errors[`values.trust_${key}`]}</small>}
        </label>
    );

    return (
        <form className="action-form" onSubmit={save}>
            {tab === 'pages' && PAGE_GROUPS.map(({ group, page, fields }) => (
                <fieldset key={group} className="ops-mb-4">
                    <legend><strong>{label(`admin_group_${group}`)}</strong></legend>
                    {group === 'exit' && <p className="muted">{label('admin_exit_hint')}</p>}
                    <label className="inline-choice">
                        <input
                            type="checkbox"
                            checked={(form.data.values[`page_enabled_${page}`] ?? '1') !== '0'}
                            onChange={(event) => setValue(`page_enabled_${page}`, event.target.checked ? '1' : '0')}
                        />
                        {label('admin_show_page')}
                    </label>
                    {fields.map(bilingualField)}
                </fieldset>
            ))}
            {tab === 'identity' && (
                <>
                    <p className="muted">{label('admin_identity_hint')}</p>
                    {data.identity_keys.map((key) => plainField(key, label(`identity_${key}`)))}
                </>
            )}
            {tab === 'grievance' && (
                <>
                    <p className="muted">{label('admin_contact_hint')}</p>
                    {bilingualField('grievance_contact_note')}
                    {data.contact_keys.map((key) => plainField(key, label(`admin_contact_${key.replace('grievance_contact_', '')}`), key.endsWith('_email') ? 'email' : 'text'))}
                </>
            )}
            <button type="submit" className="button dark" disabled={form.processing}>{label('admin_save')}</button>
        </form>
    );
}

function confirmThenDelete(url: string, message: string) {
    if (window.confirm(message)) {
        router.post(url, {}, { preserveScroll: true });
    }
}

function DocumentsTab({ data, label }: TabProps) {
    const form = useForm<{ title: string; document: File | null }>({ title: '', document: null });
    const errors = form.errors as Record<string, string>;
    const upload = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const formElement = event.currentTarget;
        form.post('/commands/public-trust/documents', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                formElement.reset();
            },
        });
    };
    const toggle = (document: DocumentRow) => router.post(`/commands/public-trust/documents/${document.id}`, { title: document.title, is_published: !document.is_published }, { preserveScroll: true });

    return (
        <>
            <p className="muted">{label('admin_documents_hint')}</p>
            <form className="action-form ops-mb-4" onSubmit={upload}>
                <label>
                    {label('admin_document_title')}
                    <input required maxLength={200} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                    {errors.title && <small className="field-error">{errors.title}</small>}
                </label>
                <label>
                    {label('admin_document_file')}
                    <input type="file" required accept="application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp" onChange={(event) => form.setData('document', event.target.files?.[0] ?? null)} />
                    {errors.document && <small className="field-error">{errors.document}</small>}
                </label>
                <button type="submit" className="button dark" disabled={form.processing}>{label('admin_document_upload')}</button>
            </form>
            {data.documents.length === 0 && <Empty title={label('admin_document_empty')} />}
            {data.documents.map((document) => (
                <div key={document.id} className="toolbar ops-mb-4">
                    <span><b>{document.title}</b><small className="cell-sub">{document.original_name} · {label(document.is_published ? 'admin_status_shown' : 'admin_status_hidden')}</small></span>
                    <button type="button" className="button small" onClick={() => toggle(document)}>{label(document.is_published ? 'admin_document_hide' : 'admin_document_show')}</button>
                    <button type="button" className="button small" aria-label={label('admin_delete')} onClick={() => confirmThenDelete(`/commands/public-trust/documents/${document.id}/delete`, label('admin_delete_confirm'))}><Trash2 size={15} /> {label('admin_delete')}</button>
                </div>
            ))}
        </>
    );
}

function ArticlesTab({ data, label }: TabProps) {
    const [editing, setEditing] = useState<ArticleRow | null>(null);
    const form = useForm<{ title: string; body: string; title_bn: string; body_bn: string; published: boolean; image: File | null }>({ title: '', body: '', title_bn: '', body_bn: '', published: false, image: null });
    const errors = form.errors as Record<string, string>;
    const startEditing = (article: ArticleRow) => {
        setEditing(article);
        form.setData({ title: article.title ?? '', body: article.body ?? '', title_bn: article.title_bn ?? '', body_bn: article.body_bn ?? '', published: article.published, image: null });
    };
    const finish = () => {
        setEditing(null);
        form.reset();
    };
    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(editing ? `/commands/public-trust/articles/${editing.id}` : '/commands/public-trust/articles', { forceFormData: true, preserveScroll: true, onSuccess: finish });
    };

    return (
        <>
            <p className="muted">{label('admin_articles_hint')}</p>
            <form className="action-form ops-mb-4" onSubmit={save}>
                <h3>{label(editing ? 'admin_article_edit' : 'admin_article_new')}</h3>
                <p className="muted">{label('admin_article_languages_hint')}</p>
                {(['_bn', ''] as const).map((suffix) => {
                    const titleKey = `title${suffix}` as const;
                    const bodyKey = `body${suffix}` as const;
                    const language = label(suffix === '' ? 'admin_english' : 'admin_bangla');
                    return (
                        <React.Fragment key={suffix}>
                            <label>
                                {`${label('admin_article_title')} (${language})`}
                                <input maxLength={200} value={form.data[titleKey]} onChange={(event) => form.setData(titleKey, event.target.value)} />
                                {errors[titleKey] && <small className="field-error">{errors[titleKey]}</small>}
                            </label>
                            <label>
                                {`${label('admin_article_body')} (${language})`}
                                <textarea rows={12} maxLength={20000} value={form.data[bodyKey]} onChange={(event) => form.setData(bodyKey, event.target.value)} />
                                {errors[bodyKey] && <small className="field-error">{errors[bodyKey]}</small>}
                            </label>
                        </React.Fragment>
                    );
                })}
                <label>
                    {label('admin_article_image')}
                    <input type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" onChange={(event) => form.setData('image', event.target.files?.[0] ?? null)} />
                    {errors.image && <small className="field-error">{errors.image}</small>}
                </label>
                <label className="inline-choice">
                    <input type="checkbox" checked={form.data.published} onChange={(event) => form.setData('published', event.target.checked)} />
                    {label('admin_article_publish')}
                </label>
                <div className="toolbar">
                    <button type="submit" className="button dark" disabled={form.processing}>{label('admin_save')}</button>
                    {editing && <button type="button" className="button" onClick={finish}>{label('admin_article_cancel')}</button>}
                </div>
            </form>
            {data.articles.length === 0 && <Empty title={label('admin_article_empty')} />}
            {data.articles.map((article) => (
                <div key={article.id} className="toolbar ops-mb-4">
                    <span><b>{article.title_bn || article.title}</b><small className="cell-sub">{label(article.published ? 'admin_status_published' : 'admin_status_draft')}</small></span>
                    <button type="button" className="button small" onClick={() => startEditing(article)}>{label('admin_edit')}</button>
                    <button type="button" className="button small" aria-label={label('admin_delete')} onClick={() => confirmThenDelete(`/commands/public-trust/articles/${article.id}/delete`, label('admin_delete_confirm'))}><Trash2 size={15} /> {label('admin_delete')}</button>
                </div>
            ))}
        </>
    );
}
