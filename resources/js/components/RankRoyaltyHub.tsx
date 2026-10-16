import React, { useState, useMemo } from 'react';
import { Award, ShieldCheck, TrendingUp, Users, Search, Filter, CheckCircle2, Clock, DollarSign, ChevronRight, Eye } from 'lucide-react';
import { money, date } from './UI';

interface RankRecord {
    id: number;
    user_id: number;
    name: string;
    dealer_code: string;
    phone: string;
    district: string;
    division: string;
    current_rank: 'dealer' | 'mo' | 'am' | 'sm' | 'me';
    achieved_at: string | null;
    lifetime_purchase_total: number;
    monthly_purchase_total: number;
    one_time_incentive_paid: boolean;
}

interface RankStats {
    mo_count: number;
    am_count: number;
    sm_count: number;
    me_count: number;
    dealer_count: number;
}

interface RankRoyaltyHubProps {
    ranks: RankRecord[];
    stats: RankStats;
    policy?: {qualification: Record<string,number|string>; incentives: Record<string,number|string>};
}

const defaultHierarchy = [
    {
        key: 'dealer',
        name: 'Dealer',
        badge: { label: 'DEALER', bg: '#f1f5f9', text: '#475569', border: '#cbd5e1' },
        requirement: 'Approved partner enrollment',
        incentive: 'Retail margin & 5% cashback',
        royaltyShare: '—'
    },
    {
        key: 'mo',
        name: 'Marketing Officer (MO)',
        badge: { label: 'MO (MKT OFFICER)', bg: '#ecfdf5', text: '#047857', border: '#a7f3d0' },
        requirement: '৳2,50,000 (৳2.5L) Cumulative Purchase',
        incentive: '৳5,000 One-time Cash Bonus',
        royaltyShare: '2.00% Monthly Company Turnover Pool'
    },
    {
        key: 'am',
        name: 'Area Manager (AM)',
        badge: { label: 'AM (AREA MGR)', bg: '#eff6ff', text: '#1d4ed8', border: '#bfdbfe' },
        requirement: '10 MOs in 3-Generation Downline (Gen 1-3)',
        incentive: '৳10,000 One-time Cash Bonus',
        royaltyShare: '1.00% Monthly Company Turnover Pool'
    },
    {
        key: 'sm',
        name: 'Sales Manager (SM)',
        badge: { label: 'SM (SALES MGR)', bg: '#faf5ff', text: '#7e22ce', border: '#e9d5ff' },
        requirement: 'Leadership Volume & Multiple AM Downlines',
        incentive: '৳20,000 One-time Cash Bonus',
        royaltyShare: '0.50% Monthly Company Turnover Pool'
    },
    {
        key: 'me',
        name: 'Marketing Executive (ME)',
        badge: { label: 'ME (EXEC MGR)', bg: '#fffbeb', text: '#b45309', border: '#fde68a' },
        requirement: 'Top Echelon Leadership Network',
        incentive: '৳50,000 One-time Cash Bonus',
        royaltyShare: '0.50% Monthly Company Turnover Pool'
    },
];

export default function RankRoyaltyHub({ ranks, stats, policy }: RankRoyaltyHubProps) {
    const q=policy?.qualification??{};
    const bonuses=policy?.incentives??{};
    const rankHierarchy=defaultHierarchy.map(row=>row.key==='dealer'?row:{...row,
        requirement: row.key==='mo'?`${money(q.mo_target??25000)} cumulative own purchases`:`${q[row.key+'_count']??10} ${row.key==='am'?'MO':row.key==='sm'?'AM':'SM'} leaders within ${q.qualification_depth??3} generations`,
        incentive:`${money(bonuses[row.key+'_bonus']??0)} one-time funded award`,
        royaltyShare:`${q[row.key+'_share_pct']??1}% purchase-value pool shared within the qualifying rank`,
    });
    const [searchQuery, setSearchQuery] = useState('');
    const [rankFilter, setRankFilter] = useState('all');
    const [auditingPartner, setAuditingPartner] = useState<RankRecord | null>(null);

    const filteredRanks = useMemo(() => {
        return ranks.filter(r => {
            const matchesQuery = !searchQuery ||
                (r.name && r.name.toLowerCase().includes(searchQuery.toLowerCase())) ||
                (r.dealer_code && r.dealer_code.toLowerCase().includes(searchQuery.toLowerCase())) ||
                (r.phone && r.phone.includes(searchQuery));
            const matchesRank = rankFilter === 'all' || r.current_rank === rankFilter;
            return matchesQuery && matchesRank;
        });
    }, [ranks, searchQuery, rankFilter]);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top Stat Summary Grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>Total Active Dealers</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>
                        {stats.dealer_count || ranks.length}
                    </div>
                    <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>Enrolled network partners</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #a7f3d0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#047857', fontSize: 12, fontWeight: 600 }}>Marketing Officers (MO)</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 4 }}>
                        {stats.mo_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>2.0% pool royalty eligible</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>Area Managers (AM)</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 4 }}>
                        {stats.am_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#3b82f6', marginTop: 2 }}>1.0% pool royalty eligible</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e9d5ff', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#7e22ce', fontSize: 12, fontWeight: 600 }}>Sales Managers (SM)</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#7e22ce', marginTop: 4 }}>
                        {stats.sm_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#a855f7', marginTop: 2 }}>0.5% pool royalty eligible</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #fde68a', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#b45309', fontSize: 12, fontWeight: 600 }}>Marketing Execs (ME)</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#b45309', marginTop: 4 }}>
                        {stats.me_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#d97706', marginTop: 2 }}>0.5% pool royalty eligible</div>
                </div>
            </div>

            {/* Rank Ladder Progression Visualizer */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                padding: '20px 24px',
                boxShadow: '0 2px 6px rgba(0,0,0,0.03)'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
                    <div>
                        <h3 style={{ fontSize: 16, fontWeight: 800, color: '#0f172a', margin: 0 }}>
                            Career Rank & Royalty Pool Framework
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '3px 0 0 0' }}>
                            Cumulative lifetime purchasing and 3-generation downline closure criteria.
                        </p>
                    </div>
                    <div style={{ fontSize: 11, fontWeight: 700, padding: '4px 10px', background: '#ecfdf5', color: '#047857', borderRadius: 20 }}>
                        4.00% Combined Royalty Pool
                    </div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: 12 }}>
                    {rankHierarchy.map((tier, idx) => (
                        <div
                            key={tier.key}
                            style={{
                                border: `1.5px solid ${tier.badge.border}`,
                                borderRadius: 10,
                                padding: 14,
                                background: '#fafafa',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'space-between'
                            }}
                        >
                            <div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 10, fontWeight: 800, color: '#94a3b8' }}>LEVEL 0{idx + 1}</span>
                                    <span style={{
                                        fontSize: 9,
                                        fontWeight: 800,
                                        padding: '2px 5px',
                                        borderRadius: 4,
                                        background: tier.badge.bg,
                                        color: tier.badge.text,
                                        border: `1px solid ${tier.badge.border}`
                                    }}>
                                        {tier.badge.label}
                                    </span>
                                </div>
                                <div style={{ fontSize: 14, fontWeight: 800, color: '#0f172a', marginTop: 6 }}>
                                    {tier.name}
                                </div>
                                <div style={{ fontSize: 11, color: '#475569', marginTop: 6, lineHeight: 1.4 }}>
                                    <b>Requirement:</b> {tier.requirement}
                                </div>
                            </div>

                            <div style={{ marginTop: 12, paddingTop: 8, borderTop: '1px solid #e2e8f0', fontSize: 11 }}>
                                <div style={{ color: '#006837', fontWeight: 600 }}>
                                    🎁 {tier.incentive}
                                </div>
                                <div style={{ color: '#1d4ed8', fontWeight: 600, marginTop: 2 }}>
                                    💎 Royalty: {tier.royaltyShare}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Filter Bar */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                padding: '12px 16px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 12
            }}>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                    <div style={{ position: 'relative', width: 280 }}>
                        <Search size={16} style={{ position: 'absolute', left: 10, top: 10, color: '#94a3b8' }} />
                        <input
                            type="text"
                            placeholder="Search leader by name, code, phone…"
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                            style={{
                                width: '100%',
                                padding: '8px 12px 8px 34px',
                                fontSize: 13,
                                border: '1px solid #cbd5e1',
                                borderRadius: 6
                            }}
                        />
                    </div>

                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <Filter size={15} style={{ color: '#64748b' }} />
                        <select
                            value={rankFilter}
                            onChange={e => setRankFilter(e.target.value)}
                            style={{
                                padding: '8px 12px',
                                fontSize: 13,
                                border: '1px solid #cbd5e1',
                                borderRadius: 6,
                                background: '#ffffff',
                                color: '#334155'
                            }}
                        >
                            <option value="all">All Ranks ({ranks.length})</option>
                            <option value="mo">Marketing Officer (MO)</option>
                            <option value="am">Area Manager (AM)</option>
                            <option value="sm">Sales Manager (SM)</option>
                            <option value="me">Marketing Executive (ME)</option>
                            <option value="dealer">Dealer</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Roster Table */}
            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                            <th style={{ padding: '12px 16px' }}>Partner</th>
                            <th style={{ padding: '12px 16px' }}>Code</th>
                            <th style={{ padding: '12px 16px' }}>Current Rank</th>
                            <th style={{ padding: '12px 16px' }}>Lifetime Purchases</th>
                            <th style={{ padding: '12px 16px' }}>Monthly Purchases</th>
                            <th style={{ padding: '12px 16px' }}>Rank Achieved At</th>
                            <th style={{ padding: '12px 16px' }}>One-time Incentive</th>
                            <th style={{ padding: '12px 16px', textAlign: 'right' }}>Audit</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredRanks.length > 0 ? (
                            filteredRanks.map(r => {
                                const rankCfg = rankHierarchy.find(x => x.key === r.current_rank) || rankHierarchy[0];
                                return (
                                    <tr key={r.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{r.name || 'Unnamed Partner'}</div>
                                            <div style={{ fontSize: 11, color: '#64748b' }}>
                                                {r.phone || 'No phone'} {r.district ? `• ${r.district}` : ''}
                                            </div>
                                        </td>
                                        <td style={{ padding: '12px 16px', fontWeight: 600 }}>{r.dealer_code || '—'}</td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <span style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 7px',
                                                borderRadius: 4,
                                                background: rankCfg.badge.bg,
                                                color: rankCfg.badge.text,
                                                border: `1px solid ${rankCfg.badge.border}`
                                            }}>
                                                {rankCfg.badge.label}
                                            </span>
                                        </td>
                                        <td style={{ padding: '12px 16px', fontWeight: 700, color: '#006837' }}>
                                            {money(r.lifetime_purchase_total)}
                                        </td>
                                        <td style={{ padding: '12px 16px', color: '#475569' }}>
                                            {money(r.monthly_purchase_total)}
                                        </td>
                                        <td style={{ padding: '12px 16px', color: '#64748b', fontSize: 12 }}>
                                            {r.achieved_at ? date(r.achieved_at) : '—'}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            {r.current_rank !== 'dealer' ? (
                                                <span style={{
                                                    fontSize: 10,
                                                    fontWeight: 700,
                                                    padding: '2px 6px',
                                                    borderRadius: 4,
                                                    background: r.one_time_incentive_paid ? '#dcfce7' : '#fffbeb',
                                                    color: r.one_time_incentive_paid ? '#15803d' : '#b45309',
                                                    border: `1px solid ${r.one_time_incentive_paid ? '#a7f3d0' : '#fde68a'}`
                                                }}>
                                                    {r.one_time_incentive_paid ? 'PAID' : 'PENDING APPROVAL'}
                                                </span>
                                            ) : (
                                                <span style={{ color: '#94a3b8' }}>—</span>
                                            )}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right' }}>
                                            <button
                                                type="button"
                                                onClick={() => setAuditingPartner(r)}
                                                style={{
                                                    fontSize: 11,
                                                    fontWeight: 600,
                                                    padding: '4px 8px',
                                                    background: '#f8fafc',
                                                    border: '1px solid #cbd5e1',
                                                    borderRadius: 4,
                                                    cursor: 'pointer',
                                                    display: 'inline-flex',
                                                    alignItems: 'center',
                                                    gap: 4
                                                }}
                                            >
                                                <Eye size={12} /> Inspect
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })
                        ) : (
                            <tr>
                                <td colSpan={8} style={{ textAlign: 'center', padding: '36px 0', color: '#94a3b8' }}>
                                    No records matching your search.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Audit Modal for Selected Partner */}
            {auditingPartner && (
                <div style={{
                    position: 'fixed',
                    top: 0, left: 0, right: 0, bottom: 0,
                    background: 'rgba(15, 23, 42, 0.6)',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    zIndex: 9999, padding: 16
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: 12,
                        maxWidth: 480,
                        width: '100%',
                        padding: 24,
                        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1)'
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                            <div>
                                <span style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    padding: '2px 6px',
                                    borderRadius: 4,
                                    background: (rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).badge.bg,
                                    color: (rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).badge.text,
                                    border: `1px solid ${(rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).badge.border}`
                                }}>
                                    {(rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).badge.label}
                                </span>
                                <h3 style={{ fontSize: 18, fontWeight: 800, margin: '8px 0 2px 0', color: '#0f172a' }}>
                                    {auditingPartner.name}
                                </h3>
                                <div style={{ fontSize: 12, color: '#64748b' }}>
                                    Code: <b>{auditingPartner.dealer_code}</b> • Phone: {auditingPartner.phone || '—'}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setAuditingPartner(null)}
                                style={{ background: 'none', border: 'none', fontSize: 18, color: '#94a3b8', cursor: 'pointer' }}
                            >
                                ✕
                            </button>
                        </div>

                        <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '14px 0' }} />

                        {/* Progress Towards MO Target */}
                        <div style={{ background: '#f8fafc', padding: 14, borderRadius: 8 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, fontWeight: 700 }}>
                                <span>MO Promotion Milestone (৳2.5L Turnover)</span>
                                <span style={{ color: '#006837' }}>
                                    {Math.min(100, Math.round((auditingPartner.lifetime_purchase_total / 250000) * 100))}%
                                </span>
                            </div>
                            <div style={{ width: '100%', height: 8, background: '#e2e8f0', borderRadius: 4, overflow: 'hidden', marginTop: 8 }}>
                                <div style={{
                                    height: '100%',
                                    width: `${Math.min(100, (auditingPartner.lifetime_purchase_total / 250000) * 100)}%`,
                                    background: '#006837',
                                    borderRadius: 4
                                }} />
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#64748b', marginTop: 6 }}>
                                <span>Current: {money(auditingPartner.lifetime_purchase_total)}</span>
                                <span>Target: {money(250000)}</span>
                            </div>
                        </div>

                        {/* AM Downline Qualification Progress */}
                        <div style={{ background: '#f8fafc', padding: 14, borderRadius: 8, marginTop: 12 }}>
                            <div style={{ fontSize: 12, fontWeight: 700, color: '#1d4ed8' }}>
                                AM Downline Qualification (Gen 1-3)
                            </div>
                            <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                                Requires at least 10 Marketing Officers (MOs) across 3 downline generations in the Directed Graph Closure Table.
                            </div>
                        </div>

                        {/* Royalty Status */}
                        <div style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', padding: 12, borderRadius: 8, marginTop: 12, fontSize: 12 }}>
                            <div style={{ fontWeight: 700, color: '#047857' }}>Royalty Pool Participation</div>
                            <div style={{ color: '#065f46', marginTop: 2 }}>
                                {auditingPartner.current_rank === 'dealer'
                                    ? 'Requires promotion to MO (৳2.5L cumulative) to qualify for 2% company turnover royalty.'
                                    : `Active participant in the ${(rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).royaltyShare}.`}
                            </div>
                        </div>

                        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 16 }}>
                            <button
                                type="button"
                                onClick={() => setAuditingPartner(null)}
                                style={{
                                    padding: '8px 16px',
                                    background: '#006837',
                                    color: '#ffffff',
                                    border: 'none',
                                    borderRadius: 6,
                                    fontWeight: 700,
                                    fontSize: 12,
                                    cursor: 'pointer'
                                }}
                            >
                                Close Audit View
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
