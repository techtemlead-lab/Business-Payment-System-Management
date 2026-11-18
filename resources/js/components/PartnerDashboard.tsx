import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Mail, MapPin, Phone, Store, Users } from 'lucide-react';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { ProfilePhoto } from './ProfilePhoto';
import { date, money, number, type Row } from './UI';
import type { IncomeCard } from './mymoney/types';
import { useT } from '../shared/i18n/t';

/** One line of the profile card: a small icon, a word and the value. */
function CardLine({ icon, label, children }: { icon: React.ReactNode; label: string; children: React.ReactNode }) {
    return (
        <div className="pd-line">
            <span className="pd-line-icon" aria-hidden="true">{icon}</span>
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/** Left column: who the partner is, where they work, when they joined and how many people they brought. */
function ProfileCard({ card }: { card: Row }) {
    const translate = useT();
    const type = card.type === 'sub_dealer' ? 'type_sub_dealer' : 'type_dealer';

    return (
        <aside className="pd-card pd-profile" data-tour="home-profile" aria-label={String(card.name ?? '')}>
            <div className="pd-photo"><ProfilePhoto src={card.photo_url} initial={card.initial || '?'} variant="cover" /></div>
            <h2 className="pd-name">{card.name}</h2>
            <p className="pd-type">{translate('cr_partner', type)}</p>
            {card.shop_name && <p className="pd-shop"><Store size={16} aria-hidden="true" /> {card.shop_name}</p>}
            <dl className="pd-lines">
                {card.area && <CardLine icon={<MapPin size={16} />} label={translate('cr_partner', 'card_area')}>{card.area}</CardLine>}
                {card.phone && <CardLine icon={<Phone size={16} />} label={translate('cr_partner', 'card_mobile')}>{card.phone}</CardLine>}
                {card.email && <CardLine icon={<Mail size={16} />} label={translate('cr_partner', 'card_email')}>{card.email}</CardLine>}
                {card.joined_on && <CardLine icon={<CalendarDays size={16} />} label={translate('cr_partner', 'card_joined')}>{date(card.joined_on.split('/').reverse().join('-'))}</CardLine>}
            </dl>
            <dl className="pd-counts">
                <div><dt><Users size={15} aria-hidden="true" /> {translate('cr_partner', 'card_direct')}</dt><dd>{number(card.direct ?? 0)}</dd></div>
                <div><dt>{translate('cr_partner', 'card_gen1')}</dt><dd>{number(card.gen1 ?? 0)}</dd></div>
                <div><dt>{translate('cr_partner', 'card_gen2')}</dt><dd>{number(card.gen2 ?? 0)}</dd></div>
            </dl>
        </aside>
    );
}

/** Middle column: the three big figures, then the income head by head. */
function MoneyColumn({ totals, cards }: { totals: Row; cards?: IncomeCard[] }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;

    return (
        <section className="pd-middle" data-tour="home-metrics" aria-label={translate('my_money', 'title')}>
            <div className="pd-card pd-totals">
                <div><span>{translate('cr_partner', 'total_income')}</span><strong>{money(totals.income)}</strong></div>
                <div title={translate('cr_partner', 'total_withdrawn_hint')}><span>{translate('cr_partner', 'total_withdrawn')}</span><strong>{money(totals.withdrawn)}</strong></div>
                <div className="is-balance"><span>{translate('cr_partner', 'total_balance')}</span><strong>{money(totals.balance)}</strong></div>
            </div>
            <div className="pd-card pd-heads">
                <div className="pd-heads-top">
                    <h3>{translate('cr_partner', 'heads_title')}</h3>
                    <Link className="text-link" href={`${base}/my-money`}>{translate('cr_partner', 'open_wallet')}</Link>
                </div>
                {cards === undefined && <p className="muted" aria-busy="true">{translate('cr_partner', 'report_loading')}</p>}
                {cards !== undefined && cards.length === 0 && <p className="muted">{translate('cr_partner', 'heads_empty')}</p>}
                {cards !== undefined && cards.length > 0 && (
                    <ul className="pd-head-list">
                        {cards.map((card) => (
                            <li key={card.key}><span>{card.label}</span><strong>{money(card.amount)}</strong></li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

/** The three columns of the dashboard. The targets panel (right) is built by the home page and keeps updating by itself. */
export function DashboardGrid({ home, moneyCards, targetsPanel }: { home: Row; moneyCards?: IncomeCard[]; targetsPanel: React.ReactNode }) {
    return (
        <div className="pd-wrap">
            <div className="pd-grid">
                <ProfileCard card={home.card ?? {}} />
                <MoneyColumn totals={home.totals ?? {}} cards={moneyCards} />
                <div className="pd-right">{targetsPanel}</div>
            </div>
        </div>
    );
}

type ReportRow = {
    id: string;
    occurred_at: string;
    what: string;
    percent: string | null;
    order_id: string | null;
    order_is_own?: boolean;
    order_number: string | null;
    from_name: string | null;
    status: 'waiting' | 'ready' | 'taken_back';
    waiting_until: string | null;
    amount: string;
};

type Report = { tabs: { key: string; label: string }[]; tab: string; table: MoneyTableData<ReportRow> };

/** Below the cards: the total report and one table for each head, with the usual search, filters and dates. */
export function IncomeReport() {
    const translate = useT();
    const { url, props } = usePage<any>();
    const report: Report | undefined = props.income_report;
    const base: string = props.portalBase ?? '';
    if (!report) {
        return (
            <section className="pd-card pd-report" aria-busy="true">
                <h3>{translate('cr_partner', 'report_title')}</h3>
                <p className="muted">{translate('cr_partner', 'report_loading')}</p>
            </section>
        );
    }
    const choose = (tab: string) => {
        const current = new URL(url || '/', 'http://report.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => { params[key] = value; });
        delete params.page;
        delete params.q;
        delete params.status;
        params.rtab = tab;
        router.get(current.pathname, params, { preserveState: true, preserveScroll: true, replace: true, only: ['income_report'] });
    };
    const columns: MoneyColumn<ReportRow>[] = [
        { key: 'occurred_at', title: translate('income', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.occurred_at) },
        {
            key: 'what',
            title: translate('income', 'col_what'),
            phone: 'title',
            render: (row) => (
                <>
                    {row.what}{Number(row.percent) > 0 ? ` ${number(row.percent)}%` : ''}
                    {row.waiting_until && <small className="cell-sub">{translate('income', 'waiting_until', { date: date(row.waiting_until) })}</small>}
                </>
            ),
        },
        { key: 'source', title: translate('income', 'col_source'), render: (row) => [row.order_number, row.from_name].filter(Boolean).join(' · ') || '—' },
        { key: 'status', title: translate('income', 'col_status'), render: (row) => translate('income', `status_${row.status}`) },
        {
            key: 'amount',
            title: translate('income', 'col_amount'),
            sortKey: 'amount',
            money: true,
            total: 'amount',
            phone: 'amount',
            render: (row) => <span className={Number(row.amount) < 0 ? 'is-negative' : undefined}>{Number(row.amount) < 0 ? '-' : '+'} {money(Math.abs(Number(row.amount)))}</span>,
        },
    ];

    return (
        <section className="pd-card pd-report" id="mm-book" aria-labelledby="pd-report-title">
            <h3 id="pd-report-title">{translate('cr_partner', 'report_title')}</h3>
            <div className="pd-tabs" role="tablist" aria-label={translate('cr_partner', 'report_title')}>
                {report.tabs.map((tab) => (
                    <button key={tab.key} type="button" role="tab" aria-selected={tab.key === report.tab} className={`pd-tab${tab.key === report.tab ? ' is-on' : ''}`} onClick={() => choose(tab.key)}>
                        {tab.label}
                    </button>
                ))}
            </div>
            <MoneyTable
                data={report.table}
                prop="income_report"
                columns={columns}
                searchPlaceholder={translate('income', 'search')}
                rowHref={(row) => (row.order_id && row.order_is_own !== false ? `${base}/orders/${row.order_id}` : null)}
                empty={translate('income', 'empty')}
            />
        </section>
    );
}
