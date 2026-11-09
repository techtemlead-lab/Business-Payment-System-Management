import { useState, type FormEvent } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowUpRight, CheckCircle2, Languages, LifeBuoy, ScrollText, Search } from 'lucide-react';
import { Badge, Empty, FormErrors, Modal, Pagination, Table, dateTime, label, type Row } from './UI';
import OfficePage from './office/OfficePage';
import { useT } from '../shared/i18n/t';

type Language = 'bn' | 'en';

type AgreementVersion = {
    id: number;
    version: number;
    body_bn: string;
    body_en: string;
    published_at: string | null;
    published_by?: string | null;
    acceptances?: number;
};

type PartnerAgreement = {
    mode: 'partner';
    required: boolean;
    blocking: boolean;
    accepted: boolean;
    latest: AgreementVersion | null;
    accepted_versions: Array<{ version: number; accepted_at: string | null }>;
};

type OfficeAgreement = {
    mode: 'office';
    can_edit: boolean;
    setting: { required: boolean; version: number };
    versions: AgreementVersion[];
    summary: { partners: number; accepted_latest: number };
    partners: Row;
};

/**
 * The dealer agreement screen. Partners read the latest version and press
 * "I have read and agree"; the office sees every version and who agreed, and
 * owners publish new versions and turn the "must accept" switch on or off.
 */
export default function AgreementHub({ agreement }: { agreement?: PartnerAgreement | OfficeAgreement }) {
    if (!agreement) {
        return null;
    }

    return agreement.mode === 'partner' ? <PartnerAgreementView agreement={agreement} /> : <OfficeAgreementView agreement={agreement} />;
}

/** The agreement text in one language, with a button to read it in the other. */
function AgreementText({ version, initialLanguage }: { version: AgreementVersion; initialLanguage: Language }) {
    const translate = useT();
    const [language, setLanguage] = useState<Language>(initialLanguage);
    const other: Language = language === 'bn' ? 'en' : 'bn';

    return (
        <>
            <div className="agr-meta">
                <span className="badge">{translate('portal', 'agr_version_label', { version: String(version.version) })}</span>
                {version.published_at && <span className="muted">{translate('portal', 'agr_published_on', { date: dateTime(version.published_at) })}</span>}
                <button type="button" className="button small agr-language" lang={other} onClick={() => setLanguage(other)}>
                    <Languages size={16} aria-hidden="true" />
                    {translate('portal', other === 'en' ? 'agr_read_in_english' : 'agr_read_in_bangla')}
                </button>
            </div>
            <article className="agr-text" lang={language} tabIndex={0}>
                {language === 'en' ? version.body_en : version.body_bn}
            </article>
        </>
    );
}

function PartnerAgreementView({ agreement }: { agreement: PartnerAgreement }) {
    const translate = useT();
    const { locale, portalBase: base = '' } = usePage<any>().props;
    const latest = agreement.latest;
    const form = useForm({ version_id: latest?.id ?? 0 });
    const acceptedLatest = latest ? agreement.accepted_versions.find((entry) => entry.version === latest.version) : undefined;

    const accept = (event: FormEvent) => {
        event.preventDefault();
        form.post('/commands/agreement/accept', { preserveScroll: true });
    };

    return (
        <section className="panel agr-panel" data-tour="agreement">
            {agreement.blocking && (
                <div className="agr-notice" role="alert">
                    <ScrollText size={20} aria-hidden="true" />
                    <p>{translate('portal', 'agr_blocking_notice')}</p>
                </div>
            )}
            <div className="panel-heading">
                <div>
                    <h2>{translate('portal', 'agreement')}</h2>
                    <p className="muted">{translate('portal', 'agr_partner_hint')}</p>
                </div>
            </div>
            {latest ? (
                <>
                    <AgreementText version={latest} initialLanguage={locale === 'en' ? 'en' : 'bn'} />
                    {agreement.accepted ? (
                        <p className="agr-done" role="status">
                            <CheckCircle2 size={20} aria-hidden="true" />
                            {translate('portal', 'agr_accepted_on', {
                                version: String(latest.version),
                                date: acceptedLatest?.accepted_at ? dateTime(acceptedLatest.accepted_at) : '',
                            })}
                        </p>
                    ) : (
                        <form className="agr-accept" onSubmit={accept}>
                            <FormErrors errors={form.errors} />
                            <button type="submit" className="button dark" disabled={form.processing}>
                                <CheckCircle2 size={18} aria-hidden="true" />
                                {form.processing ? translate('portal', 'saving') : translate('portal', 'agr_accept_button')}
                            </button>
                        </form>
                    )}
                    <p className="agr-help">
                        <Link className="text-link" href={`${base}/help`}>
                            <LifeBuoy size={16} aria-hidden="true" /> {translate('portal', 'agr_help_link')}
                        </Link>
                    </p>
                </>
            ) : (
                <Empty title={translate('portal', 'agr_none')} body="" />
            )}
        </section>
    );
}

function OfficeAgreementView({ agreement }: { agreement: OfficeAgreement }) {
    const translate = useT();
    const page = usePage<any>();
    const { locale } = page.props;
    const hub = page.props.data?.hub ?? null;
    const latest = agreement.versions[0] ?? null;
    const [view, setView] = useState<'partners' | 'versions'>('partners');
    const [publishing, setPublishing] = useState(false);

    return (
        <OfficePage
            title={translate('office_website', 'partners_title')}
            hub={hub}
            action={agreement.can_edit ? <button type="button" className="button dark" onClick={() => setPublishing(true)}>{translate('portal', 'agr_edit_title')}</button> : undefined}
        >
            <div className="agr-office">
                <RequirementSwitch setting={agreement.setting} canEdit={agreement.can_edit} summary={translate('portal', 'agr_summary', { accepted: String(agreement.summary.accepted_latest), partners: String(agreement.summary.partners) })} />
                <div className="ow-switch" role="group" aria-label={translate('office_website', 'agreement_view_label')}>
                    <button type="button" className={view === 'partners' ? 'is-active' : ''} aria-pressed={view === 'partners'} onClick={() => setView('partners')}>{translate('portal', 'agr_partners_title')}</button>
                    <button type="button" className={view === 'versions' ? 'is-active' : ''} aria-pressed={view === 'versions'} onClick={() => setView('versions')}>{translate('portal', 'agr_history_title')}</button>
                </div>
                {view === 'partners' && <AcceptanceList partners={agreement.partners} latestVersion={latest?.version ?? null} />}
                {view === 'versions' && (agreement.versions.length ? (
                    <ol className="agr-history">
                        {agreement.versions.map((version, index) => (
                            <li key={version.id}>
                                <details open={index === 0 && !agreement.can_edit}>
                                    <summary>
                                        <b>{translate('portal', 'agr_version_label', { version: String(version.version) })}</b>
                                        {index === 0 ? <span className="badge approved">{translate('portal', 'agr_current')}</span> : <span className="badge">{translate('portal', 'agr_old_version')}</span>}
                                        <span className="muted">
                                            {version.published_by
                                                ? translate('portal', 'agr_published_by', { name: version.published_by, date: dateTime(version.published_at) })
                                                : translate('portal', 'agr_published_by_system', { date: dateTime(version.published_at) })}
                                        </span>
                                        <span className="muted">{translate('portal', 'agr_accept_count', { count: String(version.acceptances ?? 0) })}</span>
                                    </summary>
                                    <AgreementText version={version} initialLanguage={locale === 'en' ? 'en' : 'bn'} />
                                </details>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <Empty line title={translate('portal', 'agr_none')} />
                ))}
            </div>
            {publishing && (
                <Modal title={translate('portal', 'agr_edit_title')} onClose={() => setPublishing(false)}>
                    <PublishForm latest={latest} onDone={() => setPublishing(false)} />
                </Modal>
            )}
        </OfficePage>
    );
}

/** The owner switch "Partners must accept the agreement". Staff only see its state. */
function RequirementSwitch({ setting, canEdit, summary }: { setting: OfficeAgreement['setting']; canEdit: boolean; summary: string }) {
    const translate = useT();
    const form = useForm({ required: '', version: '' });
    const state = translate('portal', setting.required ? 'agr_switch_on' : 'agr_switch_off');

    return (
        <div className="agr-switch">
            <div>
                <h3>{translate('portal', 'agr_switch_title')} <span className={`badge ${setting.required ? 'approved' : ''}`}>{translate('portal', 'agr_switch_now', { state })}</span></h3>
                <p className="field-hint">{translate('portal', 'agr_switch_body')}</p>
                <p className="agr-summary">{summary}</p>
            </div>
            {canEdit && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        // Read the switch at the moment of the click, so a second click after saving flips it back.
                        form.transform(() => ({ required: setting.required ? '0' : '1', version: String(setting.version) }));
                        form.post('/commands/agreement/setting', { preserveScroll: true });
                    }}
                >
                    <FormErrors errors={form.errors} />
                    <button type="submit" className={`button ${setting.required ? '' : 'dark'}`} disabled={form.processing}>
                        {form.processing ? translate('portal', 'saving') : translate('portal', setting.required ? 'agr_switch_turn_off' : 'agr_switch_turn_on')}
                    </button>
                </form>
            )}
        </div>
    );
}

/** Owner only: edit both texts and publish them as the next version. */
function PublishForm({ latest, onDone }: { latest: AgreementVersion | null; onDone: () => void }) {
    const translate = useT();
    const form = useForm({ body_bn: latest?.body_bn ?? '', body_en: latest?.body_en ?? '' });
    const nextVersion = String((latest?.version ?? 0) + 1);

    return (
        <form
            className="action-form agr-editor"
            onSubmit={(event) => {
                event.preventDefault();
                if (!window.confirm(translate('portal', 'agr_publish_confirm', { version: nextVersion }))) {
                    return;
                }
                form.post('/commands/agreement/publish', { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <p className="field-hint">{translate('portal', 'agr_edit_hint')}</p>
            <label lang="bn">
                {translate('portal', 'agr_body_bn')}
                <textarea rows={16} value={form.data.body_bn} required onChange={(event) => form.setData('body_bn', event.target.value)} />
                {form.errors.body_bn && <span className="field-error" role="alert">{form.errors.body_bn}</span>}
            </label>
            <label lang="en">
                {translate('portal', 'agr_body_en')}
                <textarea rows={16} value={form.data.body_en} required onChange={(event) => form.setData('body_en', event.target.value)} />
                {form.errors.body_en && <span className="field-error" role="alert">{form.errors.body_en}</span>}
            </label>
            <button type="submit" className="button dark" disabled={form.processing}>
                {form.processing ? translate('portal', 'saving') : translate('portal', 'agr_publish')}
                <ArrowUpRight size={17} />
            </button>
        </form>
    );
}

/** Dealers and sub-dealers with the newest version each one accepted. */
function AcceptanceList({ partners, latestVersion }: { partners: Row; latestVersion: number | null }) {
    const translate = useT();
    const [query, setQuery] = useState(() => (typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('q') ?? '' : ''));

    return (
        <>
            <form
                className="agr-search"
                role="search"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/agreement', query ? { q: query } : {}, { preserveState: true, preserveScroll: true });
                }}
            >
                <input type="search" value={query} placeholder={translate('portal', 'agr_search')} aria-label={translate('portal', 'agr_search')} onChange={(event) => setQuery(event.target.value)} />
                <button type="submit" className="button small">
                    <Search size={16} aria-hidden="true" /> {translate('portal', 'search')}
                </button>
            </form>
            <Table
                searchable={false}
                rows={partners?.data ?? []}
                columns={[
                    { key: 'name', title: translate('portal', 'col_name'), render: (row: Row) => <>{row.name}<small className="cell-sub">{row.dealer_code}</small></> },
                    { key: 'role', title: translate('portal', 'col_role'), render: (row: Row) => <>{label(String(row.role ?? ''), translate)}<small className="cell-sub"><Badge status={String(row.status ?? '')} /></small></> },
                    {
                        key: 'accepted_version',
                        title: translate('portal', 'agr_col_version'),
                        render: (row: Row) => row.accepted_version
                            ? <span className={`badge ${Number(row.accepted_version) === latestVersion ? 'approved' : 'pending'}`}>{translate('portal', 'agr_version_label', { version: String(row.accepted_version) })}</span>
                            : <span className="muted">{translate('portal', 'agr_not_accepted')}</span>,
                    },
                    { key: 'accepted_at', title: translate('portal', 'agr_col_accepted_at'), render: (row: Row) => (row.accepted_at ? dateTime(row.accepted_at) : '—') },
                    { key: 'ip_address', title: translate('portal', 'agr_col_ip'), render: (row: Row) => row.ip_address || '—' },
                ]}
            />
            <Pagination page={partners} />
        </>
    );
}
