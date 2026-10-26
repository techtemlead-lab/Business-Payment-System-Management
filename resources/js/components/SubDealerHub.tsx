import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Plus, Search, ArrowUpRight, BadgeCheck
} from 'lucide-react';
import { Table, Pagination, Badge, money, date } from './UI';
import { useT } from '../shared/i18n/t';

interface SubDealerHubProps {
    accounts: any;
    orders?: any;
    sponsors?: any[];
    stats?: {
        total: number;
        active: number;
        orders_count?: number;
        orders_volume?: number;
    };
    filters?: any;
    onEditAccount?: (account: any) => void;
}

export default function SubDealerHub({
    accounts,
    orders,
    sponsors = [],
    stats = { total: 0, active: 0, orders_count: 0, orders_volume: 0 },
    filters = {},
    onEditAccount
}: SubDealerHubProps) {
    const translate = useT();
    const [tab, setTab] = useState<'accounts' | 'orders' | 'salary' | 'territory'>('accounts');
    const [q, setQ] = useState(filters.q ?? '');
    const { portalBase: base } = usePage<any>().props;

    const subdealersList = accounts?.data ?? [];
    const ordersList = orders?.data ?? [];

    const salaryTiers = [
        { tierKey: 'sd_tier_01', rate: '1.0%', min: 10000, descKey: 'sd_tier_01_desc' },
        { tierKey: 'sd_tier_02', rate: '2.0%', min: 25000, descKey: 'sd_tier_02_desc' },
        { tierKey: 'sd_tier_03', rate: '3.0%', min: 40000, descKey: 'sd_tier_03_desc' },
        { tierKey: 'sd_tier_04', rate: '4.0%', min: 50000, descKey: 'sd_tier_04_desc' },
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Header & Sub-Navigation */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                padding: '20px 24px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 16
            }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ background: '#059669', color: '#ffffff', padding: '4px 10px', borderRadius: 6, fontSize: 11, fontWeight: 700 }}>
                            {translate('portal', 'sd_badge')}
                        </span>
                        <span style={{ fontSize: 12, color: '#64748b', fontWeight: 600 }}>{translate('portal', 'sd_badge_hint')}</span>
                    </div>
                    <h2 style={{ fontSize: 22, fontWeight: 800, color: '#011627', margin: '6px 0 2px' }}>
                        {translate('portal', 'sd_heading')}
                    </h2>
                    <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                        {translate('portal', 'sd_heading_hint')}
                    </p>
                </div>

                <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                    <div style={{ display: 'flex', gap: 6, background: '#f8fafc', padding: 4, borderRadius: 8, border: '1px solid #e2e8f0' }}>
                        <button
                            type="button"
                            onClick={() => setTab('accounts')}
                            style={{
                                padding: '6px 14px',
                                fontSize: 12,
                                fontWeight: 700,
                                borderRadius: 6,
                                border: 0,
                                cursor: 'pointer',
                                background: tab === 'accounts' ? '#059669' : 'transparent',
                                color: tab === 'accounts' ? '#ffffff' : '#475569'
                            }}
                        >
                            {translate('portal', 'sd_tab_directory', { count: String(stats.total) })}
                        </button>
                        <button
                            type="button"
                            onClick={() => setTab('orders')}
                            style={{
                                padding: '6px 14px',
                                fontSize: 12,
                                fontWeight: 700,
                                borderRadius: 6,
                                border: 0,
                                cursor: 'pointer',
                                background: tab === 'orders' ? '#059669' : 'transparent',
                                color: tab === 'orders' ? '#ffffff' : '#475569'
                            }}
                        >
                            {translate('portal', 'sd_tab_orders')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setTab('salary')}
                            style={{
                                padding: '6px 14px',
                                fontSize: 12,
                                fontWeight: 700,
                                borderRadius: 6,
                                border: 0,
                                cursor: 'pointer',
                                background: tab === 'salary' ? '#059669' : 'transparent',
                                color: tab === 'salary' ? '#ffffff' : '#475569'
                            }}
                        >
                            {translate('portal', 'sd_tab_salary')}
                        </button>
                    </div>

                    <Link href="/accounts/create" className="button dark" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: '#059669', borderColor: '#059669' }}>
                        <Plus size={16} /> {translate('portal', 'sd_add')}
                    </Link>
                </div>
            </div>

            {/* Quick Metrics */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'sd_kpi_total')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#011627', marginTop: 4 }}>
                        {stats.total}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>{translate('portal', 'sd_kpi_active', { count: String(stats.active) })}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'sd_kpi_discount')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#059669', marginTop: 4 }}>
                        {translate('portal', 'sd_kpi_discount_value')}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{translate('portal', 'sd_kpi_discount_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'sd_kpi_orders')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#011627', marginTop: 4 }}>
                        {stats.orders_count || ordersList.length}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{translate('portal', 'sd_kpi_orders_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'sd_kpi_salary_range')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#2e3192', marginTop: 4 }}>
                        {translate('portal', 'sd_kpi_salary_value')}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{translate('portal', 'sd_kpi_salary_hint')}</div>
                </div>
            </div>

            {/* TAB 1: Sub-Dealer Accounts */}
            {tab === 'accounts' && (
                <section className="panel table-panel">
                    <div className="panel-heading" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '20px 24px 0' }}>
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 2px' }}>{translate('portal', 'sd_dir_title')}</h3>
                            <p style={{ fontSize: 12, color: '#64748b', margin: 0 }}>{translate('portal', 'sd_dir_hint')}</p>
                        </div>
                        <form className="filter-bar" onSubmit={e => { e.preventDefault(); router.get(`${base}/sub-dealers`, { q }, { preserveState: true }); }}>
                            <div className="search-input">
                                <Search size={16} />
                                <input placeholder={translate('portal', 'sd_search_ph')} value={q} onChange={e => setQ(e.target.value)} />
                            </div>
                            <button className="button small">{translate('portal', 'sd_search')}</button>
                        </form>
                    </div>

                    <div style={{ padding: '0 24px 20px' }}>
                        <Table
                            rows={subdealersList}
                            columns={[
                                {
                                    key: 'name',
                                    title: translate('portal', 'sd_col_partner'),
                                    render: r => (
                                        <div>
                                            <b>{r.name}</b>
                                            {(r.profile?.shop_name || r.profile?.pharmacy_name || r.profile?.pharmacy) && (
                                                <div style={{ fontSize: 11, color: '#059669', fontWeight: 600 }}>
                                                    {r.profile.shop_name || r.profile.pharmacy_name || r.profile.pharmacy}
                                                </div>
                                            )}
                                            <small className="cell-sub">{r.phone ? `${r.phone} · ` : ''}{r.email}</small>
                                        </div>
                                    )
                                },
                                {
                                    key: 'dealer_code',
                                    title: translate('portal', 'sd_col_code'),
                                    render: r => <b style={{ color: '#059669' }}>{r.dealer_code ?? '—'}</b>
                                },
                                {
                                    key: 'sponsor',
                                    title: translate('portal', 'sd_col_parent'),
                                    render: r => {
                                        const sponsor = sponsors.find(s => s.id === r.sponsor_id);
                                        return sponsor ? (
                                            <div>
                                                <b>{sponsor.name}</b>
                                                <small className="cell-sub">{sponsor.dealer_code}</small>
                                            </div>
                                        ) : <span style={{ color: '#94a3b8' }}>{translate('portal', 'sd_parent_none')}</span>;
                                    }
                                },
                                {
                                    key: 'location',
                                    title: translate('portal', 'sd_col_territory'),
                                    render: r => <>{r.district ?? '—'}{r.division ? `, ${r.division}` : ''}</>
                                },
                                {
                                    key: 'status',
                                    title: translate('portal', 'col_status'),
                                    render: r => <Badge status={r.status} />
                                },
                                {
                                    key: 'action',
                                    title: translate('portal', 'sd_col_manage'),
                                    render: r => (
                                        <div className="inline-actions">
                                            <button
                                                type="button"
                                                className="text-link"
                                                onClick={() => onEditAccount && onEditAccount(r)}
                                            >
                                                {translate('portal', 'sd_edit_status')}
                                            </button>
                                            <button
                                                type="button"
                                                className="text-link"
                                                disabled={r.status !== 'active'}
                                                onClick={() => router.post(`/commands/accounts/${r.id}/invite`)}
                                            >
                                                {translate('portal', 'sd_invite')}
                                            </button>
                                        </div>
                                    )
                                }
                            ]}
                        />
                        {accounts && <Pagination page={accounts} />}
                    </div>
                </section>
            )}

            {/* TAB 2: Purchase Orders */}
            {tab === 'orders' && (
                <section className="panel table-panel">
                    <div className="panel-heading" style={{ padding: '20px 24px 0' }}>
                        <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 2px' }}>{translate('portal', 'sd_orders_title')}</h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: 0 }}>{translate('portal', 'sd_orders_hint')}</p>
                    </div>

                    <div style={{ padding: '0 24px 20px' }}>
                        <Table
                            rows={ordersList}
                            columns={[
                                {
                                    key: 'number',
                                    title: translate('portal', 'sd_col_order'),
                                    render: r => (
                                        <Link className="record-link" href={`${base}/orders/${r.id}`}>
                                            {r.number}
                                            <small>{date(r.created_at)}</small>
                                        </Link>
                                    )
                                },
                                {
                                    key: 'user',
                                    title: translate('portal', 'sd_col_subdealer'),
                                    render: r => (
                                        <>
                                            {r.user.name}
                                            <small className="cell-sub">{r.user.dealer_code}</small>
                                        </>
                                    )
                                },
                                {
                                    key: 'lines',
                                    title: translate('portal', 'sd_col_items'),
                                    render: r => r.lines?.reduce((n: number, l: any) => n + l.quantity, 0) ?? 0
                                },
                                {
                                    key: 'total',
                                    title: translate('portal', 'sd_col_wholesale'),
                                    render: r => <b style={{ color: '#006837' }}>{money(r.total)}</b>
                                },
                                {
                                    key: 'status',
                                    title: translate('portal', 'col_status'),
                                    render: r => <Badge status={r.status} />
                                },
                                {
                                    key: 'action',
                                    title: '',
                                    render: r => (
                                        <Link aria-label={translate('portal', 'a11y_view_order', { number: String(r.number) })} href={`${base}/orders/${r.id}`} className="icon-button">
                                            <ArrowUpRight size={17} />
                                        </Link>
                                    )
                                }
                            ]}
                        />
                        {orders && <Pagination page={orders} />}
                    </div>
                </section>
            )}

            {/* TAB 3: Monthly Salary · 3% */}
            {tab === 'salary' && (
                <section className="panel" style={{ padding: 24 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
                        <BadgeCheck size={24} color="#059669" />
                        <div>
                            <h3 style={{ fontSize: 18, fontWeight: 700, margin: 0, color: '#011627' }}>
                                {translate('portal', 'sd_salary_title')}
                            </h3>
                            <p style={{ fontSize: 12, color: '#64748b', margin: 0 }}>
                                {translate('portal', 'sd_salary_hint')}
                            </p>
                        </div>
                    </div>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16, marginTop: 16 }}>
                        {salaryTiers.map((tier) => (
                            <div key={tier.tierKey} style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 18 }}>
                                <span style={{ fontSize: 10, fontWeight: 700, color: '#64748b', letterSpacing: '0.05em' }}>{translate('portal', tier.tierKey)}</span>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#059669', margin: '4px 0' }}>{tier.rate}</div>
                                <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{money(tier.min)}+</div>
                                <p style={{ fontSize: 11, color: '#64748b', margin: '6px 0 0', lineHeight: 1.4 }}>{translate('portal', tier.descKey)}</p>
                            </div>
                        ))}
                    </div>

                    <div style={{ marginTop: 20, padding: 14, background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, fontSize: 12, color: '#166534' }}>
                        💡 <b>{translate('portal', 'sd_policy_rule')}</b> {translate('portal', 'sd_policy_body')}
                    </div>
                </section>
            )}
        </div>
    );
}
