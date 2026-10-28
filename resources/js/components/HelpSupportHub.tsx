import { Form, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    BookOpen,
    LifeBuoy,
    Search,
    Sparkles,
    Compass,
    Info,
    HelpCircle,
    CheckCircle2,
    AlertTriangle,
    Lightbulb,
    FileText,
    ChevronDown,
    ChevronRight,
    ThumbsUp,
    Check,
    RotateCcw,
    X,
} from 'lucide-react';
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
    const [selectedCategory, setSelectedCategory] = useState<string>('all');
    const [activeKey, setActiveKey] = useState<string>(String(sections[0]?.key || ''));
    const [expandedFaq, setExpandedFaq] = useState<number | null>(null);
    const [feedbackSent, setFeedbackSent] = useState<string | null>(null);

    // Filter sections based on query and selected category
    const filtered = useMemo(() => {
        let list = sections;
        if (selectedCategory !== 'all') {
            list = list.filter((s) => s.badge === selectedCategory);
        }
        const needle = query.trim().toLowerCase();
        if (!needle) return list;

        return list.filter((section) => {
            const blob = [
                section.key,
                section.title,
                section.summary,
                section.purpose,
                section.why,
                ...(section.steps || []),
                ...(section.outcomes || []),
                ...(section.rules || []),
                ...(section.tips || []),
                ...(section.warnings || []),
                ...(section.keywords || []),
                ...(section.faqs || []).map((f: { q: string; a: string }) => `${f.q} ${f.a}`),
            ].join(' ').toLowerCase();

            return blob.includes(needle);
        });
    }, [query, sections, selectedCategory]);

    const active = filtered.find((section) => section.key === activeKey) || filtered[0] || null;

    // Available badges for quick category filtering
    const allBadges = useMemo(() => {
        const set = new Set<string>();
        sections.forEach((s) => {
            if (s.badge) set.add(s.badge);
        });
        return Array.from(set);
    }, [sections]);

    return (
        <div className="help-support-hub">
            <PrerequisiteHelper guide={data.guide_prerequisites} tourBlocked={[]} />

            {/* Enterprise Documentation Panel */}
            <section className="panel help-manual-enterprise-panel" data-tour="help-manual">
                <div className="panel-heading">
                    <div>
                        <div className="help-manual-brand-tag">
                            <BookOpen size={16} />
                            <span>{translate('portal', 'help_manual_chapter_badge')}</span>
                        </div>
                        <h2>{translate('portal', 'help_manual_title')}</h2>
                        <p className="muted">{translate('portal', 'help_manual_hint')}</p>
                    </div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        <button
                            type="button"
                            className="button small dark"
                            onClick={() => router.post('/commands/tours/restart', {}, { preserveScroll: true })}
                        >
                            <Sparkles size={15} />
                            {translate('portal', 'tour_btn_start_tour')}
                        </button>
                    </div>
                </div>

                {/* Search & Category Filter Toolbar */}
                <div className="help-manual-toolbar">
                    <div className="help-manual-search-box">
                        <Search size={17} className="search-icon" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                router.get('/help', { q: event.target.value || undefined }, { preserveState: true, replace: true });
                            }}
                            placeholder={translate('portal', 'help_manual_search_ph')}
                            aria-label={translate('portal', 'help_manual_search_ph')}
                        />
                        {query && (
                            <button
                                type="button"
                                className="clear-search-btn"
                                onClick={() => {
                                    setQuery('');
                                    router.get('/help', {}, { preserveState: true, replace: true });
                                }}
                            >
                                <X size={15} />
                            </button>
                        )}
                    </div>

                    {allBadges.length > 0 && (
                        <div className="help-manual-category-pills">
                            <button
                                type="button"
                                className={`category-pill ${selectedCategory === 'all' ? 'active' : ''}`}
                                onClick={() => setSelectedCategory('all')}
                            >
                                {translate('portal', 'all')} ({sections.length})
                            </button>
                            {allBadges.map((badgeName) => (
                                <button
                                    key={badgeName}
                                    type="button"
                                    className={`category-pill ${selectedCategory === badgeName ? 'active' : ''}`}
                                    onClick={() => setSelectedCategory(badgeName)}
                                >
                                    {badgeName}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* 2-Column Documentation Reader Layout */}
                <div className="help-manual-layout">
                    {/* Left Sidebar Table of Contents */}
                    <aside className="help-manual-toc">
                        <div className="help-toc-header">
                            <span>{translate('portal', 'help_manual_filter_all')}</span>
                            <span className="help-toc-count">
                                {filtered.length} / {sections.length}
                            </span>
                        </div>

                        <div className="help-toc-scroll-list">
                            {filtered.map((section, index) => {
                                const isActive = active?.key === section.key;
                                return (
                                    <button
                                        key={section.key}
                                        type="button"
                                        className={`help-toc-item ${isActive ? 'active' : ''}`}
                                        onClick={() => {
                                            setActiveKey(section.key);
                                            setExpandedFaq(null);
                                        }}
                                    >
                                        <span className="help-manual-toc-index">{index + 1}</span>
                                        <div className="help-toc-item-content">
                                            {section.badge && (
                                                <span className="help-toc-badge">{section.badge}</span>
                                            )}
                                            <strong className="help-toc-title">{section.title}</strong>
                                            <p className="help-toc-snippet">{section.summary}</p>
                                        </div>
                                    </button>
                                );
                            })}
                            {!filtered.length && (
                                <div className="help-toc-empty">
                                    <HelpCircle size={24} />
                                    <p className="muted">{translate('portal', 'help_manual_no_results')}</p>
                                </div>
                            )}
                        </div>
                    </aside>

                    {/* Right Main Article Reader */}
                    <article className="help-manual-article">
                        {active ? (
                            <>
                                {/* Article Header */}
                                <div className="help-article-header">
                                    <div className="help-article-meta-top">
                                        <span className="help-article-eyebrow">
                                            {translate('portal', 'help_manual_title')} · {active.key.replaceAll('_', ' ').toUpperCase()}
                                        </span>
                                        {active.badge && (
                                            <span className="help-article-category-badge">{active.badge}</span>
                                        )}
                                    </div>
                                    <h2 className="help-article-title">{active.title}</h2>
                                    <blockquote className="help-article-summary">
                                        {active.summary}
                                    </blockquote>

                                    {/* Quick In-Page Anchors */}
                                    <nav className="help-article-anchors" aria-label="Article Outline">
                                        <span className="anchors-label">{translate('portal', 'help_manual_quick_nav')}:</span>
                                        {active.purpose && <a href="#purpose">{translate('portal', 'help_manual_purpose')}</a>}
                                        {active.why && <a href="#why">{translate('portal', 'help_manual_why')}</a>}
                                        {(active.steps || []).length > 0 && <a href="#steps">{translate('portal', 'help_manual_steps')}</a>}
                                        {(active.outcomes || []).length > 0 && <a href="#outcomes">{translate('portal', 'help_manual_outcomes')}</a>}
                                        {(active.rules || []).length > 0 && <a href="#rules">{translate('portal', 'help_manual_rules')}</a>}
                                        {(active.tips || []).length > 0 && <a href="#tips">{translate('portal', 'help_manual_tips')}</a>}
                                        {(active.warnings || []).length > 0 && <a href="#warnings">{translate('portal', 'help_manual_warnings')}</a>}
                                        {(active.faqs || []).length > 0 && <a href="#faqs">{translate('portal', 'help_manual_faqs')}</a>}
                                    </nav>
                                </div>

                                {/* Section 1: Purpose & Overview */}
                                {active.purpose && (
                                    <section id="purpose" className="help-manual-callout help-manual-purpose">
                                        <div className="callout-icon-col">
                                            <Info size={22} className="callout-icon" />
                                        </div>
                                        <div className="callout-body">
                                            <h4>{translate('portal', 'help_manual_purpose')}</h4>
                                            <p>{active.purpose}</p>
                                        </div>
                                    </section>
                                )}

                                {/* Section 2: Why It Exists & Core Value */}
                                {active.why && (
                                    <section id="why" className="help-manual-callout help-manual-why">
                                        <div className="callout-icon-col">
                                            <Compass size={22} className="callout-icon" />
                                        </div>
                                        <div className="callout-body">
                                            <h4>{translate('portal', 'help_manual_why')}</h4>
                                            <p>{active.why}</p>
                                        </div>
                                    </section>
                                )}

                                {/* Section 3: Step-by-Step Practical Workflow */}
                                {(active.steps || []).length > 0 && (
                                    <section id="steps" className="help-article-section">
                                        <h3 className="help-manual-section-title">
                                            <span className="section-title-bullet" />
                                            {translate('portal', 'help_manual_steps')}
                                        </h3>
                                        <div className="help-steps-timeline">
                                            {active.steps.map((stepText: string, index: number) => (
                                                <div className="help-timeline-item" key={`${active.key}-step-${index}`}>
                                                    <div className="timeline-badge">
                                                        <span>{index + 1}</span>
                                                    </div>
                                                    <div className="timeline-content">
                                                        <p className="timeline-step-text">{stepText}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                )}

                                {/* Section 4: Expected Outcomes */}
                                {(active.outcomes || []).length > 0 && (
                                    <section id="outcomes" className="help-manual-callout help-manual-outcomes">
                                        <div className="callout-icon-col">
                                            <CheckCircle2 size={22} className="callout-icon" />
                                        </div>
                                        <div className="callout-body">
                                            <h4>{translate('portal', 'help_manual_outcomes')}</h4>
                                            <ul className="help-check-list">
                                                {active.outcomes.map((outcome: string, index: number) => (
                                                    <li key={`${active.key}-out-${index}`}>
                                                        <Check size={16} className="inline-check" />
                                                        <span>{outcome}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    </section>
                                )}

                                {/* Section 5: Enterprise Business Rules & Formulas */}
                                {(active.rules || []).length > 0 && (
                                    <section id="rules" className="help-manual-callout help-manual-rules">
                                        <div className="callout-icon-col">
                                            <FileText size={22} className="callout-icon" />
                                        </div>
                                        <div className="callout-body">
                                            <h4>{translate('portal', 'help_manual_rules')}</h4>
                                            <ul className="help-rules-list">
                                                {active.rules.map((rule: string, index: number) => (
                                                    <li key={`${active.key}-rule-${index}`}>{rule}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    </section>
                                )}

                                {/* Section 6: Pro Tips */}
                                {(active.tips || []).length > 0 && (
                                    <section id="tips" className="help-manual-callout help-manual-tips-callout">
                                        <div className="callout-icon-col">
                                            <Lightbulb size={22} className="callout-icon" />
                                        </div>
                                        <div className="callout-body">
                                            <h4>{translate('portal', 'help_manual_tips')}</h4>
                                            <ul className="help-tips-list">
                                                {active.tips.map((tip: string, index: number) => (
                                                    <li key={`${active.key}-tip-${index}`}>{tip}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    </section>
                                )}

                                {/* Section 7: Security Warnings & Compliance */}
                                {(active.warnings || []).length > 0 && (
                                    <section id="warnings" className="help-manual-callout help-manual-warnings">
                                        <div className="callout-icon-col">
                                            <AlertTriangle size={22} className="callout-icon" />
                                        </div>
                                        <div className="callout-body">
                                            <h4>{translate('portal', 'help_manual_warnings')}</h4>
                                            <ul className="help-warnings-list">
                                                {active.warnings.map((warning: string, index: number) => (
                                                    <li key={`${active.key}-warn-${index}`}>{warning}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    </section>
                                )}

                                {/* Section 8: Interactive FAQ Accordion */}
                                {(active.faqs || []).length > 0 && (
                                    <section id="faqs" className="help-article-section help-faq-section">
                                        <h3 className="help-manual-section-title">
                                            <span className="section-title-bullet" />
                                            {translate('portal', 'help_manual_faqs')}
                                        </h3>
                                        <div className="help-faq-accordion">
                                            {active.faqs.map((faq: { q: string; a: string }, fIdx: number) => {
                                                const isOpen = expandedFaq === fIdx;
                                                return (
                                                    <div className={`help-faq-item ${isOpen ? 'open' : ''}`} key={fIdx}>
                                                        <button
                                                            type="button"
                                                            className="help-faq-question-btn"
                                                            onClick={() => setExpandedFaq(isOpen ? null : fIdx)}
                                                            aria-expanded={isOpen}
                                                        >
                                                            <span>{faq.q}</span>
                                                            {isOpen ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
                                                        </button>
                                                        {isOpen && (
                                                            <div className="help-faq-answer">
                                                                <p>{faq.a}</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </section>
                                )}

                                {/* Article Footer Feedback */}
                                <div className="help-article-footer">
                                    <div className="help-feedback-block">
                                        <span className="feedback-prompt">
                                            {translate('portal', 'help_manual_feedback_prompt')}
                                        </span>
                                        {feedbackSent === active.key ? (
                                            <span className="feedback-thankyou">
                                                <Check size={16} />
                                                {translate('portal', 'help_manual_feedback_thanks')}
                                            </span>
                                        ) : (
                                            <div className="feedback-buttons">
                                                <button
                                                    type="button"
                                                    className="button small"
                                                    onClick={() => setFeedbackSent(active.key)}
                                                >
                                                    <ThumbsUp size={14} />
                                                    {translate('portal', 'yes')}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="button small"
                                                    onClick={() => setFeedbackSent(active.key)}
                                                >
                                                    {translate('portal', 'no')}
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </>
                        ) : (
                            <Empty title={translate('portal', 'help_manual_no_results')} />
                        )}
                    </article>
                </div>
            </section>

            {/* Support Ticket Submission Panel */}
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

            {/* My Tickets List Panel */}
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
