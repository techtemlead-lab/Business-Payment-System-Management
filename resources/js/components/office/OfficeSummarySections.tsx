import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ArrowUpRight } from 'lucide-react';
import { dateTime, money, number, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { localizeDigits } from '../../business-date';

type Props = { sections: string[] };

/** One bordered box with its own heading, a link to the full page, and a skeleton until the numbers arrive. */
function Box({ id, title, href, ready, children, wide }: { id: string; title: string; href?: string; ready: boolean; children: ReactNode; wide?: boolean }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;

    return (
        <section className={`panel osb${wide ? ' is-wide' : ''}`} data-tour={`dash-${id}`} aria-labelledby={`osb-${id}`} aria-busy={!ready}>
            <div className="osb-head">
                <h2 id={`osb-${id}`}>{title}</h2>
                {href && ready && <Link href={`${base}${href}`} className="text-link osb-link">{translate('cr18', 'see_full')} <ArrowUpRight size={15} /></Link>}
            </div>
            {ready ? children : <div className="osb-skeleton" aria-hidden="true"><i /><i /><i /></div>}
        </section>
    );
}

function Stat({ label, value, text }: { label: string; value: string; text?: boolean }) {
    return (
        <div className="osb-stat">
            <span>{label}</span>
            <strong>{text ? value : money(value)}</strong>
        </div>
    );
}

function useSection(key: string, sections: string[]): { shown: boolean; data: Row | undefined } {
    const loaded: Row | undefined = usePage<any>().props.dash_sections;

    return { shown: sections.includes(key), data: loaded?.[key] };
}

function Ranking({ title, rows, unit }: { title: string; rows: { name: string; orders?: number; units?: number }[]; unit: 'orders' | 'units' }) {
    const translate = useT();

    return (
        <div className="osb-rank">
            <h3>{title}</h3>
            {rows.length === 0 ? <p className="muted">{translate('cr18', 'nothing_yet')}</p> : (
                <ol>
                    {rows.map((row) => (
                        <li key={row.name}>
                            <span>{row.name}</span>
                            <b>{translate('cr18', unit === 'orders' ? 'orders_count' : 'units_count', { count: number(unit === 'orders' ? row.orders : row.units) })}</b>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

export function MoneySection({ sections }: Props) {
    const translate = useT();
    const { shown, data } = useSection('money', sections);
    if (!shown) {
        return null;
    }

    return (
        <Box id="money" title={translate('cr18', 'money_title')} href={data?.funds ? '/treasury/funds' : '/treasury/orders'} ready={data !== undefined} wide>
            {data && (
                <div className="osb-money">
                    {data.funds && (
                        <div className="osb-group">
                            <h3>{translate('cr18', 'money_balance_title')}</h3>
                            <div className="osb-stat is-big"><span>{translate('cr18', 'money_balance')}</span><strong>{money(data.funds.total)}</strong></div>
                            <ul className="osb-funds">
                                {data.funds.main.map((fund: Row) => <li key={fund.key}><span>{fund.name}</span><b>{money(fund.balance)}</b></li>)}
                            </ul>
                        </div>
                    )}
                    {data.sales && (
                        <div className="osb-group">
                            <h3>{translate('cr18', 'sales_title')}</h3>
                            <Stat label={translate('cr18', 'sales_to_date')} value={data.sales.to_date} />
                            <Stat label={translate('cr18', 'sales_month')} value={data.sales.month} />
                            <Stat label={translate('cr18', 'sales_today')} value={data.sales.today} />
                        </div>
                    )}
                    {data.waiting && (
                        <div className="osb-group">
                            <h3>{translate('cr18', 'waiting_title')}</h3>
                            <Stat text label={translate('cr18', 'waiting_payments')} value={number(data.waiting.payments)} />
                            <Stat text label={translate('cr18', 'waiting_withdrawals')} value={number(data.waiting.withdrawals)} />
                        </div>
                    )}
                </div>
            )}
        </Box>
    );
}

export function SiteSection({ sections }: Props) {
    const translate = useT();
    const { shown, data } = useSection('site', sections);
    if (!shown) {
        return null;
    }
    const trend: { key: string; visitors: number }[] = data?.trend ?? [];
    const peak = Math.max(...trend.map((point) => point.visitors), 1);

    return (
        <Box id="site" title={translate('cr18', 'site_title')} href="/visitors" ready={data !== undefined}>
            {data && (
                <>
                    <div className="osb-stats">
                        <Stat text label={translate('cr18', 'site_today')} value={number(data.today_visitors)} />
                        <Stat text label={translate('cr18', 'site_now')} value={number(data.active_now)} />
                        <Stat text label={translate('cr18', 'site_week')} value={number(data.week_visitors)} />
                    </div>
                    {trend.length > 1 && (
                        <div className="osb-bars" role="img" aria-label={translate('cr18', 'site_trend')}>
                            {trend.map((point) => (
                                <span key={point.key} title={`${localizeDigits(point.key)}: ${number(point.visitors)}`} style={{ height: `${Math.max(6, (point.visitors / peak) * 100)}%` }} />
                            ))}
                        </div>
                    )}
                    {data.pages.length > 0 && (
                        <div className="osb-rank">
                            <h3>{translate('cr18', 'site_pages')}</h3>
                            <ol>{data.pages.map((page: Row) => <li key={page.path}><span>{page.path}</span><b>{translate('cr18', 'views_count', { count: number(page.views) })}</b></li>)}</ol>
                        </div>
                    )}
                </>
            )}
        </Box>
    );
}

export function BusinessSection({ sections }: Props) {
    const translate = useT();
    const { shown, data } = useSection('business', sections);
    if (!shown) {
        return null;
    }

    return (
        <Box id="business" title={translate('cr18', 'business_title')} href="/orders" ready={data !== undefined} wide>
            {data && (
                <>
                    <div className="osb-stats">
                        <Stat text label={translate('cr18', 'biz_today_orders')} value={number(data.today.orders)} />
                        <Stat label={translate('cr18', 'biz_today_amount')} value={data.today.amount} />
                    </div>
                    <div className="osb-ranks">
                        <Ranking title={`${translate('cr18', 'biz_districts')} · ${translate('cr18', 'period_today')}`} rows={data.districts.today} unit="orders" />
                        <Ranking title={`${translate('cr18', 'biz_districts')} · ${translate('cr18', 'period_month')}`} rows={data.districts.month} unit="orders" />
                        <Ranking title={`${translate('cr18', 'biz_divisions')} · ${translate('cr18', 'period_today')}`} rows={data.divisions.today} unit="orders" />
                        <Ranking title={`${translate('cr18', 'biz_divisions')} · ${translate('cr18', 'period_month')}`} rows={data.divisions.month} unit="orders" />
                        <Ranking title={translate('cr18', 'biz_products')} rows={data.products} unit="units" />
                    </div>
                </>
            )}
        </Box>
    );
}

export function EventsSection({ sections }: Props) {
    const translate = useT();
    const { shown, data } = useSection('events', sections);
    if (!shown) {
        return null;
    }
    const rows: Row[] = data?.rows ?? [];

    return (
        <Box id="events" title={translate('cr18', 'events_title')} href="/treasury/events" ready={data !== undefined} wide>
            {rows.length === 0 ? <p className="muted">{translate('cr18', 'nothing_yet')}</p> : (
                <div className="odt-scroll">
                    <table className="odt-table">
                        <thead><tr><th scope="col">{translate('cr18', 'col_when')}</th><th scope="col">{translate('cr18', 'col_what')}</th><th scope="col">{translate('cr18', 'col_who')}</th><th scope="col" className="is-num">{translate('cr18', 'col_amount')}</th></tr></thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td data-label={translate('cr18', 'col_when')}>{dateTime(row.occurred_at)}</td>
                                    <td data-label={translate('cr18', 'col_what')}>{row.action_label}{row.label ? ` · ${row.label}` : ''}</td>
                                    <td data-label={translate('cr18', 'col_who')}>{row.partner_name || row.order_number || '—'}</td>
                                    <td data-label={translate('cr18', 'col_amount')} className="is-num">{row.amount ? money(row.amount) : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Box>
    );
}

export function ActivitySection({ sections }: Props) {
    const translate = useT();
    const { shown, data } = useSection('activity', sections);
    if (!shown) {
        return null;
    }

    return (
        <Box id="activity" title={translate('cr18', 'activity_title')} href="/visitors" ready={data !== undefined}>
            {data && (
                <div className="osb-stats">
                    <Stat text label={translate('cr18', 'activity_online')} value={number(data.online)} />
                    <Stat text label={translate('cr18', 'activity_today')} value={number(data.today)} />
                </div>
            )}
        </Box>
    );
}
