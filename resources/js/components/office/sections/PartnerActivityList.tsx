import { router } from '@inertiajs/react';
import { Laptop, Smartphone, Tablet } from 'lucide-react';
import { Table, dateTime, number, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { localizeDigits } from '../../../business-date';
import { FilterDropdown } from '../../FilterDropdown';

export interface PartnerActivityRow {
    id: number;
    name: string;
    type: 'dealer' | 'sub_dealer';
    code: string | null;
    area: string | null;
    online: boolean;
    last_seen_at: string | null;
    ip: string | null;
    device: 'pc' | 'phone' | 'tablet' | null;
    browser: string | null;
    os: string | null;
    last_login_at: string | null;
}

/** What PartnerActivityReport sends (CR-17). */
export interface PartnerActivityReport {
    filter: string;
    q: string;
    counts: { dealers: number; sub_dealers: number };
    rows: PartnerActivityRow[];
    page: number;
    pages: number;
    total: number;
}

const FILTERS = ['all', 'dealers', 'sub_dealers', 'online', 'idle'] as const;

/** Owner only: who is online now and when each dealer or sub-dealer was last active. */
export default function PartnerActivityList({ report }: { report: PartnerActivityReport }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('cr17', key, replace);

    /** Opens the page again with this list's filter, search and page; the time-range choice above stays as it is. */
    const go = (change: { status?: string; q?: string; page?: number }) => {
        const query = Object.fromEntries(new URLSearchParams(window.location.search));
        const next: Record<string, string> = { ...query };
        const status = change.status ?? report.filter;
        const search = change.q ?? report.q;
        if (status === 'all') { delete next.status; } else { next.status = status; }
        if (search === '') { delete next.q; } else { next.q = search; }
        if (!change.page || change.page <= 1) { delete next.page; } else { next.page = String(change.page); }
        router.get('/visitors', next, { preserveState: true, preserveScroll: true, replace: true });
    };

    const ago = (iso: string | null): string => {
        if (!iso) {
            return t('never');
        }
        const minutes = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
        if (minutes < 1) { return t('ago_now'); }
        if (minutes < 60) { return t('ago_minutes', { count: localizeDigits(String(minutes)) }); }
        if (minutes < 1440) { return t('ago_hours', { count: localizeDigits(String(Math.floor(minutes / 60))) }); }

        return t('ago_days', { count: localizeDigits(String(Math.floor(minutes / 1440))) });
    };

    const deviceIcon = (kind: PartnerActivityRow['device']) => (kind === 'phone' ? <Smartphone size={15} aria-hidden="true" /> : kind === 'tablet' ? <Tablet size={15} aria-hidden="true" /> : <Laptop size={15} aria-hidden="true" />);

    return (
        <section className="panel table-panel" aria-labelledby="partner-activity-title">
            <div className="panel-heading"><h2 id="partner-activity-title">{t('title')}</h2></div>
            <p className="vis-note"><strong>{t('online_line', { dealers: number(report.counts.dealers), sub_dealers: number(report.counts.sub_dealers) })}</strong></p>
            <p className="vis-note">{t('online_note')}</p>
            <div className="mt-toolbar">
                <FilterDropdown
                    label={t('filter_label')}
                    showLabel
                    value={report.filter}
                    onChange={(choice) => go({ status: choice, page: 1 })}
                    options={FILTERS.map((choice) => ({ value: choice, label: t(`filter_${choice}`) }))}
                />
                <form className="mt-filters" role="search" onSubmit={(event) => { event.preventDefault(); go({ q: String(new FormData(event.currentTarget).get('q') ?? '').trim(), page: 1 }); }}>
                    <input type="search" name="q" key={report.q} defaultValue={report.q} maxLength={100} placeholder={t('search')} aria-label={t('search')} />
                </form>
            </div>
            <Table<Row>
                rows={report.rows as unknown as Row[]}
                searchable={false}
                tools={false}
                empty={t('empty')}
                columns={[
                    { key: 'name', title: t('col_name'), render: (row) => <>{String(row.name)}{row.code ? <small> · {String(row.code)}</small> : null}</> },
                    { key: 'type', title: t('col_type'), render: (row) => t(`type_${row.type}`) },
                    { key: 'area', title: t('col_area'), render: (row) => String(row.area ?? '—') },
                    { key: 'online', title: t('col_online'), render: (row) => <span role="img" aria-label={t(row.online ? 'dot_online' : 'dot_offline')} title={t(row.online ? 'dot_online' : 'dot_offline')} style={{ display: 'inline-block', width: 12, height: 12, borderRadius: '50%', background: row.online ? '#1a9b4b' : '#b8b8b8' }} /> },
                    { key: 'last_seen_at', title: t('col_last'), render: (row) => (row.last_seen_at ? <>{ago(String(row.last_seen_at))}<br /><small>{dateTime(String(row.last_seen_at))}</small></> : t('never')) },
                    { key: 'ip', title: t('col_ip'), render: (row) => String(row.ip ?? '—') },
                    { key: 'device', title: t('col_device'), render: (row) => (row.device ? <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>{deviceIcon(row.device as PartnerActivityRow['device'])}{t(`device_${row.device}`)}{row.os ? <small> · {String(row.os)}</small> : null}</span> : '—') },
                    { key: 'browser', title: t('col_browser'), render: (row) => String(row.browser ?? '—') },
                    { key: 'last_login_at', title: t('col_login'), render: (row) => (row.last_login_at ? dateTime(String(row.last_login_at)) : '—') },
                ]}
            />
            <div className="ops-row is-wrap" aria-label={t('page_of', { page: String(report.page), pages: String(report.pages), total: String(report.total) })}>
                <button type="button" className="button small" disabled={report.page <= 1} onClick={() => go({ page: report.page - 1 })}>{t('prev')}</button>
                <span>{t('page_of', { page: localizeDigits(String(report.page)), pages: localizeDigits(String(report.pages)), total: number(report.total) })}</span>
                <button type="button" className="button small" disabled={report.page >= report.pages} onClick={() => go({ page: report.page + 1 })}>{t('next')}</button>
            </div>
        </section>
    );
}
