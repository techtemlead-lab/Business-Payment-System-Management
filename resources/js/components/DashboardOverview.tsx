import { Link, usePage } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import {
    ArrowUpRight, ChevronRight, ShoppingBag, Package, Truck, Wallet, Users, Settings,
    GitBranch, Coins, BadgeCheck, Target, Bike, Share2, Copy, Check, Plane,
    Phone, MessageCircle, ListChecks,
} from 'lucide-react';
import IncomeCards from './mymoney/IncomeCards';
import type { IncomeCard } from './mymoney/types';
import { PartnerProgressButton } from './PartnerProgress';
import PartnerAlertsCard from './dealertools/PartnerAlertsCard';
import NextStepsCard from './dealertools/NextStepsCard';
import { ProfilePhoto } from './ProfilePhoto';
import Figures, { type FigureItem } from './office/Figures';
import OfficePage from './office/OfficePage';
import { Badge, Empty, date, humanize, money, type Row, number } from './UI';
import { businessTodayIso, localizeDigits } from '../business-date';
import { useT } from '../shared/i18n/t';

/** A progress bar row, or (kind "amount") a plain amount with no target, such as a sub-dealer's salary or tour fund. */
type TargetRow = {
    key: string;
    kind?: 'progress' | 'amount';
    label: string;
    hint: string;
    value?: string;
    current?: string;
    target?: string;
    remaining?: string;
    percent?: number;
};


function ReferralShare({ referral, role }: { referral: { code?: string; url?: string; qr_url?: string; hint?: string } | null; role?: string }) {
    const translate = useT();
    const [copied, setCopied] = useState(false);
    if (!referral?.url || !referral?.code) {
        return null;
    }

    const shareText = `${translate('portal', 'share_join_text', { code: String(referral.code) })} ${referral.url}`;
    const encodedUrl = encodeURIComponent(referral.url!);
    const encodedText = encodeURIComponent(shareText);
    const whatsappHref = `https://wa.me/?text=${encodedText}`;
    const facebookHref = `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
    // Messenger has no web share URL without a registered app id; the app link works on phones.
    const messengerHref = `fb-messenger://share/?link=${encodedUrl}`;
    const telegramHref = `https://t.me/share/url?url=${encodedUrl}&text=${encodeURIComponent(translate('portal', 'share_join_text', { code: String(referral.code) }))}`;
    const linkedinHref = `https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}`;
    const xHref = `https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodeURIComponent(translate('portal', 'share_join_text', { code: String(referral.code) }))}`;

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(referral.url!);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    const shareNative = async () => {
        if (navigator.share) {
            try {
                await navigator.share({
                    title: translate('portal', 'share_join_title'),
                    text: translate('portal', 'share_join_text', { code: String(referral.code) }),
                    url: referral.url,
                });
                return;
            } catch {
                // Fall through to copy when the share sheet is dismissed or unavailable.
            }
        }
        await copyLink();
    };

    return (
        <section className="partner-home-referral" data-tour="home-referral" aria-label={translate('portal', 'invite_partners')}>
            <div className="partner-home-metrics-head">
                <div>
                    <h3>{translate('portal', 'invite_partners')}</h3>
                    <p>{referral.hint || translate('portal', 'invite_partners_hint')}</p>
                </div>
                <Share2 size={18} />
            </div>
            <div className="partner-home-referral-body">
                <div className="partner-home-referral-poster">
                    {referral.qr_url
                        ? <img src={referral.qr_url} alt="" width={96} height={96} className="partner-home-referral-qr" />
                        : null}
                    <small>{translate('portal', 'brand_company_bar')}</small>
                    <b>{referral.code}</b>
                    <span>{translate('portal', 'open_join_page')}</span>
                </div>
                <div className="partner-home-referral-meta">
                    <div className="partner-home-referral-code">
                        <span>{translate('portal', role === 'sub_dealer' ? 'acct_my_sub_dealer_code' : 'acct_my_dealer_code')}</span>
                        <strong>{referral.code}</strong>
                    </div>
                    <code className="partner-home-referral-url">{referral.url}</code>
                    <div className="partner-home-referral-actions">
                        <button type="button" className="button small" onClick={copyLink}>
                            {copied ? <Check size={14} /> : <Copy size={14} />}
                            {copied ? translate('portal', 'copied') : translate('portal', 'copy_link')}
                        </button>
                        <button type="button" className="button small dark" onClick={shareNative}>
                            <Share2 size={14} /> {translate('portal', 'share')}
                        </button>
                        <a className="button small" href={whatsappHref} target="_blank" rel="noreferrer">{translate('portal', 'share_whatsapp')}</a>
                        <a className="button small" href={facebookHref} target="_blank" rel="noreferrer">{translate('portal', 'share_facebook')}</a>
                        <a className="button small" href={messengerHref} target="_blank" rel="noreferrer">{translate('portal', 'share_messenger')}</a>
                        <a className="button small" href={telegramHref} target="_blank" rel="noreferrer">{translate('portal', 'share_telegram')}</a>
                        <a className="button small" href={linkedinHref} target="_blank" rel="noreferrer">{translate('portal', 'share_linkedin')}</a>
                        <a className="button small" href={xHref} target="_blank" rel="noreferrer">{translate('portal', 'share_x')}</a>
                        <a className="text-link" href={referral.url} target="_blank" rel="noreferrer">{translate('portal', 'open_join_page')}</a>
                    </div>
                </div>
            </div>
        </section>
    );
}

/** Wallet balance hidden until tapped, then hidden again after a few seconds — as in bKash and Nagad. */
function BalancePill({ amount }: { amount: unknown }) {
    const translate = useT();
    const [shown, setShown] = useState(false);
    useEffect(() => {
        if (!shown) {
            return;
        }
        const timer = window.setTimeout(() => setShown(false), 6000);

        return () => window.clearTimeout(timer);
    }, [shown]);

    return (
        <button
            type="button"
            className={`ph-balance${shown ? ' is-shown' : ''}`}
            onClick={() => setShown((value) => !value)}
            aria-pressed={shown}
        >
            <span className="ph-balance-coin" aria-hidden="true">৳</span>
            <span className="ph-balance-text" aria-live="polite">
                {shown ? money(amount) : translate('portal', 'ph_tap_for_balance')}
            </span>
        </button>
    );
}

type QuickAction = { section: string; label: string; icon: React.ComponentType<{ size?: number }>; tone: string; wordGroup?: string };

const QUICK_ACTIONS: QuickAction[] = [
    { section: 'purchase', label: 'ph_qa_new_order', icon: ShoppingBag, tone: 'green' },
    { section: 'orders', label: 'ph_qa_my_orders', icon: ListChecks, tone: 'blue' },
    { section: 'deliveries', label: 'ph_qa_deliveries', icon: Truck, tone: 'amber' },
    { section: 'my-money', label: 'title', icon: Wallet, tone: 'rose', wordGroup: 'my_money' },
    { section: 'network', label: 'ph_qa_team', icon: Users, tone: 'blue' },
    { section: 'ranks', label: 'ph_qa_ranks', icon: Target, tone: 'rose' },
    { section: 'catalog', label: 'ph_qa_products', icon: Package, tone: 'green' },
    { section: 'help', label: 'ph_qa_help', icon: MessageCircle, tone: 'amber' },
];

function QuickActions({ base }: { base: string }) {
    const translate = useT();
    const sections: string[] = usePage<any>().props.portalCatalog?.sections ?? [];
    const actions = QUICK_ACTIONS.filter((action) => sections.includes(action.section)).slice(0, 8);
    if (actions.length === 0) {
        return null;
    }

    return (
        <nav className="ph-actions" aria-label={translate('portal', 'ph_quick_actions')}>
            {actions.map(({ section, label, icon: Icon, tone, wordGroup }) => (
                <Link key={section} href={`${base}/${section}`} className="ph-action">
                    <span className={`ph-action-icon tone-${tone}`} aria-hidden="true"><Icon size={22} /></span>
                    <span className="ph-action-label">{translate(wordGroup ?? 'portal', label)}</span>
                </Link>
            ))}
        </nav>
    );
}

function PartnerHome({ home, orders, tools, moneyCards }: { home: Row; orders: React.ReactNode; tools?: Row; moneyCards?: IncomeCard[] }) {
    const { portalBase: base } = usePage<any>().props;
    const translate = useT();
    const profile = home.profile ?? {};
    const targets: TargetRow[] = home.targets ?? [];
    const snapshot = home.snapshot ?? {};
    const wallet = home.wallet ?? {};
    const waitingAmounts: Row[] = wallet.commission_waiting?.amounts ?? [];
    const location = [profile.district, profile.division].filter(Boolean).join(', ');

    return (
        <div className="partner-home ph">
            <section className="ph-hero" data-tour="home-profile">
                <div className="ph-hero-top">
                    <ProfilePhoto src={profile.photo_url} initial={profile.initial || '?'} />
                    <div className="ph-hero-who">
                        <p>{translate('portal', 'ph_greeting')}</p>
                        <h2>{profile.name}</h2>
                        <span className="ph-chip">
                            {profile.dealer_code || '—'}
                            <i aria-hidden="true" />
                            {labelRole(String(profile.role || ''), translate)}
                        </span>
                    </div>
                    <Badge status={profile.status || 'active'} />
                </div>
                <BalancePill amount={wallet.available} />
                <dl className="ph-hero-stats">
                    <div>
                        <dt>{translate('portal', 'ph_commission_month')}</dt>
                        <dd>{money(snapshot.commission_month)}</dd>
                    </div>
                    <div>
                        <dt>{translate('portal', 'pending_withdrawal')}</dt>
                        <dd>{money(wallet.pending_withdrawal)}</dd>
                    </div>
                    <div className="ph-hero-waiting" data-testid="home-commission-waiting">
                        <dt>{translate('portal', 'ph_commission_waiting')}</dt>
                        <dd>{money(wallet.commission_waiting?.total)}</dd>
                        {waitingAmounts.slice(0, 3).map((item) => (
                            <dd className="ph-hero-note" key={item.ready_on ?? 'no-date'}>
                                {money(item.amount)} · {item.ready_on ? translate('portal', 'commission_waiting_until', { date: date(item.ready_on) }) : translate('portal', 'commission_waiting_no_date')}
                            </dd>
                        ))}
                        {waitingAmounts.length > 3 && (
                            <dd className="ph-hero-note"><Link href={`${base}/my-money`}>{translate('portal', 'commission_waiting_more', { count: number(waitingAmounts.length - 3) })}</Link></dd>
                        )}
                    </div>
                </dl>
            </section>

            {home.is_birthday === true && (
                <section className="ph-birthday" role="status" data-tour="home-birthday">
                    <span className="ph-birthday-icon" aria-hidden="true">🎂</span>
                    <div>
                        <h3>{translate('portal', 'ph_birthday_title')}</h3>
                        <p>{translate('portal', 'ph_birthday_body', { name: String(profile.name ?? '') })}</p>
                    </div>
                </section>
            )}

            <QuickActions base={base} />

            <IncomeCards cards={moneyCards} hrefFor={(type) => `${base}/my-money?type=${encodeURIComponent(type)}`} />

            <section className="ph-today" data-tour="home-metrics" aria-labelledby="ph-today-title">
                <h3 id="ph-today-title">{translate('portal', 'today_activity')}</h3>
                <div className="ph-today-grid">
                    <div>
                        <span>{translate('portal', 'today_purchase')}</span>
                        <strong>{money(snapshot.own_sales_today)}</strong>
                    </div>
                    <div>
                        <span>{translate('portal', 'today_commission')}</span>
                        <strong>{money(snapshot.commission_today)}</strong>
                    </div>
                    <div>
                        <span>{translate('portal', 'team_sales')}</span>
                        <strong>{money(snapshot.team_sales_today)}</strong>
                    </div>
                </div>
            </section>

            <PartnerAlertsCard alerts={tools?.alerts ?? []} inactivePartners={tools?.inactive_partners ?? []} />
            <NextStepsCard steps={tools?.next_steps ?? []} />

            {targets.length > 0 && (
                <section className="ph-card ph-targets" data-tour="home-targets">
                    <div className="ph-card-head ph-targets-head">
                        <div>
                            <h3>{translate('portal', 'targets')}</h3>
                            <p>{translate('portal', profile.role === 'sub_dealer' ? 'targets_hint_subdealer' : 'targets_hint')}</p>
                        </div>
                        <PartnerProgressButton />
                    </div>
                    <div className="partner-home-target-list">
                        {targets.map((target) => target.kind === 'amount' ? (
                            <article key={target.key} className="partner-home-target-amount">
                                <div className="partner-home-target-top">
                                    <b>
                                        {target.key === 'tour_fund' ? <Plane size={14} /> : <Wallet size={14} />}
                                        {target.label}
                                    </b>
                                    <span>{money(target.value)}</span>
                                </div>
                                <small>{localizeDigits(target.hint)}</small>
                            </article>
                        ) : (
                            <article key={target.key}>
                                <div className="partner-home-target-top">
                                    <b>
                                        {target.key === 'bike_incentive' ? <Bike size={14} /> : null}
                                        {target.label}
                                    </b>
                                    <span>{number(target.percent)}%</span>
                                </div>
                                <div
                                    className="partner-home-bar"
                                    role="progressbar"
                                    aria-label={target.label}
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-valuenow={Math.min(100, Number(target.percent) || 0)}
                                >
                                    <i style={{ width: `${Math.min(100, Number(target.percent) || 0)}%` }} />
                                </div>
                                <div className="partner-home-target-meta">
                                    <span>{translate('portal', 'target_done')} {target.key === 'rank_reward' && !String(target.current).includes('.') ? target.current : money(target.current)}</span>
                                    <span>{translate('portal', 'target_left')} {target.key === 'rank_reward' && !String(target.remaining).includes('.') ? target.remaining : money(target.remaining)}</span>
                                </div>
                                <small>{localizeDigits(target.hint)}</small>
                            </article>
                        ))}
                    </div>
                </section>
            )}

            <section className="panel table-panel dense-panel" data-tour="recent-orders">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'recent_orders')}</h2>
                        <p>{translate('portal', 'recent_orders_hint')}</p>
                    </div>
                    <Link href={`${base}/orders`} className="text-link">{translate('portal', 'view_all')} <ArrowUpRight size={15} /></Link>
                </div>
                {orders}
            </section>

            {home.sponsor && (
                <section className="panel sponsor-card" data-tour="sponsor-contact">
                    <div className="sponsor-info">
                        <span className="eyebrow">{translate('portal', 'assigned_sponsor_dealer')}</span>
                        <h4>{home.sponsor.name}{home.sponsor.dealer_code ? ` (${home.sponsor.dealer_code})` : ''}</h4>
                        <p>{home.sponsor.phone || translate('portal', 'no_phone_provided')}</p>
                    </div>
                    {home.sponsor.phone && (
                        <div className="sponsor-actions">
                            <a href={`tel:${home.sponsor.phone}`} className="button small">
                                <Phone size={14} /> {translate('portal', 'call_dealer')}
                            </a>
                            <a
                                href={`https://wa.me/${String(home.sponsor.phone).replace(/[^0-9]/g, '').replace(/^01/, '8801')}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="button small ph-whatsapp"
                            >
                                <MessageCircle size={14} /> {translate('portal', 'whatsapp')}
                            </a>
                        </div>
                    )}
                </section>
            )}
            <ReferralShare referral={home.referral ?? null} role={profile.role} />

            <section className="ph-card ph-profile">
                <div className="ph-card-head">
                    <h3>{translate('portal', 'ph_my_account')}</h3>
                    {profile.shop_name && <p>{profile.shop_name}</p>}
                </div>
                <dl>
                    <div><dt>{translate('portal', 'label_mobile')}</dt><dd>{profile.phone || '—'}</dd></div>
                    <div><dt>{translate('portal', 'label_email')}</dt><dd>{profile.email || '—'}</dd></div>
                    <div><dt>{translate('portal', 'label_area_code')}</dt><dd>{profile.area_code || '—'}</dd></div>
                    {location && <div><dt>{translate('portal', 'label_location')}</dt><dd>{location}</dd></div>}
                </dl>
                <div className="ph-profile-links">
                    <Link href={`${base}/profile`} className="button small">{translate('portal', 'full_profile')}</Link>
                    <a href={`${base}/id-card/pdf`} className="button small">{translate('portal', 'download_id_card')}</a>
                    {profile.refer_code && <a href={`${base}/join-poster/pdf`} className="button small">{translate('portal', 'download_join_poster')}</a>}
                </div>
            </section>
        </div>
    );
}

type WorkItem = { key: string; count: number; section: string; query?: Record<string, string> };
type TrendPoint = { date: string; orders: number; value: string };

/** A business day (YYYY-MM-DD) in the page's language, whatever clock the browser is on. */
function dayInWords(iso: string, format: Intl.DateTimeFormatOptions): string {
    const [year, month, day] = iso.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(document.documentElement.lang === 'bn' ? 'bn-BD' : 'en-GB', format);
}

function WorkQueue({ items, base }: { items: WorkItem[]; base: string }) {
    const translate = useT();

    return (
        <section className="panel admin-work-queue" data-tour="admin-work-queue" aria-label={translate('portal', 'dash_work_title')}>
            <div className="panel-heading">
                <h2>{translate('portal', 'dash_work_title')}</h2>
            </div>
            <ul>
                {items.map((item) => {
                    const query = item.query ? `?${new URLSearchParams(item.query).toString()}` : '';
                    return (
                        <li key={item.key} className={item.count > 0 ? 'has-work' : 'is-clear'}>
                            <Link href={`${base}/${item.section}${query}`}>
                                <strong>{number(item.count)}</strong>
                                <span>{translate('portal', `dash_work_${item.key}`)}</span>
                                <ChevronRight size={16} aria-hidden="true" />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** Goods delivered over the last two weeks, with this month's delivered value and orders in one line above the bars. */
function SalesTrend({ points, month }: { points: TrendPoint[]; month: Row | null }) {
    const translate = useT();
    const values = points.map((point) => Number(point.value) || 0);
    const peak = Math.max(...values, 1);
    const width = 560;
    const height = 140;
    const gap = 6;
    const barWidth = (width - gap * (points.length - 1)) / points.length;
    const dayLabel = (iso: string) => dayInWords(iso, { day: 'numeric', month: 'short' });

    return (
        <section className="panel admin-sales-trend" data-tour="admin-sales-trend">
            <div className="panel-heading">
                <h2>{translate('office_home', 'trend_title')}</h2>
            </div>
            {month && <p className="office-home-month">{translate('office_home', 'month_line', { value: money(month.sales), orders: number(month.orders) })}</p>}
            <svg className="admin-trend-chart" viewBox={`0 0 ${width} ${height + 22}`} role="img" aria-label={translate('office_home', 'trend_title')}>
                {points.map((point, index) => {
                    const value = values[index];
                    const barHeight = value > 0 ? Math.max(3, (value / peak) * height) : 2;
                    const x = index * (barWidth + gap);
                    return (
                        <g key={point.date}>
                            <rect x={x} y={height - barHeight} width={barWidth} height={barHeight} rx={4} className={value > 0 ? 'bar' : 'bar empty'}>
                                <title>{`${dayLabel(point.date)}: ${money(point.value)} · ${translate('portal', 'dash_trend_orders', { count: String(point.orders) })}`}</title>
                            </rect>
                            {(points.length - 1 - index) % 2 === 0 && (
                                <text x={x + barWidth / 2} y={height + 16} textAnchor="middle">{dayLabel(point.date)}</text>
                            )}
                        </g>
                    );
                })}
            </svg>
        </section>
    );
}

/** The day's figures, each opening the list behind it. Staff get only the ones their work allows; finance staff's four fill the row. */
function officeFigures(home: Row, base: string, caps: string[], translate: ReturnType<typeof useT>): FigureItem[] {
    const commerce = caps.includes('orders') || caps.includes('finance');
    const snapshot = home.snapshot ?? {};
    const month: Row | null = home.month ?? null;
    const queue: WorkItem[] = home.work_queue ?? [];
    const paymentsWaiting = queue.find((item) => item.key === 'payments_to_check');
    const withdrawalsWaiting = queue.find((item) => item.key === 'withdrawals_to_review');

    const figures: FigureItem[] = [];
    if (caps.includes('finance') && month) {
        figures.push({ key: 'delivered_today', label: translate('office_home', 'delivered_today'), value: String(month.delivered_today ?? '0.00'), href: `${base}/treasury/orders` });
    }
    if (commerce) {
        figures.push({ key: 'orders', label: translate('portal', 'admin_stat_orders_today'), value: number(snapshot.orders_today ?? 0), text: true, href: `${base}/orders?period=today` });
    }
    if (paymentsWaiting) {
        figures.push({ key: 'payments', label: translate('office_home', 'payments_waiting'), value: number(paymentsWaiting.count), text: true, href: `${base}/payments?status=pending` });
    }
    if (withdrawalsWaiting) {
        figures.push({ key: 'withdrawals', label: translate('office_home', 'withdrawals_waiting'), value: number(withdrawalsWaiting.count), text: true, href: `${base}/payouts-hub?status=requested` });
    }
    if (commerce) {
        figures.push({ key: 'purchases', label: translate('portal', 'admin_stat_approved_purchases'), value: String(snapshot.purchases ?? '0.00'), href: `${base}/orders?status=delivered` });
    }
    if (caps.includes('accounts')) {
        figures.push({ key: 'partners', label: translate('portal', 'admin_stat_partner_accounts'), value: number(snapshot.partner_accounts ?? 0), text: true, href: `${base}/accounts` });
    }

    return figures;
}

function AdminHome({ home }: { home: Row }) {
    const { auth, portalBase: base } = usePage<any>().props;
    const translate = useT();
    const figures = officeFigures(home, base, auth.capabilities ?? [], translate);
    const work: WorkItem[] = home.work_queue ?? [];
    const trend: TrendPoint[] = home.trend ?? [];
    const today = dayInWords(businessTodayIso(), { weekday: 'long', day: 'numeric', month: 'long' });

    return (
        <OfficePage title={translate('portal', 'overview')} action={<span className="office-home-date">{today}</span>}>
            <div className="office-home">
                {/* The tour step talks about the numbers, so it is skipped for staff who get none here. */}
                {figures.length > 0 && <div data-tour="home-metrics"><Figures items={figures} /></div>}
                {(work.length > 0 || trend.length > 0) && (
                    <section className="admin-home-insights">
                        {work.length > 0 && <WorkQueue items={work} base={base} />}
                        {trend.length > 0 && <SalesTrend points={trend} month={home.month ?? null} />}
                    </section>
                )}
                {figures.length === 0 && work.length === 0 && trend.length === 0 && <Empty line={translate('office_home', 'nothing_waiting')} />}
            </div>
        </OfficePage>
    );
}

function labelRole(role: string, translate?: (group: string, key: string) => string) {
    const fallback = humanize(String(role || ''));
    if (!translate || !role) {
        return fallback;
    }
    const key = `staff_role_${role}`;
    const label = translate('portal', key);

    return label === key ? fallback : label;
}

export default function DashboardOverview({ data, orders }: { data: Row; orders: React.ReactNode }) {
    const { auth, portalBase: base } = usePage<any>().props;
    const translate = useT();
    const u = auth.user;
    const caps: string[] = auth.capabilities;
    const staff = caps.length > 0;

    if (data.partner_home && (u.role === 'dealer' || u.role === 'sub_dealer')) {
        return <PartnerHome home={data.partner_home} orders={orders} tools={data.partner_tools} moneyCards={data.money_cards} />;
    }
    if (data.admin_home && staff) {
        return <AdminHome home={data.admin_home} />;
    }

    // Fallback for edge roles
    const commerce = !staff || caps.includes('orders') || caps.includes('finance');
    const cards = [
        { route: 'catalog', name: translate('portal','dash_card_catalog'), description: translate('portal','dash_card_catalog_desc'), icon: Package, show: true },
        { route: 'orders', name: translate('portal','dash_card_orders'), description: translate('portal','dash_card_orders_desc'), icon: ShoppingBag, show: commerce },
        { route: 'my-money', name: translate('portal','nav_my_earnings'), description: translate('portal','dash_card_earnings_desc'), icon: Coins, show: !staff },
        { route: 'network', name: translate('portal','dash_card_network'), description: translate('portal','dash_card_network_desc'), icon: GitBranch, show: u.role === 'dealer' },
        { route: 'ranks', name: translate('portal','dash_card_ranks'), description: translate('portal','dash_card_ranks_desc'), icon: BadgeCheck, show: u.role === 'dealer' },
        { route: 'profile', name: translate('portal','dash_card_profile'), description: translate('portal','dash_card_profile_desc'), icon: Users, show: true },
        { route: 'security', name: translate('portal','dash_card_security'), description: translate('portal','dash_card_security_desc'), icon: Settings, show: true },
    ].filter((x) => x.show);

    return (
        <>
            <section className="account-profile-card">
                <div className="account-profile-info">
                    <ProfilePhoto src={u.photo_url} initial={u.name?.[0] || '?'} variant="account" upload={staff} />
                    <div>
                        <p>{translate('portal','dash_hello')}</p>
                        <h2>{u.name}</h2>
                        <small>{u.dealer_code ?? translate('portal','company_staff')} · {labelRole(u.role, translate)}</small>
                        <Badge status={u.status} />
                    </div>
                </div>
            </section>
            <section className="account-actions dense-actions" aria-label={translate('portal','dash_account_actions')}>
                {cards.map((card) => {
                    const Icon = card.icon;
                    return (
                        <Link href={`${base}/${card.route}`} className="account-action-card" key={card.route}>
                            <div className="account-action-icon"><Icon size={20} /></div>
                            <div>
                                <h2>{card.name}</h2>
                                <p>{card.description}</p>
                            </div>
                            <ArrowUpRight size={14} />
                        </Link>
                    );
                })}
            </section>
            {commerce && (
                <section className="panel table-panel dense-panel" data-tour="recent-orders">
                    <div className="panel-heading">
                        <div><h2>{translate('portal', 'recent_orders')}</h2></div>
                        <Link href={`${base}/orders`} className="text-link">{translate('portal', 'view_all')} <ArrowUpRight size={15} /></Link>
                    </div>
                    {orders}
                </section>
            )}
        </>
    );
}
