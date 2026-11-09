import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Empty, Modal, Table, number } from './UI';
import OfficePage from './office/OfficePage';
import { useT } from '../shared/i18n/t';

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

/** Text boxes grouped by the page they belong to. */
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

type HubProps = { data: TrustData; label: (key: string) => string };

/** The trust pages, as a list with one closed row per page. Website staff open a row to edit it. */
export default function PublicTrustHub({ data }: { data?: TrustData | null }) {
    const translate = useT();
    const hub = usePage<any>().props.data?.hub ?? null;
    if (!data) {
        return null;
    }
    const label = (key: string): string => data.labels[key] ?? key;

    return (
        <OfficePage title={translate('website_admin', 'heading')} hub={hub}>
            <div className="ow-list" data-tour="public-trust">
                <TextSections data={data} label={label} />
                <DocumentsSection data={data} label={label} />
                <ArticlesSection data={data} label={label} />
            </div>
        </OfficePage>
    );
}

/** One closed row. The summary line says what is in it; opening it shows the boxes. */
function Row({ title, state, open, onToggle, children }: { title: string; state?: React.ReactNode; open?: boolean; onToggle?: (open: boolean) => void; children: React.ReactNode }) {
    return (
        <details className="ow-row" open={open} onToggle={onToggle ? (event) => onToggle(event.currentTarget.open) : undefined}>
            <summary>
                <span className="ow-row-title">{title}</span>
                {state}
            </summary>
            <div className="ow-row-body">{children}</div>
        </details>
    );
}

function ShownBadge({ shown, label }: { shown: boolean; label: HubProps['label'] }) {
    return <span className={`badge ${shown ? 'approved' : ''}`}>{label(shown ? 'admin_status_shown' : 'admin_status_hidden')}</span>;
}

/** Starting values: a blank saved box shows its built-in draft so it can be edited in place. */
function initialValues(data: TrustData): Record<string, string> {
    const values = { ...data.values };
    data.text_keys.forEach((key) => {
        values[`trust_${key}`] = data.values[`trust_${key}`] || data.drafts.en[key] || '';
        values[`trust_${key}_bn`] = data.values[`trust_${key}_bn`] || data.drafts.bn[key] || '';
    });
    return values;
}

/** Every box of one row, so a refused save can open the rows that hold the problem. */
function keysOfSection(section: string, data: TrustData): string[] {
    if (section === 'identity') {
        return data.identity_keys.map((key) => `trust_${key}`);
    }
    if (section === 'grievance_contact') {
        return ['trust_grievance_contact_note', 'trust_grievance_contact_note_bn', ...data.contact_keys.map((key) => `trust_${key}`)];
    }
    const pageGroup = PAGE_GROUPS.find(({ group }) => group === section);
    return pageGroup ? [`page_enabled_${pageGroup.page}`, ...pageGroup.fields.flatMap((field) => [`trust_${field}`, `trust_${field}_bn`])] : [];
}

const TEXT_SECTIONS = [...PAGE_GROUPS.map(({ group }) => group), 'identity', 'grievance_contact'];

function TextSections({ data, label }: HubProps) {
    const form = useForm<{ values: Record<string, string> }>({ values: initialValues(data) });
    const [openSections, setOpenSections] = useState<string[]>([]);
    const setValue = (key: string, value: string) => form.setData('values', { ...form.data.values, [key]: value });
    const errors = form.errors as Record<string, string>;

    const toggle = (section: string, open: boolean) => setOpenSections((current) => (open ? [...new Set([...current, section])] : current.filter((name) => name !== section)));

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
        form.post('/commands/public-trust/text', {
            preserveScroll: true,
            onError: (problems) => {
                const withProblem = TEXT_SECTIONS.filter((section) => keysOfSection(section, data).some((key) => `values.${key}` in problems));
                setOpenSections((current) => [...new Set([...current, ...withProblem])]);
            },
        });
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

    const isOpen = (section: string): boolean => openSections.includes(section);

    return (
        <form className="action-form ow-list-form" onSubmit={save}>
            {PAGE_GROUPS.map(({ group, page, fields }) => {
                const shown = (form.data.values[`page_enabled_${page}`] ?? '1') !== '0';
                return (
                    <Row key={group} title={label(`admin_group_${group}`)} state={<ShownBadge shown={shown} label={label} />} open={isOpen(group)} onToggle={(open) => toggle(group, open)}>
                        {group === 'exit' && <p className="field-hint">{label('admin_exit_hint')}</p>}
                        <label className="inline-choice">
                            <input type="checkbox" checked={shown} onChange={(event) => setValue(`page_enabled_${page}`, event.target.checked ? '1' : '0')} />
                            {label('admin_show_page')}
                        </label>
                        {fields.map(bilingualField)}
                    </Row>
                );
            })}
            <Row title={label('admin_tab_identity')} open={isOpen('identity')} onToggle={(open) => toggle('identity', open)}>
                <p className="field-hint">{label('admin_identity_hint')}</p>
                {data.identity_keys.map((key) => plainField(key, label(`identity_${key}`)))}
            </Row>
            <Row title={label('admin_tab_grievance')} open={isOpen('grievance_contact')} onToggle={(open) => toggle('grievance_contact', open)}>
                <p className="field-hint">{label('admin_contact_hint')}</p>
                {bilingualField('grievance_contact_note')}
                {data.contact_keys.map((key) => plainField(key, label(`admin_contact_${key.replace('grievance_contact_', '')}`), key.endsWith('_email') ? 'email' : 'text'))}
            </Row>
            <div className="ws-save-bar is-end">
                <button type="submit" className="button dark" disabled={form.processing}>{label('admin_save')}</button>
            </div>
        </form>
    );
}

function confirmThenDelete(url: string, message: string, onDeleted?: () => void) {
    if (window.confirm(message)) {
        router.post(url, {}, { preserveScroll: true, onSuccess: onDeleted });
    }
}

function DocumentsSection({ data, label }: HubProps) {
    const translate = useT();
    const [adding, setAdding] = useState(false);
    const [openedId, setOpenedId] = useState<number | null>(null);
    const opened = data.documents.find((document) => document.id === openedId) ?? null;
    const form = useForm<{ title: string; document: File | null }>({ title: '', document: null });
    const errors = form.errors as Record<string, string>;
    const closeAdding = () => {
        setAdding(false);
        form.reset();
        form.clearErrors();
    };
    const upload = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/commands/public-trust/documents', { forceFormData: true, preserveScroll: true, onSuccess: closeAdding });
    };
    const toggleShown = (document: DocumentRow) => router.post(`/commands/public-trust/documents/${document.id}`, { title: document.title, is_published: !document.is_published }, { preserveScroll: true });

    return (
        <>
            <Row title={label('admin_tab_documents')} state={<small className="muted">{number(data.documents.length)}</small>}>
                <div className="toolbar-end">
                    <button type="button" className="button small" onClick={() => setAdding(true)}><Plus size={15} aria-hidden="true" /> {label('admin_document_upload')}</button>
                </div>
                {data.documents.length === 0
                    ? <Empty line title={label('admin_document_empty')} />
                    : (
                        <Table
                            tools={false}
                            searchable={false}
                            rows={data.documents}
                            onRowClick={(row: DocumentRow) => setOpenedId(row.id)}
                            columns={[
                                { key: 'title', title: label('admin_document_title'), render: (row: DocumentRow) => <button type="button" className="text-link" onClick={() => setOpenedId(row.id)}>{row.title}</button> },
                                { key: 'original_name', title: label('admin_document_file') },
                                { key: 'is_published', title: translate('portal', 'enquiry_status'), render: (row: DocumentRow) => <ShownBadge shown={row.is_published} label={label} /> },
                            ]}
                        />
                    )}
            </Row>
            {adding && (
                <Modal title={label('admin_document_upload')} onClose={closeAdding}>
                    <form className="action-form" onSubmit={upload}>
                        <label>
                            {label('admin_document_title')}
                            <input required maxLength={200} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                            {errors.title && <small className="field-error">{errors.title}</small>}
                        </label>
                        <label>
                            {label('admin_document_file')}
                            <input type="file" required accept="application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp" onChange={(event) => form.setData('document', event.target.files?.[0] ?? null)} />
                            <small className="field-hint">{label('admin_documents_hint')}</small>
                            {errors.document && <small className="field-error">{errors.document}</small>}
                        </label>
                        <button type="submit" className="button dark" disabled={form.processing}>{label('admin_document_upload')}</button>
                    </form>
                </Modal>
            )}
            {opened && (
                <Modal variant="drawer" title={opened.title} onClose={() => setOpenedId(null)} footer={<>
                        <button type="button" className="button small" onClick={() => toggleShown(opened)}>{label(opened.is_published ? 'admin_document_hide' : 'admin_document_show')}</button>
                        <button type="button" className="button small danger" onClick={() => confirmThenDelete(`/commands/public-trust/documents/${opened.id}/delete`, label('admin_delete_confirm'), () => setOpenedId(null))}><Trash2 size={15} aria-hidden="true" /> {label('admin_delete')}</button>
                </>}>
                    <dl className="office-facts">
                        <div><dt>{label('admin_document_file')}</dt><dd>{opened.original_name}</dd></div>
                        <div><dt>{translate('portal', 'enquiry_status')}</dt><dd><ShownBadge shown={opened.is_published} label={label} /></dd></div>
                    </dl>
                </Modal>
            )}
        </>
    );
}

/** The article form, opened for a new article or for one row. */
function ArticleForm({ article, label, onDone }: { article: ArticleRow | null; label: HubProps['label']; onDone: () => void }) {
    const form = useForm<{ title: string; body: string; title_bn: string; body_bn: string; published: boolean; image: File | null }>({
        title: article?.title ?? '',
        body: article?.body ?? '',
        title_bn: article?.title_bn ?? '',
        body_bn: article?.body_bn ?? '',
        published: article?.published ?? false,
        image: null,
    });
    const errors = form.errors as Record<string, string>;
    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(article ? `/commands/public-trust/articles/${article.id}` : '/commands/public-trust/articles', { forceFormData: true, preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form className="action-form" onSubmit={save}>
            <p className="field-hint">{label('admin_articles_hint')}</p>
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
                            <textarea rows={8} maxLength={20000} value={form.data[bodyKey]} onChange={(event) => form.setData(bodyKey, event.target.value)} />
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
            <button type="submit" className="button dark" disabled={form.processing}>{label('admin_save')}</button>
        </form>
    );
}

function ArticlesSection({ data, label }: HubProps) {
    const translate = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const editing = data.articles.find((article) => article.id === editingId) ?? null;
    const titleOf = (article: ArticleRow): string => article.title_bn || article.title || '';

    return (
        <>
            <Row title={label('admin_tab_articles')} state={<small className="muted">{number(data.articles.length)}</small>}>
                <div className="toolbar-end">
                    <button type="button" className="button small" onClick={() => setAdding(true)}><Plus size={15} aria-hidden="true" /> {label('admin_article_new')}</button>
                </div>
                {data.articles.length === 0
                    ? <Empty line title={label('admin_article_empty')} />
                    : (
                        <Table
                            tools={false}
                            searchable={false}
                            rows={data.articles}
                            onRowClick={(row: ArticleRow) => setEditingId(row.id)}
                            columns={[
                                { key: 'title', title: label('admin_article_title'), render: (row: ArticleRow) => <button type="button" className="text-link" onClick={() => setEditingId(row.id)}>{titleOf(row)}</button> },
                                { key: 'published', title: translate('portal', 'enquiry_status'), render: (row: ArticleRow) => <span className={`badge ${row.published ? 'approved' : ''}`}>{label(row.published ? 'admin_status_published' : 'admin_status_draft')}</span> },
                            ]}
                        />
                    )}
            </Row>
            {adding && (
                <Modal title={label('admin_article_new')} onClose={() => setAdding(false)}>
                    <ArticleForm article={null} label={label} onDone={() => setAdding(false)} />
                </Modal>
            )}
            {editing && (
                <Modal title={label('admin_article_edit')} onClose={() => setEditingId(null)} footer={<>
                        <button type="button" className="button small danger" onClick={() => confirmThenDelete(`/commands/public-trust/articles/${editing.id}/delete`, label('admin_delete_confirm'), () => setEditingId(null))}><Trash2 size={15} aria-hidden="true" /> {label('admin_delete')}</button>
                </>}>
                    <ArticleForm key={editing.id} article={editing} label={label} onDone={() => setEditingId(null)} />
                </Modal>
            )}
        </>
    );
}
