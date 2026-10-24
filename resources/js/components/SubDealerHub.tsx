import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Store, Plus, Search, ArrowUpRight, BadgeCheck, Users, ShoppingBag,
    Wallet, ShieldCheck, MapPin, ChevronRight
} from 'lucide-react';
import { Table, Pagination, Badge, money, date, label } from './UI';

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
    const [tab, setTab] = useState<'accounts' | 'orders' | 'salary' | 'territory'>('accounts');
    const [q, setQ] = useState(filters.q ?? '');
    const { portalBase: base } = usePage<any>().props;

    const subdealersList = accounts?.data ?? [];
    const ordersList = orders?.data ?? [];

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
                            SUB-DEALER SECTION
                        </span>
                        <span style={{ fontSize: 12, color: '#64748b', fontWeight: 600 }}>Product Prices, Orders & Salary · 3%</span>
                    </div>
                    <h2 style={{ fontSize: 22, fontWeight: 800, color: '#011627', margin: '6px 0 2px' }}>
                        Sub-Dealer Operations & Records
                    </h2>
                    <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                        Separate accounts, purchase orders at configured partner prices, territory mapping, and monthly flat 3% salary.
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
                            1. Sub-Dealer Directory ({stats.total})
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
                            2. Purchase Orders
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
                            3. Monthly Salary · 3%
                        </button>
                    </div>

                    <Link href="/accounts/create" className="button dark" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: '#059669', borderColor: '#059669' }}>
                        <Plus size={16} /> Add Sub-Dealer
                    </Link>
                </div>
            </div>

            {/* Quick Metrics */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>Total Registered Sub-Dealers</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#011627', marginTop: 4 }}>
                        {stats.total}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>{stats.active} Active & Operating</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>Approved Pricing Discount</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#059669', marginTop: 4 }}>
                        40.0% OFF MRP
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>Configured sub-dealer purchase prices</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>Sub-Dealer Orders Placed</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#011627', marginTop: 4 }}>
                        {stats.orders_count || ordersList.length}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>Processed in system</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>Salary Qualification Range</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#2e3192', marginTop: 4 }}>
                        1% to 4%
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>Based on ৳10k to ৳50k monthly volume</div>
                </div>
            </div>

            {/* TAB 1: Sub-Dealer Accounts */}
            {tab === 'accounts' && (
                <section className="panel table-panel">
                    <div className="panel-heading" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '20px 24px 0' }}>
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 2px' }}>Sub-Dealer Partner Directory</h3>
                            <p style={{ fontSize: 12, color: '#64748b', margin: 0 }}>Showing all retail partners operating under the sub-dealer agreement.</p>
                        </div>
                        <form className="filter-bar" onSubmit={e => { e.preventDefault(); router.get(`${base}/sub-dealers`, { q }, { preserveState: true }); }}>
                            <div className="search-input">
                                <Search size={16} />
                                <input placeholder="Search sub-dealer name or code..." value={q} onChange={e => setQ(e.target.value)} />
                            </div>
                            <button className="button small">Search</button>
                        </form>
                    </div>

                    <div style={{ padding: '0 24px 20px' }}>
                        <Table
                            rows={subdealersList}
                            columns={[
                                {
                                    key: 'name',
                                    title: 'Sub-Dealer / Pharmacy',
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
                                    title: 'Sub-Dealer Code',
                                    render: r => <b style={{ color: '#059669' }}>{r.dealer_code ?? '—'}</b>
                                },
                                {
                                    key: 'sponsor',
                                    title: 'Parent Dealer',
                                    render: r => {
                                        const sponsor = sponsors.find(s => s.id === r.sponsor_id);
                                        return sponsor ? (
                                            <div>
                                                <b>{sponsor.name}</b>
                                                <small className="cell-sub">{sponsor.dealer_code}</small>
                                            </div>
                                        ) : <span style={{ color: '#94a3b8' }}>Direct / None</span>;
                                    }
                                },
                                {
                                    key: 'location',
                                    title: 'Territory',
                                    render: r => <>{r.district ?? '—'}{r.division ? `, ${r.division}` : ''}</>
                                },
                                {
                                    key: 'status',
                                    title: 'Status',
                                    render: r => <Badge status={r.status} />
                                },
                                {
                                    key: 'action',
                                    title: 'Manage',
                                    render: r => (
                                        <div className="inline-actions">
                                            <button
                                                type="button"
                                                className="text-link"
                                                onClick={() => onEditAccount && onEditAccount(r)}
                                            >
                                                Edit / Status
                                            </button>
                                            <button
                                                type="button"
                                                className="text-link"
                                                disabled={r.status !== 'active'}
                                                onClick={() => router.post(`/commands/accounts/${r.id}/invite`)}
                                            >
                                                Invite
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
                        <h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 2px' }}>Sub-Dealer Purchase Orders</h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: 0 }}>Wholesale purchases placed by sub-dealers with configured product prices.</p>
                    </div>

                    <div style={{ padding: '0 24px 20px' }}>
                        <Table
                            rows={ordersList}
                            columns={[
                                {
                                    key: 'number',
                                    title: 'Order Number',
                                    render: r => (
                                        <Link className="record-link" href={`${base}/orders/${r.id}`}>
                                            {r.number}
                                            <small>{date(r.created_at)}</small>
                                        </Link>
                                    )
                                },
                                {
                                    key: 'user',
                                    title: 'Sub-Dealer',
                                    render: r => (
                                        <>
                                            {r.user.name}
                                            <small className="cell-sub">{r.user.dealer_code}</small>
                                        </>
                                    )
                                },
                                {
                                    key: 'lines',
                                    title: 'Items',
                                    render: r => r.lines?.reduce((n: number, l: any) => n + l.quantity, 0) ?? 0
                                },
                                {
                                    key: 'total',
                                    title: 'Wholesale Purchase Value',
                                    render: r => <b style={{ color: '#006837' }}>{money(r.total)}</b>
                                },
                                {
                                    key: 'status',
                                    title: 'Status',
                                    render: r => <Badge status={r.status} />
                                },
                                {
                                    key: 'action',
                                    title: '',
                                    render: r => (
                                        <Link aria-label={`View ${r.number}`} href={`${base}/orders/${r.id}`} className="icon-button">
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
                                Confirmed Sub-Dealer Monthly Salary · 3%
                            </h3>
                            <p style={{ fontSize: 12, color: '#64748b', margin: 0 }}>
                                According to client specifications, the highest qualifying tier applies once to the entire monthly qualifying purchase amount.
                            </p>
                        </div>
                    </div>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16, marginTop: 16 }}>
                        {[
                            { tier: 'TIER 01', rate: '1.0%', min: 10000, desc: 'Qualifying monthly purchases from ৳10,000' },
                            { tier: 'TIER 02', rate: '2.0%', min: 25000, desc: 'Qualifying monthly purchases from ৳25,000' },
                            { tier: 'TIER 03', rate: '3.0%', min: 40000, desc: 'Qualifying monthly purchases from ৳40,000' },
                            { tier: 'TIER 04', rate: '4.0%', min: 50000, desc: 'Qualifying monthly purchases from ৳50,000+' }
                        ].map((t, idx) => (
                            <div key={idx} style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 18 }}>
                                <span style={{ fontSize: 10, fontWeight: 700, color: '#64748b', letterSpacing: '0.05em' }}>{t.tier}</span>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#059669', margin: '4px 0' }}>{t.rate}</div>
                                <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{money(t.min)}+</div>
                                <p style={{ fontSize: 11, color: '#64748b', margin: '6px 0 0', lineHeight: 1.4 }}>{t.desc}</p>
                            </div>
                        ))}
                    </div>

                    <div style={{ marginTop: 20, padding: 14, background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, fontSize: 12, color: '#166534' }}>
                        💡 <b>Policy Rule:</b> Monthly volume below ৳10,000 receives 0% salary. When a sub-dealer hits ৳50,000+, they earn the full 4.0% salary on their entire monthly volume.
                    </div>
                </section>
            )}
        </div>
    );
}
