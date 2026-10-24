import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowUpRight, ShoppingBag, Package, Truck, Wallet, Users, ShieldCheck, FileText, Bell, Settings,
    GitBranch, Coins, BadgeCheck, Target, Bike, TrendingUp, Share2, Copy, Check, FileBarChart2,
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
        <div className="home-pie-wrap">
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

function ReferralShare({ referral }: { referral: { code?: string; url?: string; hint?: string } | null }) {
    const translate = useT();
    const [copied, setCopied] = useState(false);
    if (!referral?.url || !referral?.code) {
        return null;
    }

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
        <section className="partner-home-referral" aria-label={translate('portal', 'invite_partners')}>
            <div className="partner-home-metrics-head">
                <div>
                    <h3>{translate('portal', 'invite_partners')}</h3>
                    <p>{referral.hint || translate('portal', 'invite_partners_hint')}</p>
                </div>
                <Share2 size={18} />
            </div>
            <div className="partner-home-referral-body">
                <div className="partner-home-referral-poster" aria-hidden="true">
                    <small>Madina Natural Health Care</small>
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
                <aside className="partner-home-profile">
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
                </aside>

                <section className="partner-home-metrics">
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

                <section className="partner-home-targets">
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

            <ReferralShare referral={home.referral ?? null} />

            <section className="panel table-panel dense-panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'recent_orders')}</h2>
                        <p>{translate('portal', 'recent_orders_hint')}</p>
                    </div>
                    <Link href={`${base}/orders`} className="text-link">View all <ArrowUpRight size={15} /></Link>
                </div>
                {orders}
            </section>
        </div>
    );
}

function AdminHome({ home, data, orders }: { home: Row; data: Row; orders: React.ReactNode }) {
    const { auth, portalBase: base } = usePage<any>().props;
    const u = auth.user;
    const caps: string[] = auth.capabilities;
    const staff = caps.length > 0;
    const commerce = !staff || caps.includes('orders') || caps.includes('finance');
    const profile = home.profile ?? {};
    const snapshot = home.snapshot ?? {};
    const cards = [
        { route: 'catalog', name: 'Product collection', description: 'Products, prices and available stock', icon: Package, show: true },
        { route: 'orders', name: 'Orders', description: 'Purchase requests and order history', icon: ShoppingBag, show: commerce },
        { route: 'deliveries', name: 'Pending supply & deliveries', description: 'Remaining quantities and courier updates', icon: Truck, show: commerce },
        { route: 'dues', name: 'Outstanding dues', description: 'Unpaid balances and verified payments', icon: Wallet, show: commerce },
        { route: 'accounts', name: 'Partner accounts', description: 'Dealers, sub-dealers and staff access', icon: Users, show: caps.includes('accounts') },
        { route: 'partnerships', name: 'Partnership applications', description: 'Dealership applications awaiting review', icon: Users, show: caps.includes('accounts') },
        { route: 'inventory', name: 'Inventory', description: 'Stock receipts, issues and adjustments', icon: Package, show: caps.includes('inventory') },
        { route: 'payments', name: 'Payment review', description: 'Verify manual payment references', icon: Coins, show: caps.includes('finance') },
        { route: 'policies', name: 'Commission policies', description: 'Inactive drafts and policy status', icon: ShieldCheck, show: caps.includes('finance') },
        { route: 'ledger', name: 'Financial ledger', description: 'Posted financial history', icon: FileText, show: caps.includes('finance') },
        { route: 'reports', name: 'Reports', description: 'Order reports and permitted exports', icon: FileText, show: caps.includes('reports') },
        { route: 'content', name: 'Website content', description: 'Published company pages and notices', icon: FileText, show: caps.includes('content') },
        { route: 'enquiries', name: 'Contact enquiries', description: 'Public contact-form messages', icon: Users, show: caps.includes('content') },
        { route: 'audit', name: 'Activity log', description: 'Staff actions and recorded changes', icon: ShieldCheck, show: caps.includes('audit') },
        { route: 'profile', name: 'My profile', description: 'Company-maintained account information', icon: Users, show: true },
        { route: 'security', name: 'Account security', description: 'Change your account password', icon: Settings, show: true },
        { route: 'notifications', name: 'Notifications', description: 'Stock, order and account updates', icon: Bell, show: true },
    ].filter((x) => x.show);

    return (
        <div className="admin-home">
            <section className="admin-home-hero">
                <aside className="partner-home-profile compact">
                    <div className="partner-home-avatar" aria-hidden="true"><span>{profile.initial || u.name?.[0]}</span></div>
                    <h2>{profile.name || u.name}</h2>
                    <small>{labelRole(profile.role || u.role)}</small>
                    <Badge status={profile.status || u.status} />
                    <dl>
                        <div><dt>Mobile</dt><dd>{profile.phone || '—'}</dd></div>
                        <div><dt>Email</dt><dd>{profile.email || u.email || '—'}</dd></div>
                    </dl>
                </aside>
                <div className="admin-home-stats">
                    <div><span>Orders today</span><strong>{snapshot.orders_today ?? 0}</strong></div>
                    <div><span>Awaiting review</span><strong>{snapshot.pending_review ?? 0}</strong></div>
                    <div><span>Partner accounts</span><strong>{snapshot.partner_accounts ?? 0}</strong></div>
                    <div><span>Approved purchases</span><strong>{money(snapshot.purchases)}</strong></div>
                    <div><span>Outstanding dues</span><strong>{money(snapshot.dues)}</strong></div>
                </div>
            </section>

            <section className="account-actions dense-actions" aria-label="Company modules">
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
                            <h2>Recent orders</h2>
                            <p>Purchase requests and fulfilment progress.</p>
                        </div>
                        <Link href={`${base}/orders`} className="text-link">View all <ArrowUpRight size={15} /></Link>
                    </div>
                    {orders}
                </section>
            )}
            {data.lowStock?.length > 0 && (
                <section className="notice">
                    <h2>Low-stock alerts</h2>
                    <p>{data.lowStock.map((p: Row) => `${p.name} (${p.stock} left)`).join(' · ')}</p>
                    <Link href={`${base}/inventory`} className="text-link">Review inventory</Link>
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
        { route: 'catalog', name: 'Product collection', description: 'Products, prices and available stock', icon: Package, show: true },
        { route: 'orders', name: 'Orders', description: 'Purchase requests and order history', icon: ShoppingBag, show: commerce },
        { route: 'earnings', name: 'My earnings', description: 'View earnings and payouts', icon: Coins, show: !staff },
        { route: 'network', name: 'My network', description: 'Direct referrals', icon: GitBranch, show: u.role === 'dealer' },
        { route: 'ranks', name: 'Rank progress', description: 'Qualification status', icon: BadgeCheck, show: u.role === 'dealer' },
        { route: 'profile', name: 'My profile', description: 'Account information', icon: Users, show: true },
        { route: 'security', name: 'Account security', description: 'Change password', icon: Settings, show: true },
    ].filter((x) => x.show);

    return (
        <>
            <section className="account-profile-card">
                <div className="account-profile-info">
                    <div className="account-avatar" aria-hidden="true">{u.name[0]}</div>
                    <div>
                        <p>Hello,</p>
                        <h2>{u.name}</h2>
                        <small>{u.dealer_code ?? 'Company staff'} · {labelRole(u.role)}</small>
                        <Badge status={u.status} />
                    </div>
                </div>
            </section>
            <section className="account-actions dense-actions" aria-label="Account actions">
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
                        <div><h2>Recent orders</h2></div>
                        <Link href={`${base}/orders`} className="text-link">View all <ArrowUpRight size={15} /></Link>
                    </div>
                    {orders}
                </section>
            )}
        </>
    );
}
