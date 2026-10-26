import { Form, router, usePage } from '@inertiajs/react';
import { BookOpen, LifeBuoy, RotateCcw } from 'lucide-react';
import { Badge, date, Empty, Table, type Row } from './UI';
import { useT } from '../shared/i18n/t';

export default function HelpSupportHub({ data }: { data: Row }) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const sections: Row[] = data.manual_sections || [];
    const tickets: Row[] = data.support_tickets || [];
    const categories: string[] = data.support_categories || [];

    return (
        <div className="help-support-hub">
            <section className="panel" data-tour="help-manual">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'help_manual_title')}</h2>
                        <p className="muted">{translate('portal', 'help_manual_hint')}</p>
                    </div>
                    <BookOpen size={22} />
                </div>
                <div className="help-manual-grid">
                    {sections.map((section) => (
                        <article key={section.key} className="help-manual-card">
                            <h3>{section.title}</h3>
                            <p>{section.body}</p>
                        </article>
                    ))}
                </div>
                <button
                    type="button"
                    className="button small"
                    onClick={() => router.post('/commands/tours/restart', { page: 'overview' })}
                >
                    <RotateCcw size={14} /> {translate('portal', 'restart_tour')}
                </button>
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
