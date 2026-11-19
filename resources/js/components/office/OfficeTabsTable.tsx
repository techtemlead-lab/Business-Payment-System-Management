import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge, channelLabel, date, label, money, number, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

type Column = { key: string; type: 'text' | 'money' | 'date' | 'status' | 'payment' | 'role' | 'channel' | 'count' };
type TabData = {
    tab: string;
    columns: Column[];
    rows: Row[];
    total: number;
    page: number;
    last_page: number;
    all_href: string;
    search: string;
};

/**
 * CR-18: one table area under the dashboard buttons. The tab names across the top are the column
 * headers of the area: tapping one shows that list. The rows come from the server only for the open
 * tab (a partial reload of `dash_tab`), so the dashboard itself stays quick.
 */
export default function OfficeTabsTable({ tabs }: { tabs: string[] }) {
    const translate = useT();
    const { url, props } = usePage<any>();
    const base: string = props.portalBase ?? '';
    const data: TabData | null | undefined = props.dash_tab;
    const [busy, setBusy] = useState(false);
    const [words, setWords] = useState('');
    const openTab = data?.tab ?? tabs[0];

    useEffect(() => {
        setWords(data?.search ?? '');
    }, [data?.tab, data?.search]);

    if (tabs.length === 0) {
        return null;
    }

    const ask = (change: Record<string, string | undefined>) => {
        const current = new URL(url || '/', 'http://dash.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => { params[key] = value; });
        for (const [key, value] of Object.entries(change)) {
            if (value === undefined || value === '') {
                delete params[key];
            } else {
                params[key] = value;
            }
        }
        router.get(current.pathname, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['dash_tab'],
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    const cell = (column: Column, row: Row) => {
        const value = row[column.key];
        if (value === null || value === undefined || value === '') {
            return <span className="muted">—</span>;
        }
        switch (column.type) {
            case 'money': return money(value);
            case 'date': return date(value);
            case 'count': return number(value);
            case 'status': return <Badge status={String(value)} />;
            case 'role': return label(String(value), translate);
            case 'channel': return channelLabel(value, translate);
            case 'payment': return value === 'none' ? <span className="muted">—</span> : <span className={`office-pay is-${value}`}>{translate('cr18', `pay_${value}`)}</span>;
            default: return String(value);
        }
    };

    const heading = (tab: string, key: string) => {
        const own = translate('cr18', `col_${tab}_${key}`);

        return own === `col_${tab}_${key}` ? translate('cr18', `col_${key}`) : own;
    };

    const loading = data === undefined || busy;

    return (
        <section className="panel odt" data-tour="office-tabs" aria-labelledby="odt-title">
            <h2 id="odt-title" className="odt-title">{translate('cr18', 'tabs_title')}</h2>
            <div className="odt-row" role="tablist" aria-label={translate('cr18', 'tabs_title')}>
                {tabs.map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        id={`office-tab-${key}`}
                        aria-selected={openTab === key}
                        aria-controls="office-tab-panel"
                        className={`odt-tab${openTab === key ? ' is-active' : ''}`}
                        onClick={() => ask({ tab: key, tq: undefined, tp: undefined })}
                    >
                        {translate('cr18', `tab_${key}`)}
                    </button>
                ))}
            </div>
            <div id="office-tab-panel" role="tabpanel" aria-labelledby={`office-tab-${openTab}`} aria-busy={loading}>
                <div className="odt-tools">
                    <form
                        className="odt-search"
                        role="search"
                        onSubmit={(event) => {
                            event.preventDefault();
                            ask({ tab: openTab, tq: words.trim(), tp: undefined });
                        }}
                    >
                        <input type="search" value={words} maxLength={100} aria-label={translate('cr18', 'search_label')} placeholder={translate('cr18', 'search_placeholder')} onChange={(event) => setWords(event.target.value)} />
                        <button type="submit" className="button small">{translate('cr18', 'search_button')}</button>
                    </form>
                    {data && <Link href={`${base}${data.all_href}`} className="text-link odt-all">{translate('cr18', 'view_all')} <ArrowUpRight size={15} /></Link>}
                </div>
                {loading && !data && (
                    <div className="odt-skeleton" aria-hidden="true">
                        {[0, 1, 2, 3, 4].map((line) => <i key={line} />)}
                    </div>
                )}
                {data && (
                    <div className={`odt-body${busy ? ' is-busy' : ''}`}>
                        {data.rows.length === 0 ? (
                            <p className="muted odt-empty">{translate('cr18', data.search ? 'empty_search' : 'empty')}</p>
                        ) : (
                            <div className="odt-scroll">
                                <table className="odt-table">
                                    <thead>
                                        <tr>{data.columns.map((column) => <th key={column.key} scope="col" className={column.type === 'money' || column.type === 'count' ? 'is-num' : undefined}>{heading(data.tab, column.key)}</th>)}</tr>
                                    </thead>
                                    <tbody>
                                        {data.rows.map((row, index) => (
                                            <tr key={`${row.href ?? ''}-${index}`}>
                                                {data.columns.map((column, position) => {
                                                    const text = heading(data.tab, column.key);
                                                    return (
                                                        <td key={column.key} data-label={text} className={column.type === 'money' || column.type === 'count' ? 'is-num' : undefined}>
                                                            {position === 0 && row.href ? <Link href={`${base}${row.href}`} className="text-link">{cell(column, row)}</Link> : cell(column, row)}
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <div className="odt-foot">
                            <span>{translate('cr18', 'total_count', { count: number(data.total) })}</span>
                            {data.last_page > 1 && (
                                <span className="odt-pages">
                                    <button type="button" className="button small" disabled={data.page <= 1 || busy} onClick={() => ask({ tab: data.tab, tq: data.search, tp: String(data.page - 1) })}>{translate('cr18', 'prev')}</button>
                                    <span>{translate('cr18', 'page_of', { page: number(data.page), last: number(data.last_page) })}</span>
                                    <button type="button" className="button small" disabled={data.page >= data.last_page || busy} onClick={() => ask({ tab: data.tab, tq: data.search, tp: String(data.page + 1) })}>{translate('cr18', 'next')}</button>
                                </span>
                            )}
                        </div>
                    </div>
                )}
                {data === null && <p className="muted odt-empty">{translate('cr18', 'empty')}</p>}
            </div>
        </section>
    );
}
