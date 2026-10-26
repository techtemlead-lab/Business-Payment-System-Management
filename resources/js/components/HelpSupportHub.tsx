import { Form, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { BookOpen, LifeBuoy, Search } from 'lucide-react';
import { Badge, date, Empty, Table, type Row } from './UI';
import PrerequisiteHelper from './PrerequisiteHelper';
import { useT } from '../shared/i18n/t';

export default function HelpSupportHub({ data }: { data: Row }) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const sections: Row[] = data.manual_sections || [];
    const tickets: Row[] = data.support_tickets || [];
    const categories: string[] = data.support_categories || [];
    const [query, setQuery] = useState(String(data.manual_query || ''));
    const [activeKey, setActiveKey] = useState<string>(String(sections[0]?.key || ''));

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) return sections;
        return sections.filter((section) => {
            const blob = [
                section.key,
                section.title,
                section.summary,
                ...(section.steps || []),
                ...(section.tips || []),
                ...(section.keywords || []),
            ].join(' ').toLowerCase();
            return blob.includes(needle);
        });
    }, [query, sections]);

    const active = filtered.find((section) => section.key === activeKey) || filtered[0] || null;

    return (
        <div className="help-support-hub">
            <PrerequisiteHelper guide={data.guide_prerequisites} tourBlocked={[]} />

            <section className="panel" data-tour="help-manual">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'help_manual_title')}</h2>
                        <p className="muted">{translate('portal', 'help_manual_hint')}</p>
                    </div>
                    <BookOpen size={22} />
                </div>
                <label className="help-manual-search">
                    <Search size={16} />
                    <input
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            router.get('/help', { q: event.target.value || undefined }, { preserveState: true, replace: true });
                        }}
                        placeholder={translate('portal', 'help_manual_search_ph')}
                    />
                </label>
                <div className="help-manual-layout">
                    <aside className="help-manual-toc">
                        {filtered.map((section) => (
                            <button
                                key={section.key}
                                type="button"
                                className={active?.key === section.key ? 'active' : ''}
                                onClick={() => setActiveKey(section.key)}
                            >
                                {section.title}
                            </button>
                        ))}
                        {!filtered.length && <p className="muted">{translate('portal', 'help_manual_no_results')}</p>}
                    </aside>
                    <article className="help-manual-article">
                        {active ? (
                            <>
                                <h3>{active.title}</h3>
                                <p className="muted">{active.summary}</p>
                                <ol>
                                    {(active.steps || []).map((step: string, index: number) => (
                                        <li key={`${active.key}-${index}`}>{step}</li>
                                    ))}
                                </ol>
                                {(active.tips || []).length > 0 && (
                                    <div className="help-manual-tips">
                                        <strong>{translate('portal', 'help_manual_tips')}</strong>
                                        <ul>
                                            {(active.tips || []).map((tip: string, index: number) => (
                                                <li key={`${active.key}-tip-${index}`}>{tip}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </>
                        ) : (
                            <Empty title={translate('portal', 'help_manual_no_results')} />
                        )}
                    </article>
                </div>
            </section>

            <section className="panel" data-tour="help-support">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'support_request_title')}</h2>
                        <p className="muted">{translate('portal', 'support_request_hint')}</p>
                    </div>
                    <LifeBuoy size={22} />
                </div>
                <Form action="/commands/support-tickets" method="post" encType="multipart/form-data" className="action-form">
                    {({ errors, processing }) => (
                        <>
                            <label>
                                {translate('portal', 'support_category')}
                                <select name="category" required defaultValue="">
                                    <option value="">{translate('portal', 'select_placeholder')}</option>
                                    {categories.map((category) => (
                                        <option key={category} value={category}>{translate('portal', `support_cat_${category}`)}</option>
                                    ))}
                                </select>
                                {errors.category && <span className="field-error">{errors.category}</span>}
                            </label>
                            <label>
                                {translate('portal', 'support_subject')}
                                <input name="subject" required maxLength={180} />
                                {errors.subject && <span className="field-error">{errors.subject}</span>}
                            </label>
                            <label>
                                {translate('portal', 'support_description')}
                                <textarea name="body" rows={5} required />
                                {errors.body && <span className="field-error">{errors.body}</span>}
                            </label>
                            <label>
                                {translate('portal', 'support_attachment_optional')}
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf" />
                            </label>
                            <button className="button dark" disabled={processing}>{translate('portal', 'support_submit')}</button>
                        </>
                    )}
                </Form>
            </section>

            <section className="panel">
                <div className="panel-heading"><h2>{translate('portal', 'my_support_tickets')}</h2></div>
                {tickets.length ? (
                    <Table
                        rows={tickets}
                        columns={[
                            { key: 'created_at', title: translate('portal', 'col_date'), render: (row) => date(row.created_at) },
                            { key: 'category', title: translate('portal', 'support_category'), render: (row) => translate('portal', `support_cat_${row.category}`) },
                            { key: 'subject', title: translate('portal', 'support_subject') },
                            { key: 'status', title: translate('portal', 'col_status'), render: (row) => <Badge status={row.status} /> },
                        ]}
                    />
                ) : (
                    <Empty title={translate('portal', 'support_empty_title')} body={translate('portal', 'support_empty_body')} />
                )}
                <p className="muted" style={{ marginTop: 12 }}>{translate('portal', 'support_signed_in_as', { name: auth.user.name })}</p>
            </section>
        </div>
    );
}

export function SupportAdminHub({ data }: { data: Row }) {
    const translate = useT();
    const admin = data.support_admin || { tickets: [], filters: {} };
    const tickets: Row[] = admin.tickets || [];

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('portal', 'support_admin_title')}</h2>
                    <p className="muted">{translate('portal', 'support_admin_hint')}</p>
                </div>
            </div>
            <form
                className="filter-bar"
                onSubmit={(event) => {
                    event.preventDefault();
                    const fd = new FormData(event.currentTarget);
                    router.get('/support-admin', { status: String(fd.get('status') || '') || undefined }, { preserveState: true });
                }}
            >
                <label className="muted" style={{ display: 'grid', gap: 4, fontSize: 11 }}>
                    {translate('portal', 'col_status')}
                    <select name="status" defaultValue={admin.filters?.status || ''}>
                        <option value="">{translate('portal', 'all')}</option>
                        <option value="pending">{translate('portal', 'status_pending')}</option>
                        <option value="in_progress">{translate('portal', 'status_in_progress')}</option>
                        <option value="resolved">{translate('portal', 'status_resolved')}</option>
                    </select>
                </label>
                <button className="button small" type="submit">{translate('portal', 'apply_dates')}</button>
            </form>
            {tickets.map((ticket) => (
                <article key={ticket.id} className="panel" style={{ marginTop: 12 }}>
                    <div className="panel-heading">
                        <div>
                            <h3>{ticket.subject}</h3>
                            <p className="muted">{ticket.user_name} · {ticket.dealer_code} · {translate('portal', `support_cat_${ticket.category}`)}</p>
                        </div>
                        <Badge status={ticket.status} />
                    </div>
                    <p>{ticket.body}</p>
                    <Form action={`/commands/support-tickets/${ticket.id}/reply`} method="post" className="action-form" style={{ marginTop: 12 }}>
                        {({ processing }) => (
                            <>
                                <label>
                                    {translate('portal', 'support_reply')}
                                    <textarea name="body" rows={3} required />
                                </label>
                                <label>
                                    {translate('portal', 'col_status')}
                                    <select name="status" defaultValue={ticket.status}>
                                        <option value="pending">{translate('portal', 'status_pending')}</option>
                                        <option value="in_progress">{translate('portal', 'status_in_progress')}</option>
                                        <option value="resolved">{translate('portal', 'status_resolved')}</option>
                                    </select>
                                </label>
                                <button className="button dark" disabled={processing}>{translate('portal', 'support_send_reply')}</button>
                            </>
                        )}
                    </Form>
                </article>
            ))}
            {!tickets.length && <Empty title={translate('portal', 'support_admin_empty')} />}
        </section>
    );
}
