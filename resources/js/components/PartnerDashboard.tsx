import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Building2, CalendarDays, Hash, Landmark, Mail, MapPin, Users } from 'lucide-react';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { ProfilePhoto } from './ProfilePhoto';
import { date, money, number, type Row } from './UI';
import type { CompanyBenefits as CompanyBenefitsData, IncomeCard } from './mymoney/types';
import CompanyBenefitsList from './mymoney/CompanyBenefits';
import { useT } from '../shared/i18n/t';
import AppointmentPill from './AppointmentPill';

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
            <p className="pd-type"><AppointmentPill appointment={card.appointment} fallback={translate('cr_partner', type)} testId="home-appointment" /></p>
            <dl className="pd-lines">
                {card.code && <CardLine icon={<Hash size={16} />} label={translate('cr21_identity', card.type === 'sub_dealer' ? 'label_code_sub_dealer' : 'label_code_dealer')}>{card.code}</CardLine>}
                {card.type === 'sub_dealer' && <CardLine icon={<Building2 size={16} />} label={translate('cr21_identity', 'label_chamber')}>{card.shop_name || translate('cr21_identity', 'not_given')}</CardLine>}
                {card.appointment?.area && <CardLine icon={<Landmark size={16} />} label={translate('cr21_identity', 'label_area')}>{card.appointment.area}</CardLine>}
                {card.area && <CardLine icon={<MapPin size={16} />} label={translate('cr21_identity', 'label_address')}>{card.area}</CardLine>}
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

/** The identity boxes at the top of the middle column: mobile, code, rank, this month's purchase and what is left of the target. */
function IdentityBoxes({ card }: { card: Row }) {
    const translate = useT();
    const c = (key: string) => translate('cr21_identity', key);
    const isSub = card.type === 'sub_dealer';
    const targetLeft = card.target_left === null || card.target_left === undefined
        ? null
        : Number(card.target_left) > 0 ? money(card.target_left) : c('target_done');
    const boxes: [string, React.ReactNode][] = [
        [c('label_mobile'), card.phone],
        [c(isSub ? 'label_code_sub_dealer' : 'label_code_dealer'), card.code],
        ...(card.rank ? [[c('label_rank'), translate('portal', `net_rank_${card.rank}`)] as [string, React.ReactNode]] : []),
        [c('label_month_purchase'), money(card.month_purchase ?? 0)],
        ...(targetLeft !== null ? [[c('label_target_left'), targetLeft] as [string, React.ReactNode]] : []),
    ];

    return (
        <dl className="pd-card pd-idboxes" data-testid="home-idboxes">
            {boxes.filter(([, value]) => value !== null && value !== undefined && value !== '').map(([label, value]) => (
                <div key={label}><dt>{label}</dt><dd>{value}</dd></div>
            ))}
        </dl>
    );
}

/** Middle column: the three big figures, then the income head by head. */
function MoneyColumn({ totals, cards, held, pending, benefits, card }: { totals: Row; cards?: IncomeCard[]; held?: Row; pending?: Row; benefits?: CompanyBenefitsData | null; card: Row }) {
    const translate = useT();
    const { portalBase: base, portalCatalog } = usePage<any>().props;
    const heldLines: Row[] = held?.lines ?? [];
    const mayWithdraw: boolean = (portalCatalog?.sections ?? []).includes('my-money');

    return (
        <section className="pd-middle" data-tour="home-metrics" aria-label={translate('my_money', 'title')}>
            <IdentityBoxes card={card} />
            <div className="pd-card pd-totals">
                <div><span>{translate('cr_partner', 'total_income')}</span><strong>{money(totals.income)}</strong></div>
                <div title={translate('cr_partner', 'total_withdrawn_hint')}><span>{translate('cr_partner', 'total_withdrawn')}</span><strong>{money(totals.withdrawn)}</strong></div>
                <div className="is-balance"><span>{translate('cr_partner', 'total_balance')}</span><strong>{money(totals.balance)}</strong></div>
            </div>
            {/* Money that waits for the month's target or the month-end close: beside the balance, never inside it. */}
            {Number(pending?.total ?? 0) > 0 && (
                <div className="pd-card pd-held" data-testid="home-pending-balance">
                    <div className="pd-heads-top">
                        <h3>{translate('my_money', 'pending_balance')}</h3>
                        <strong>{money(pending?.total)}</strong>
                    </div>
                    <p className="muted">{translate('my_money', 'pending_balance_hint')}</p>
                </div>
            )}
            {/* Prizes already earned (rank prize, bike, car) that are not in the balance yet: one line each with the plain reason. */}
            {(pending?.rewards ?? []).length > 0 && (
                <div className="pd-card pd-held" data-testid="home-rewards-waiting">
                    <div className="pd-heads-top">
                        <h3>{translate('my_money', 'rewards_heading')}</h3>
                    </div>
                    <ul>
                        {(pending?.rewards ?? []).map((line: Row) => (
                            <li key={line.id}>
                                <div className="pd-heads-top">
                                    <span>{line.what}</span>
                                    {line.amount !== null && <strong>{money(line.amount)}</strong>}
                                </div>
                                <p className="muted">{translate('my_money', `reward_reason_${line.reason}`)}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {/* Rank royalty, the salary or bonus and the area shares of new orders: decided together on the month-end day. No amount where none is known yet. */}
            {(pending?.month_end ?? []).length > 0 && (
                <div className="pd-card pd-held" data-testid="home-month-end">
                    <div className="pd-heads-top">
                        <h3>{translate('my_money', 'month_end_heading')}</h3>
                    </div>
                    <p className="muted">{translate('my_money', pending?.role === 'sub_dealer' ? 'month_end_intro_sub' : 'month_end_intro')}</p>
                    <ul>
                        {(pending?.month_end ?? []).map((line: Row) => (
                            <li key={line.id}>
                                <div className="pd-heads-top">
                                    <span>{line.order_number ? translate('my_money', 'pending_line', { what: line.what, order: line.order_number }) : line.what}</span>
                                    <strong>{line.amount !== null
                                        ? `${line.estimate ? `${translate('my_money', 'pending_salary_estimate')} ` : ''}${money(line.amount)}`
                                        : translate('my_money', 'month_end_no_amount')}</strong>
                                </div>
                                <p className="muted">{translate('my_money', `pending_reason_${line.reason}`)}</p>
                            </li>
                        ))}
                    </ul>
                    {Number(pending?.more ?? 0) > 0 && mayWithdraw && (
                        <Link className="text-link" href={`${base}/my-money`}>{translate('my_money', 'pending_more', { count: number(Number(pending?.more)) })}</Link>
                    )}
                </div>
            )}
            {/* What the company spent on the partner (tour, event or contest prize): the latest three, never in the balance. */}
            <CompanyBenefitsList benefits={benefits} compact />
            {/* MA-03: commission held for the partner is shown while it is held, with the reason. */}
            {Number(held?.total ?? 0) > 0 && (
                <div className="pd-card pd-held" data-testid="home-commission-held">
                    <div className="pd-heads-top">
                        <h3>{translate('money_core', 'held_heading')}</h3>
                        <strong>{money(held?.total)}</strong>
                    </div>
                    <ul className="pd-head-list">
                        {heldLines.map((line) => (
                            <li key={line.id}>
                                <span>{translate('money_core', 'held_line', { what: line.what, order: line.order_number ?? '—' })} · {translate('money_core', `held_reason_${line.reason}`)}</span>
                                <strong>{money(line.amount)}</strong>
                            </li>
                        ))}
                    </ul>
                    {Number(held?.count ?? 0) > heldLines.length && (
                        <Link className="text-link" href={`${base}/my-money`}>{translate('money_core', 'held_more', { count: number(Number(held?.count) - heldLines.length) })}</Link>
                    )}
                </div>
            )}
            <div className="pd-card pd-heads">
                <div className="pd-heads-top">
                    <h3>{translate('cr_partner', 'heads_title')}</h3>
                    <div className="pd-heads-actions">
                        {/* The most wanted action sits on the home page; the wallet opens its withdrawal dialog (findability #2). */}
                        {mayWithdraw && <Link className="button small dark" href={`${base}/my-money?withdraw=1`} data-testid="home-withdraw">{translate('findability', 'btn_withdraw')}</Link>}
                        <Link className="text-link" href={`${base}/my-money`}>{translate('cr_partner', 'open_wallet')}</Link>
                    </div>
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
                <MoneyColumn totals={home.totals ?? {}} cards={moneyCards} held={home.wallet?.commission_held} pending={home.wallet?.pending} benefits={home.wallet?.benefits} card={home.card ?? {}} />
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
