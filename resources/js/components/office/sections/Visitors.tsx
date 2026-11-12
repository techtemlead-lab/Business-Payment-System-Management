import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import OfficePage from '../OfficePage';
import { ReportFilterSheet } from '../../BranchPerformanceHub';
import type { OfficeHub } from '../../OfficeHubTabs';
import { ActionForm, Table, dateTime, number, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { businessDate, businessMonth, localizeDigits } from '../../../business-date';

type Bucket = 'hour' | 'day' | 'month';
type TrendBar = { key: string; views: number; visitors: number };

/** What VisitorsSection sends: the owner's visitor count for one stretch of days. */
export interface VisitorsReport {
    period: { key: string; from: string; to: string; bucket: Bucket };
    active_now: number;
    summary: { views: number; visitors: number; views_change: number | null; visitors_change: number | null };
    trend: TrendBar[];
    devices: { mobile: number; desktop: number };
    sources: { source: string; views: number }[];
    pages: { path: string; views: number }[];
    products: { id: number; name: string; views: number }[];
    recent: { path: string; device: string; source: string; at: string }[];
    details_partial: boolean;
    keep_months: number;
    counting: { enabled: boolean; version: number };
}

/** The ready-made views, in the order the buttons show. "day" and "custom" come from the date boxes. */
const PERIODS = ['today', 'yesterday', 'week', 'last_week', 'month', 'last_month', 'year', 'all'] as const;

/** About this many bars get a label under them; the rest show theirs on hover. */
const MOST_BAR_LABELS = 8;

const percentOf = (part: number, whole: number): number => (whole > 0 ? Math.round((part / whole) * 100) : 0);

/**
 * Owners only: how many people visit the public website, where they come from and what they open.
 * The page refreshes itself every 30 seconds with the rest of the portal's live pages.
 */
export default function Visitors({ report, hub }: { report: VisitorsReport; hub?: OfficeHub | null }) {
    const translate = useT();
    const { period, summary, devices } = report;
    const pickedFrom = period.key === 'day' || period.key === 'custom' ? period.from : '';
    const pickedTo = period.key === 'custom' ? period.to : '';

    const visit = (query: { period?: string; from?: string; to?: string }) => {
        router.get('/visitors', query, { preserveState: true, preserveScroll: true, replace: true });
    };
    /** One date shows that day; a second date makes it a range. */
    const chooseDates = (from: string, to: string) => {
        if (from && to) {
            visit({ period: 'custom', from, to });
        } else if (from) {
            visit({ period: 'day', from });
        } else {
            visit({});
        }
    };
    const datePickers = (
        <>
            <label>{translate('office_visitors', 'from')}<input type="date" value={pickedFrom} onChange={(event) => chooseDates(event.target.value, pickedTo)} /></label>
            <label>{translate('office_visitors', 'to')}<input type="date" value={pickedTo} min={pickedFrom || undefined} disabled={!pickedFrom} onChange={(event) => chooseDates(pickedFrom, event.target.value)} /></label>
        </>
    );

    const barLabel = (key: string): string => {
        if (period.bucket === 'hour') {
            return translate('office_visitors', 'chart_hour', { hour: String(Number(key)) });
        }

        return period.bucket === 'month' ? businessMonth(key) : businessDate(key);
    };
    const shortBarLabel = (key: string): string => {
        if (period.bucket === 'hour') {
            return localizeDigits(key);
        }

        return period.bucket === 'month' ? businessMonth(key) : localizeDigits(`${Number(key.slice(8))}/${Number(key.slice(5, 7))}`);
    };
    const changeLine = (change: number | null): ReactNode => {
        if (change === null) {
            return <small className="vis-change">{translate('office_visitors', 'change_none')}</small>;
        }
        if (change === 0) {
            return <small className="vis-change">{translate('office_visitors', 'change_same')}</small>;
        }

        return (
            <small className={`vis-change ${change > 0 ? 'is-up' : 'is-down'}`}>
                {translate('office_visitors', change > 0 ? 'change_up' : 'change_down', { percent: String(Math.abs(change)) })}
            </small>
        );
    };

    const downloadFile = () => {
        const cell = (value: string | number): string => `"${String(value).replace(/"/g, '""')}"`;
        const lines = [
            [translate('office_visitors', 'file_period'), `${period.from} - ${period.to}`],
            [translate('office_visitors', 'visitors'), summary.visitors],
            [translate('office_visitors', 'views'), summary.views],
            [translate('office_visitors', 'device_mobile'), devices.mobile],
            [translate('office_visitors', 'device_desktop'), devices.desktop],
            [],
            [translate('office_visitors', 'file_date'), translate('office_visitors', 'visitors'), translate('office_visitors', 'views')],
            ...report.trend.map((bar) => [period.bucket === 'hour' ? `${period.from} ${bar.key}:00` : bar.key, bar.visitors, bar.views]),
        ];
        // The leading mark makes Excel read the Bangla words correctly.
        const file = new Blob(['﻿' + lines.map((line) => line.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const address = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = address;
        link.download = `visitors-${period.from}-${period.to}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(address);
    };

    const tallest = Math.max(1, ...report.trend.map((bar) => bar.views));
    const labelEvery = Math.max(1, Math.ceil(report.trend.length / MOST_BAR_LABELS));
    const hasVisits = summary.views > 0;
    const deviceViews = devices.mobile + devices.desktop;
    const arrivals = report.sources.reduce((sum, row) => sum + row.views, 0);
    const mostOpened = report.pages[0]?.views ?? 0;
    const mostViewed = report.products[0]?.views ?? 0;
    const times = (count: number): string => translate('office_visitors', 'times', { count: String(count) });

    return (
        <OfficePage
            title={hub?.label ?? translate('portal', 'visitors')}
            hub={hub}
            action={<button type="button" className="button" onClick={downloadFile}><Download size={16} aria-hidden="true" />{translate('office_visitors', 'download')}</button>}
        >
            {!report.counting.enabled && <p className="notice" role="status">{translate('office_visitors', 'counting_off_notice')}</p>}

            <div className="money-table">
                <div className="mt-toolbar">
                    <ReportFilterSheet>{datePickers}</ReportFilterSheet>
                    <div className="mt-views" role="group" aria-label={translate('office_visitors', 'pick_period')}>
                        {PERIODS.map((choice) => (
                            <button key={choice} type="button" className="mt-view" aria-pressed={period.key === choice} onClick={() => visit({ period: choice })}>
                                {translate('office_visitors', `period_${choice}`)}
                            </button>
                        ))}
                    </div>
                    <div className="mt-filters vis-dates">{datePickers}</div>
                </div>
            </div>

            <div className="office-figures" data-cards="4">
                <div className="office-figure">
                    <span>{translate('office_visitors', 'now')}</span>
                    <strong>{number(report.active_now)}</strong>
                </div>
                <div className="office-figure">
                    <span>{translate('office_visitors', 'visitors')}</span>
                    <strong className="is-headline">{number(summary.visitors)}</strong>
                    {changeLine(summary.visitors_change)}
                </div>
                <div className="office-figure">
                    <span>{translate('office_visitors', 'views')}</span>
                    <strong>{number(summary.views)}</strong>
                    {changeLine(summary.views_change)}
                </div>
                <div className="office-figure">
                    <span>{translate('office_visitors', 'phone_share')}</span>
                    <strong>{localizeDigits(`${percentOf(devices.mobile, deviceViews)}%`)}</strong>
                </div>
            </div>
            <p className="vis-note">
                {period.from === period.to
                    ? translate('office_visitors', 'showing_day', { from: businessDate(period.from) })
                    : translate('office_visitors', 'showing', { from: businessDate(period.from), to: businessDate(period.to) })}
                {' '}{translate('office_visitors', 'visitor_rule')}
            </p>

            <section className="vis-card">
                <h2>{translate('office_visitors', 'chart_title')}</h2>
                {hasVisits ? (
                    <>
                        <p className="vis-legend">
                            <span><i aria-hidden="true" />{translate('office_visitors', 'views')}</span>
                            <span><i className="is-visitors" aria-hidden="true" />{translate('office_visitors', 'visitors')}</span>
                        </p>
                        <div className="vis-chart-scroll">
                            <ol className="vis-chart">
                                {report.trend.map((bar, index) => {
                                    const summaryLine = translate('office_visitors', 'chart_bar', { label: barLabel(bar.key), views: String(bar.views), visitors: String(bar.visitors) });

                                    return (
                                        <li key={bar.key} className="vis-col" title={summaryLine}>
                                            <span className="vis-col-bars" aria-hidden="true">
                                                <i style={{ height: `${(bar.views / tallest) * 100}%` }} />
                                                <i className="is-visitors" style={{ height: `${(bar.visitors / tallest) * 100}%` }} />
                                            </span>
                                            <small aria-hidden="true">{index % labelEvery === 0 ? shortBarLabel(bar.key) : ''}</small>
                                            <span className="sr-only">{summaryLine}</span>
                                        </li>
                                    );
                                })}
                            </ol>
                        </div>
                    </>
                ) : (
                    <p className="vis-empty">{translate('office_visitors', 'none_yet')}</p>
                )}
            </section>

            {report.details_partial && <p className="vis-note" role="note">{translate('office_visitors', 'details_partial', { months: String(report.keep_months) })}</p>}

            <div className="vis-grid">
                <section className="vis-card">
                    <h2>{translate('office_visitors', 'devices_title')}</h2>
                    {deviceViews > 0 ? (
                        <>
                            <span className="vis-split" aria-hidden="true">
                                <i style={{ width: `${percentOf(devices.mobile, deviceViews)}%` }} />
                                <i className="is-desktop" style={{ width: `${100 - percentOf(devices.mobile, deviceViews)}%` }} />
                            </span>
                            <ul className="vis-rows">
                                <li className="vis-row"><b>{translate('office_visitors', 'device_mobile')}</b><span>{times(devices.mobile)} · {localizeDigits(`${percentOf(devices.mobile, deviceViews)}%`)}</span></li>
                                <li className="vis-row"><b>{translate('office_visitors', 'device_desktop')}</b><span>{times(devices.desktop)} · {localizeDigits(`${100 - percentOf(devices.mobile, deviceViews)}%`)}</span></li>
                            </ul>
                        </>
                    ) : (
                        <p className="vis-empty">{translate('office_visitors', 'none_yet')}</p>
                    )}
                </section>

                <RankedList
                    title={translate('office_visitors', 'sources_title')}
                    empty={translate('office_visitors', 'none_yet')}
                    rows={report.sources.map((row) => ({
                        key: row.source,
                        label: translate('office_visitors', `source_${row.source}`),
                        value: `${times(row.views)} · ${localizeDigits(`${percentOf(row.views, arrivals)}%`)}`,
                        share: percentOf(row.views, arrivals),
                    }))}
                />
                <RankedList
                    title={translate('office_visitors', 'pages_title')}
                    empty={translate('office_visitors', 'none_yet')}
                    rows={report.pages.map((row) => ({
                        key: row.path,
                        label: row.path === '/' ? translate('office_visitors', 'page_home') : row.path,
                        value: times(row.views),
                        share: percentOf(row.views, mostOpened),
                    }))}
                />
                <RankedList
                    title={translate('office_visitors', 'products_title')}
                    empty={translate('office_visitors', 'none_yet')}
                    rows={report.products.map((row) => ({
                        key: String(row.id),
                        label: row.name,
                        value: times(row.views),
                        share: percentOf(row.views, mostViewed),
                    }))}
                />
            </div>

            <section className="panel table-panel">
                <div className="panel-heading"><h2>{translate('office_visitors', 'recent_title')}</h2></div>
                <Table<Row>
                    rows={report.recent as unknown as Row[]}
                    searchable={false}
                    tools={false}
                    empty={translate('office_visitors', 'none_yet')}
                    columns={[
                        { key: 'at', title: translate('office_visitors', 'col_time'), render: (row) => dateTime(row.at) },
                        { key: 'path', title: translate('office_visitors', 'col_page'), render: (row) => (row.path === '/' ? translate('office_visitors', 'page_home') : row.path) },
                        { key: 'device', title: translate('office_visitors', 'col_device'), render: (row) => translate('office_visitors', `device_${row.device}`) },
                        { key: 'source', title: translate('office_visitors', 'col_source'), render: (row) => translate('office_visitors', `source_${row.source}`) },
                    ]}
                />
            </section>

            <div className="set-sheet">
                <section className="set-block">
                    <h2>{translate('office_visitors', 'counting_title')}</h2>
                    <p className="set-line">{translate('office_visitors', 'privacy_line')}</p>
                    <ActionForm
                        key={report.counting.version}
                        action="/commands/owner/site-visit-counting"
                        defaults={{ enabled: report.counting.enabled ? '1' : '0', version: String(report.counting.version) }}
                        fields={[{
                            name: 'enabled',
                            label: translate('office_visitors', 'counting_field'),
                            options: [{ value: '1', label: translate('office_visitors', 'counting_on') }, { value: '0', label: translate('office_visitors', 'counting_off') }],
                            required: true,
                            value: report.counting.enabled ? '1' : '0',
                        }]}
                        submit={translate('office_visitors', 'counting_save')}
                    />
                </section>
            </div>
        </OfficePage>
    );
}

/** A short list, biggest first, each row with a bar for its share. */
function RankedList({ title, empty, rows }: { title: string; empty: string; rows: { key: string; label: string; value: string; share: number }[] }) {
    return (
        <section className="vis-card">
            <h2>{title}</h2>
            {rows.length === 0 ? (
                <p className="vis-empty">{empty}</p>
            ) : (
                <ol className="vis-rows">
                    {rows.map((row) => (
                        <li key={row.key} className="vis-row">
                            <b>{row.label}</b>
                            <span>{row.value}</span>
                            <span className="vis-meter" aria-hidden="true"><i style={{ width: `${row.share}%` }} /></span>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
