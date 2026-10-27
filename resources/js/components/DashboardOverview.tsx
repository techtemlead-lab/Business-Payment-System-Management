import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowUpRight, ShoppingBag, Package, Truck, Wallet, Users, ShieldCheck, FileText, Bell, Settings,
    GitBranch, Coins, BadgeCheck, Target, Bike, TrendingUp, Share2, Copy, Check, FileBarChart2,
    Phone, MessageCircle,
} from 'lucide-react';
import { Badge, money, type Row } from './UI';
import { useT } from '../shared/i18n/t';

type ChartSlice = { key: string; label: string; value: string; cents: number; color: string };
type TargetRow = { key: string; label: string; current: string; target: string; remaining: string; percent: number; hint: string };

function PieChart({ slices }: { slices: ChartSlice[] }) {
    const total = slices.reduce((sum, slice) => sum + Math.max(0, slice.cents), 0);
    const translate = useT();
    if (total <= 0) {
        return (
            <div className="home-pie empty" aria-hidden="true">
                <span>{translate('portal', 'no_activity_yet')}</span>
            </div>
        );
    }
    let cursor = 0;
    const stops = slices.map((slice) => {
        const share = (Math.max(0, slice.cents) / total) * 100;
        const start = cursor;
        cursor += share;
        return `${slice.color} ${start}% ${cursor}%`;
    }).join(', ');

    return (
        <div className="home-pie-wrap" data-tour="home-chart">
            <div className="home-pie" style={{ background: `conic-gradient(${stops})` }} aria-hidden="true" />
            <ul className="home-pie-legend">
                {slices.map((slice) => (
                    <li key={slice.key}>
                        <i style={{ background: slice.color }} />
                        <span>{slice.label}</span>
                        <b>{money(slice.value)}</b>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ReferralShare({ referral }: { referral: { code?: string; url?: string; qr_url?: string; hint?: string } | null }) {
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
    const messengerHref = `https://www.facebook.com/dialog/send?link=${encodedUrl}&app_id=0&redirect_uri=${encodedUrl}`;
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
                        <span>{translate('portal', 'refer_code')}</span>
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

function PartnerHome({ home, orders }: { home: Row; orders: React.ReactNode }) {
    const { portalBase: base } = usePage<any>().props;
    const translate = useT();
    const profile = home.profile ?? {};
    const targets: TargetRow[] = home.targets ?? [];
    const chart: ChartSlice[] = home.chart ?? [];

    return (
        <div className="partner-home">
            <section className="partner-home-grid">
                <aside className="partner-home-profile" data-tour="home-profile">
                    <div className="partner-home-avatar" aria-hidden="true">
                        {profile.photo_url
                            ? <img src={profile.photo_url} alt="" />
                            : <span>{profile.initial || '?'}</span>}
                    </div>
                    <h2>{profile.name}</h2>
                    {profile.shop_name && <p className="partner-home-shop">{profile.shop_name}</p>}
                    <small>{profile.dealer_code || '—'} · {String(profile.role || '').replaceAll('_', ' ')}</small>
                    <Badge status={profile.status || 'active'} />
                    <dl>
                        <div><dt>{translate('portal', 'label_mobile')}</dt><dd>{profile.phone || '—'}</dd></div>
                        <div><dt>{translate('portal', 'label_email')}</dt><dd>{profile.email || '—'}</dd></div>
                        <div><dt>{translate('portal', 'label_area_code')}</dt><dd>{profile.area_code || '—'}</dd></div>
                        {(profile.district || profile.division) && (
                            <div><dt>{translate('portal', 'label_location')}</dt><dd>{[profile.district, profile.division].filter(Boolean).join(', ')}</dd></div>
                        )}
                        {profile.refer_code && (
                            <div><dt>{translate('portal', 'refer_code')}</dt><dd>{profile.refer_code}</dd></div>
                        )}
                    </dl>
                    <Link href={`${base}/profile`} className="text-link">{translate('portal', 'full_profile')}</Link>
                    <Link href={`${base}/full-report`} className="text-link" style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                        <FileBarChart2 size={14} /> {translate('portal', 'full_report')}
                    </Link>
                    <a href={`${base}/id-card/pdf`} className="text-link">{translate('portal', 'download_id_card')}</a>
                    <a href={`${base}/join-poster/pdf`} className="text-link">{translate('portal', 'download_join_poster')}</a>
                </aside>

                <section className="partner-home-metrics" data-tour="home-metrics">
                    <div className="partner-home-metrics-head">
                        <div>
                            <h3>{translate('portal', 'today_activity')}</h3>
                            <p>{translate('portal', 'today_activity_hint')}</p>
                        </div>
                        <TrendingUp size={18} />
                    </div>
                    <div className="partner-home-metric-cards">
                        <div>
                            <span>{translate('portal', 'today_purchase')}</span>
                            <strong>{money(home.snapshot?.own_sales_today)}</strong>
                            <small>{translate('portal', 'today_purchase_hint')}</small>
                        </div>
                        <div>
                            <span>{translate('portal', 'team_sales')}</span>
                            <strong>{money(home.snapshot?.team_sales_today)}</strong>
                            <small>{translate('portal', 'team_sales_hint')}</small>
                        </div>
                        <div>
                            <span>{translate('portal', 'commission_month')}</span>
                            <strong>{money(home.snapshot?.commission_month)}</strong>
                            <small>{translate('portal', 'commission_month_hint')}</small>
                        </div>
                    </div>
                    <PieChart slices={chart} />
                </section>

                <section className="partner-home-targets" data-tour="home-targets">
                    <div className="partner-home-metrics-head">
                        <div>
                            <h3>{translate('portal', 'targets')}</h3>
                            <p>{translate('portal', 'targets_hint')}</p>
                        </div>
                        <Target size={18} />
                    </div>
                    <div className="partner-home-target-list">
                        {targets.map((target) => (
                            <article key={target.key}>
                                <div className="partner-home-target-top">
                                    <b>
                                        {target.key === 'bike_incentive' ? <Bike size={14} /> : null}
                                        {target.label}
                                    </b>
                                    <span>{target.percent}%</span>
                                </div>
                                <div className="partner-home-bar" aria-hidden="true">
                                    <i style={{ width: `${Math.min(100, target.percent)}%` }} />
                                </div>
                                <div className="partner-home-target-meta">
                                    <span>{translate('portal', 'target_done')} {target.key === 'rank_reward' && !String(target.current).includes('.') ? target.current : money(target.current)}</span>
                                    <span>{translate('portal', 'target_left')} {target.key === 'rank_reward' && !String(target.remaining).includes('.') ? target.remaining : money(target.remaining)}</span>
                                </div>
                                <small>{target.hint}</small>
                            </article>
                        ))}
                    </div>
                </section>
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
                                className="button small"
                                style={{ background: '#ecfdf5', borderColor: '#a7f3d0', color: '#047857' }}
                            >
                                <MessageCircle size={14} /> {translate('portal', 'whatsapp')}
                            </a>
                        </div>
                    )}
                </section>
            )}
            <ReferralShare referral={home.referral ?? null} />

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'recent_orders')}</h2>
                        <p>{translate('portal', 'recent_orders_hint')}</p>
                    </div>
                    <Link href={`${base}/orders`} className="text-link">{translate('portal', 'view_all')} <ArrowUpRight size={15} /></Link>
                </div>
                {orders}
            </section>
        </div>
    );
}

function AdminHome({ home, data, orders }: { home: Row; data: Row; orders: React.ReactNode }) {
    const { auth, portalBase: base } = usePage<any>().props;
    const translate = useT();
    const u = auth.user;
    const caps: string[] = auth.capabilities;
    const staff = caps.length > 0;
    const commerce = !staff || caps.includes('orders') || caps.includes('finance');
    const profile = home.profile ?? {};
    const snapshot = home.snapshot ?? {};
    const cards = [
        { route: 'catalog', name: translate('portal', 'admin_tile_catalog'), description: translate('portal', 'admin_tile_catalog_hint'), icon: Package, show: true },
        { route: 'orders', name: translate('portal', 'admin_tile_orders'), description: translate('portal', 'admin_tile_orders_hint'), icon: ShoppingBag, show: commerce },
        { route: 'deliveries', name: translate('portal', 'admin_tile_deliveries'), description: translate('portal', 'admin_tile_deliveries_hint'), icon: Truck, show: commerce },
        { route: 'dues', name: translate('portal', 'admin_tile_dues'), description: translate('portal', 'admin_tile_dues_hint'), icon: Wallet, show: commerce },
        { route: 'accounts', name: translate('portal', 'admin_tile_accounts'), description: translate('portal', 'admin_tile_accounts_hint'), icon: Users, show: caps.includes('accounts') },
        { route: 'partnerships', name: translate('portal', 'admin_tile_partnerships'), description: translate('portal', 'admin_tile_partnerships_hint'), icon: Users, show: caps.includes('accounts') },
        { route: 'inventory', name: translate('portal', 'admin_tile_inventory'), description: translate('portal', 'admin_tile_inventory_hint'), icon: Package, show: caps.includes('inventory') },
        { route: 'payments', name: translate('portal', 'admin_tile_payments'), description: translate('portal', 'admin_tile_payments_hint'), icon: Coins, show: caps.includes('finance') },
        { route: 'policies', name: translate('portal', 'admin_tile_policies'), description: translate('portal', 'admin_tile_policies_hint'), icon: ShieldCheck, show: caps.includes('finance') },
        { route: 'ledger', name: translate('portal', 'admin_tile_ledger'), description: translate('portal', 'admin_tile_ledger_hint'), icon: FileText, show: caps.includes('finance') },
        { route: 'reports', name: translate('portal', 'admin_tile_reports'), description: translate('portal', 'admin_tile_reports_hint'), icon: FileText, show: caps.includes('reports') },
        { route: 'content', name: translate('portal', 'admin_tile_content'), description: translate('portal', 'admin_tile_content_hint'), icon: FileText, show: caps.includes('content') },
        { route: 'enquiries', name: translate('portal', 'admin_tile_enquiries'), description: translate('portal', 'admin_tile_enquiries_hint'), icon: Users, show: caps.includes('content') || caps.includes('accounts') },
        { route: 'audit', name: translate('portal', 'admin_tile_audit'), description: translate('portal', 'admin_tile_audit_hint'), icon: ShieldCheck, show: caps.includes('audit') },
        { route: 'profile', name: translate('portal', 'admin_tile_profile'), description: translate('portal', 'admin_tile_profile_hint'), icon: Users, show: true },
        { route: 'security', name: translate('portal', 'admin_tile_security'), description: translate('portal', 'admin_tile_security_hint'), icon: Settings, show: true },
        { route: 'notifications', name: translate('portal', 'admin_tile_notifications'), description: translate('portal', 'admin_tile_notifications_hint'), icon: Bell, show: true },
    ].filter((x) => x.show);

    return (
        <div className="admin-home">
            <section className="admin-home-hero" data-tour="home-metrics">
                <aside className="partner-home-profile compact">
                    <div className="partner-home-avatar" aria-hidden="true"><span>{profile.initial || u.name?.[0]}</span></div>
                    <h2>{profile.name || u.name}</h2>
                    <small>{labelRole(profile.role || u.role)}</small>
                    <Badge status={profile.status || u.status} />
                    <dl>
                        <div><dt>{translate('portal', 'label_mobile')}</dt><dd>{profile.phone || '—'}</dd></div>
                        <div><dt>{translate('portal', 'label_email')}</dt><dd>{profile.email || u.email || '—'}</dd></div>
                    </dl>
                </aside>
                <div className="admin-home-stats">
                    <div><span>{translate('portal', 'admin_stat_orders_today')}</span><strong>{snapshot.orders_today ?? 0}</strong></div>
                    <div><span>{translate('portal', 'admin_stat_awaiting_review')}</span><strong>{snapshot.pending_review ?? 0}</strong></div>
                    <div><span>{translate('portal', 'admin_stat_partner_accounts')}</span><strong>{snapshot.partner_accounts ?? 0}</strong></div>
                    <div><span>{translate('portal', 'admin_stat_approved_purchases')}</span><strong>{money(snapshot.purchases)}</strong></div>
                    <div><span>{translate('portal', 'admin_stat_outstanding_dues')}</span><strong>{money(snapshot.dues)}</strong></div>
                </div>
            </section>

            <section className="account-actions dense-actions" data-tour="admin-modules" aria-label={translate('portal', 'admin_modules')}>
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
                <section className="panel table-panel dense-panel">
                    <div className="panel-heading">
                        <div>
                            <h2>{translate('portal', 'recent_orders')}</h2>
                            <p>{translate('portal', 'recent_orders_hint')}</p>
                        </div>
                        <Link href={`${base}/orders`} className="text-link">{translate('portal', 'view_all')} <ArrowUpRight size={15} /></Link>
                    </div>
                    {orders}
                </section>
            )}
            {data.lowStock?.length > 0 && (
                <section className="notice">
                    <h2>{translate('portal', 'low_stock_alerts')}</h2>
                    <p>{data.lowStock.map((p: Row) => `${p.name} (${p.stock} left)`).join(' · ')}</p>
                    <Link href={`${base}/inventory`} className="text-link">{translate('portal', 'review_inventory')}</Link>
                </section>
            )}
        </div>
    );
}

function labelRole(role: string) {
    return String(role || '').replaceAll('_', ' ');
}

export default function DashboardOverview({ data, orders }: { data: Row; orders: React.ReactNode }) {
    const { auth, portalBase: base } = usePage<any>().props;
    const translate = useT();
    const u = auth.user;
    const caps: string[] = auth.capabilities;
    const staff = caps.length > 0;

    if (data.partner_home && (u.role === 'dealer' || u.role === 'sub_dealer')) {
        return <PartnerHome home={data.partner_home} orders={orders} />;
    }
    if (data.admin_home && staff) {
        return <AdminHome home={data.admin_home} data={data} orders={orders} />;
    }

    // Fallback for edge roles
    const commerce = !staff || caps.includes('orders') || caps.includes('finance');
    const cards = [
        { route: 'catalog', name: translate('portal','dash_card_catalog'), description: translate('portal','dash_card_catalog_desc'), icon: Package, show: true },
        { route: 'orders', name: translate('portal','dash_card_orders'), description: translate('portal','dash_card_orders_desc'), icon: ShoppingBag, show: commerce },
        { route: 'earnings', name: translate('portal','nav_my_earnings'), description: translate('portal','dash_card_earnings_desc'), icon: Coins, show: !staff },
        { route: 'network', name: translate('portal','dash_card_network'), description: translate('portal','dash_card_network_desc'), icon: GitBranch, show: u.role === 'dealer' },
        { route: 'ranks', name: translate('portal','dash_card_ranks'), description: translate('portal','dash_card_ranks_desc'), icon: BadgeCheck, show: u.role === 'dealer' },
        { route: 'profile', name: translate('portal','dash_card_profile'), description: translate('portal','dash_card_profile_desc'), icon: Users, show: true },
        { route: 'security', name: translate('portal','dash_card_security'), description: translate('portal','dash_card_security_desc'), icon: Settings, show: true },
    ].filter((x) => x.show);

    return (
        <>
            <section className="account-profile-card">
                <div className="account-profile-info">
                    <div className="account-avatar" aria-hidden="true">{u.name[0]}</div>
                    <div>
                        <p>{translate('portal','dash_hello')}</p>
                        <h2>{u.name}</h2>
                        <small>{u.dealer_code ?? translate('portal','company_staff')} · {labelRole(u.role)}</small>
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
                <section className="panel table-panel dense-panel">
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
