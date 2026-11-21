import { useState } from 'react';
import { router } from '@inertiajs/react';
import OfficePage from '../OfficePage';
import { settingsBack } from './SettingsForm';
import { ActionForm, Pagination, dateTime, number, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { FilterChips } from '../../FilterChips';

type ErrorRow = {
    id: number;
    exception_class: string;
    message: string;
    file: string;
    line: number;
    portal: string | null;
    route_name: string | null;
    http_status: number;
    occurrences: number;
    first_seen_at: string;
    last_seen_at: string;
    fixed_at: string | null;
};

type ErrorDetail = ErrorRow & { user_id: number | null; user_name: string | null; fixed_by_name: string | null };

type ErrorsData = {
    rows: Row & { data: ErrorRow[] };
    counts: { open: number; fixed: number; week: number };
    filters: { q: string; status: string; sort: string };
    detail: ErrorDetail | null;
    settings: { enabled: boolean; retention_days: number; version: number };
    limits: { min: number; max: number };
};

const PORTALS = ['public', 'dealer', 'subdealer', 'manage', 'sadmin'];

/** Owner only: problems the site hit while running, one line for each kind, with a detail view and the log's own switch. */
/** The place in the code without the server's folders, so a screen never shows where the site is installed. */
const shortPath = (path: string): string => String(path).replace(/\\/g, '/').replace(/^.*?\/(app|vendor|routes|resources|bootstrap|database)\//, '$1/');

export default function Errors({ data }: { data: ErrorsData }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('office_errors', key, replace);
    const [q, setQ] = useState(data.filters.q);
    const [status, setStatus] = useState(data.filters.status);
    const [sort, setSort] = useState(data.filters.sort);
    const open = (query: Record<string, string>) => router.get('/errors', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '')), { preserveScroll: true });
    const apply = (event: React.FormEvent) => {
        event.preventDefault();
        open({ q, status, sort });
    };
    const portalName = (portal: string | null) => (portal === null ? t('d_none') : t(PORTALS.includes(portal) ? `portal_${portal}` : 'portal_other'));
    const detail = data.detail;
    const markFixed = (id: number) => {
        if (window.confirm(t('confirm_fixed'))) {
            router.post(`/commands/errors/${id}/fixed`, {}, { preserveScroll: true });
        }
    };

    return (
        <OfficePage title={t('title')} subtitle={t('subtitle')} back={settingsBack(translate)}>
            {!data.settings.enabled && <p className="notice" role="status">{t('off_notice')}</p>}
            <div className="stat-row">
                <div className="stat"><b>{number(data.counts.open)}</b><span>{t('open_count')}</span></div>
                <div className="stat"><b>{number(data.counts.fixed)}</b><span>{t('fixed_count')}</span></div>
                <div className="stat"><b>{number(data.counts.week)}</b><span>{t('week_count')}</span></div>
            </div>

            {detail && (
                <section className="panel" aria-label={t('d_title')}>
                    <h2>{t('d_title')}</h2>
                    <dl className="detail-list">
                        <dt>{t('d_message')}</dt><dd><b>{detail.exception_class.split('\\').pop()}</b><br />{detail.message || t('d_none')}</dd>
                        <dt>{t('d_file')}</dt><dd><code>{shortPath(detail.file)}:{detail.line}</code></dd>
                        <dt>{t('d_portal')}</dt><dd>{portalName(detail.portal)}</dd>
                        <dt>{t('d_route')}</dt><dd>{detail.route_name ?? t('d_none')}</dd>
                        <dt>{t('d_status')}</dt><dd>{detail.http_status}</dd>
                        <dt>{t('d_user')}</dt><dd>{detail.user_id === null ? t('d_nobody') : `${detail.user_name ?? ''} (#${detail.user_id})`}</dd>
                        <dt>{t('d_times')}</dt><dd>{number(detail.occurrences)}</dd>
                        <dt>{t('d_first')}</dt><dd>{dateTime(detail.first_seen_at)}</dd>
                        <dt>{t('d_last')}</dt><dd>{dateTime(detail.last_seen_at)}</dd>
                        {detail.fixed_at && <><dt>{t('d_fixed')}</dt><dd>{t('fixed_by', { name: detail.fixed_by_name ?? '', date: dateTime(detail.fixed_at) })}</dd></>}
                    </dl>
                    <div className="actions">
                        {!detail.fixed_at && <button type="button" className="button dark" onClick={() => markFixed(detail.id)}>{t('mark_fixed')}</button>}
                        <button type="button" className="button" onClick={() => open({ q, status, sort })}>{t('back_to_list')}</button>
                    </div>
                </section>
            )}

            <form className="cleanup-filters" onSubmit={apply}>
                <label>{t('search')}
                    <input type="search" value={q} maxLength={100} placeholder={t('search_placeholder')} onChange={(event) => setQ(event.target.value)} />
                </label>
                <FilterChips
                    label={t('status_label')}
                    showLabel
                    value={status}
                    onChange={(value) => { setStatus(value); open({ q, status: value, sort }); }}
                    options={['open', 'fixed', 'all'].map((value) => ({ value, label: t(`status_${value}`) }))}
                />
                <FilterChips
                    label={t('sort_label')}
                    showLabel
                    value={sort}
                    onChange={(value) => { setSort(value); open({ q, status, sort: value }); }}
                    options={['last_seen', 'first_seen', 'count'].map((value) => ({ value, label: t(`sort_${value}`) }))}
                />
                <button type="submit" className="button">{t('apply')}</button>
            </form>

            {(
                <section className="panel table-panel">
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{t('col_problem')}</th>
                                    <th scope="col">{t('col_where')}</th>
                                    <th scope="col">{t('col_times')}</th>
                                    <th scope="col">{t('col_last')}</th>
                                    <th scope="col">{t('col_state')}</th>
                                    <th scope="col"><span className="sr-only">{t('details')}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.rows.data.length === 0 && (
                                    <tr><td colSpan={6} className="set-line">{data.filters.q || data.filters.status !== 'open' ? t('empty_filtered') : t('empty_open')}</td></tr>
                                )}
                                {data.rows.data.map((row) => (
                                    <tr key={row.id}>
                                        <td><b>{row.exception_class.split('\\').pop()}</b><small className="cell-sub">{row.message}</small></td>
                                        <td>{portalName(row.portal)}{row.route_name && <small className="cell-sub">{row.route_name}</small>}</td>
                                        <td>{number(row.occurrences)}</td>
                                        <td>{dateTime(row.last_seen_at)}</td>
                                        <td>{row.fixed_at ? t('state_fixed') : t('state_open')}</td>
                                        <td><button type="button" className="button" onClick={() => open({ q, status, sort, error: String(row.id) })}>{t('details')}</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination page={data.rows} />
                </section>
            )}

            <div className="set-sheet">
                <section className="set-block">
                    <h2>{t('tracking_title')}</h2>
                    <p className="set-line">{t('privacy_line')}</p>
                    <ActionForm
                        key={data.settings.version}
                        action="/commands/owner/error-tracking"
                        defaults={{ enabled: data.settings.enabled ? '1' : '0', retention_days: String(data.settings.retention_days), version: String(data.settings.version) }}
                        fields={[
                            {
                                name: 'enabled',
                                label: t('tracking_field'),
                                options: [{ value: '1', label: t('tracking_on') }, { value: '0', label: t('tracking_off') }],
                                required: true,
                                value: data.settings.enabled ? '1' : '0',
                            },
                            {
                                name: 'retention_days',
                                label: t('days_field'),
                                type: 'number',
                                required: true,
                                hint: t('days_hint', { min: String(data.limits.min), max: String(data.limits.max) }),
                                value: String(data.settings.retention_days),
                            },
                        ]}
                        submit={t('save')}
                    />
                </section>
            </div>
        </OfficePage>
    );
}
