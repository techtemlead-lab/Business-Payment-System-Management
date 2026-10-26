import React, { useState, useMemo } from 'react';
import { Award, ShieldCheck, TrendingUp, Users, Search, Filter, CheckCircle2, Clock, DollarSign, ChevronRight, Eye } from 'lucide-react';
import { money, date } from './UI';
import { useT } from '../shared/i18n/t';

interface RankHistoryEntry {
    rank: string;
    achieved_at: string;
}

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
    qualification_history?: RankHistoryEntry[];
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

const rankBadgeStyles: Record<string, { bg: string; text: string; border: string }> = {
    dealer: { bg: '#f1f5f9', text: '#475569', border: '#cbd5e1' },
    mo: { bg: '#ecfdf5', text: '#047857', border: '#a7f3d0' },
    am: { bg: '#eff6ff', text: '#1d4ed8', border: '#bfdbfe' },
    sm: { bg: '#faf5ff', text: '#7e22ce', border: '#e9d5ff' },
    me: { bg: '#fffbeb', text: '#b45309', border: '#fde68a' },
};



export default function RankRoyaltyHub({ ranks, stats, policy }: RankRoyaltyHubProps) {
    const translate = useT();
    const q=policy?.qualification??{};
    const bonuses=policy?.incentives??{};
    const rankHierarchy = [
        {
            key: 'dealer',
            name: translate('portal','acct_role_dealer'),
            badge: { label: translate('portal','rank_badge_dealer'), ...rankBadgeStyles.dealer },
            requirement: translate('portal','rank_req_dealer'),
            incentive: translate('portal','rank_incentive_dealer'),
            royaltyShare: '—',
        },
        {
            key: 'mo',
            name: translate('portal','net_rank_mo'),
            badge: { label: translate('portal','rank_badge_mo'), ...rankBadgeStyles.mo },
            requirement: translate('portal','rank_req_mo_dyn',{amount:money(q.mo_target??250000)}),
            incentive: translate('portal','rank_incentive_dyn',{amount:money(bonuses.mo_bonus??0)}),
            royaltyShare: translate('portal','rank_royalty_dyn',{pct:String(q.mo_share_pct??1)}),
        },
        {
            key: 'am',
            name: translate('portal','net_rank_am'),
            badge: { label: translate('portal','rank_badge_am'), ...rankBadgeStyles.am },
            requirement: translate('portal','rank_req_leaders_dyn',{count:String(q.am_count??10),rank:translate('portal','rank_short_mo'),depth:String(q.qualification_depth??3)}),
            incentive: translate('portal','rank_incentive_dyn',{amount:money(bonuses.am_bonus??0)}),
            royaltyShare: translate('portal','rank_royalty_dyn',{pct:String(q.am_share_pct??1)}),
        },
        {
            key: 'sm',
            name: translate('portal','net_rank_sm'),
            badge: { label: translate('portal','rank_badge_sm'), ...rankBadgeStyles.sm },
            requirement: translate('portal','rank_req_leaders_dyn',{count:String(q.sm_count??10),rank:translate('portal','rank_short_am'),depth:String(q.qualification_depth??3)}),
            incentive: translate('portal','rank_incentive_dyn',{amount:money(bonuses.sm_bonus??0)}),
            royaltyShare: translate('portal','rank_royalty_dyn',{pct:String(q.sm_share_pct??1)}),
        },
        {
            key: 'me',
            name: translate('portal','net_rank_me'),
            badge: { label: translate('portal','rank_badge_me'), ...rankBadgeStyles.me },
            requirement: translate('portal','rank_req_leaders_dyn',{count:String(q.me_count??10),rank:translate('portal','rank_short_sm'),depth:String(q.qualification_depth??3)}),
            incentive: translate('portal','rank_incentive_dyn',{amount:money(bonuses.me_bonus??0)}),
            royaltyShare: translate('portal','rank_royalty_dyn',{pct:String(q.me_share_pct??1)}),
        },
    ];
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

    const personalHistory = ranks.length === 1
        ? (ranks[0].qualification_history?.length
            ? ranks[0].qualification_history
            : (ranks[0].achieved_at && ranks[0].current_rank !== 'dealer'
                ? [{ rank: ranks[0].current_rank, achieved_at: ranks[0].achieved_at }]
                : []))
        : [];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {personalHistory.length > 0 && (
                <section className="panel" style={{ margin: 0 }}>
                    <div className="panel-heading">
                        <div>
                            <h2 style={{ margin: 0, fontSize: 16 }}>{translate('portal','rank_history_title')}</h2>
                            <p className="muted" style={{ margin: '4px 0 0' }}>{translate('portal','rank_history_hint')}</p>
                        </div>
                        <span className="badge">{String(ranks[0].current_rank).toUpperCase()}</span>
                    </div>
                    <ol style={{ margin: 0, paddingLeft: 18, display: 'flex', flexDirection: 'column', gap: 8 }}>
                        {personalHistory.map((entry, index) => (
                            <li key={`${entry.rank}-${entry.achieved_at}-${index}`} style={{ color: '#0f172a', fontSize: 13 }}>
                                <b style={{ textTransform: 'uppercase' }}>{entry.rank}</b>
                                <span style={{ color: '#64748b' }}> · {date(entry.achieved_at)}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            )}
            {/* Top Stat Summary Grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal','rank_stat_dealers')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>
                        {stats.dealer_count || ranks.length}
                    </div>
                    <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>{translate('portal','rank_stat_dealers_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #a7f3d0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#047857', fontSize: 12, fontWeight: 600 }}>{translate('portal','rank_stat_mo')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 4 }}>
                        {stats.mo_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>{translate('portal','rank_pool_mo')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>{translate('portal','rank_stat_am')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 4 }}>
                        {stats.am_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#3b82f6', marginTop: 2 }}>{translate('portal','rank_pool_am')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e9d5ff', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#7e22ce', fontSize: 12, fontWeight: 600 }}>{translate('portal','rank_stat_sm')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#7e22ce', marginTop: 4 }}>
                        {stats.sm_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#a855f7', marginTop: 2 }}>{translate('portal','rank_pool_sm')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #fde68a', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#b45309', fontSize: 12, fontWeight: 600 }}>{translate('portal','rank_stat_me')}</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#b45309', marginTop: 4 }}>
                        {stats.me_count}
                    </div>
                    <div style={{ fontSize: 11, color: '#d97706', marginTop: 2 }}>{translate('portal','rank_pool_me')}</div>
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
                            {translate('portal','rank_framework_title')}
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '3px 0 0 0' }}>
                            {translate('portal','rank_framework_hint')}
                        </p>
                    </div>
                    <div style={{ fontSize: 11, fontWeight: 700, padding: '4px 10px', background: '#ecfdf5', color: '#047857', borderRadius: 20 }}>
                        {translate('portal','rank_combined_pool')}
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
                                    <span style={{ fontSize: 10, fontWeight: 800, color: '#94a3b8' }}>{translate('portal','rank_level',{n:String(idx+1)})}</span>
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
                                    <b>{translate('portal','rank_requirement')}:</b> {tier.requirement}
                                </div>
                            </div>

                            <div style={{ marginTop: 12, paddingTop: 8, borderTop: '1px solid #e2e8f0', fontSize: 11 }}>
                                <div style={{ color: '#006837', fontWeight: 600 }}>
                                    🎁 {tier.incentive}
                                </div>
                                <div style={{ color: '#1d4ed8', fontWeight: 600, marginTop: 2 }}>
                                    💎 {translate('portal','rank_royalty_label')}: {tier.royaltyShare}
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
                            placeholder={translate('portal','rank_search_placeholder')}
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
                            <option value="all">{translate('portal','rank_all_ranks',{count:String(ranks.length)})}</option>
                            <option value="mo">{translate('portal','net_rank_mo')}</option>
                            <option value="am">{translate('portal','net_rank_am')}</option>
                            <option value="sm">{translate('portal','net_rank_sm')}</option>
                            <option value="me">{translate('portal','net_rank_me')}</option>
                            <option value="dealer">{translate('portal','acct_role_dealer')}</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Roster Table */}
            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','col_partner')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','net_code')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','rank_col_current')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','net_lifetime_purchases')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','rank_col_monthly')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','rank_col_achieved')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal','rank_col_incentive')}</th>
                            <th style={{ padding: '12px 16px', textAlign: 'right' }}>{translate('portal','rank_col_audit')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredRanks.length > 0 ? (
                            filteredRanks.map(r => {
                                const rankCfg = rankHierarchy.find(x => x.key === r.current_rank) || rankHierarchy[0];
                                return (
                                    <tr key={r.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{r.name || translate('portal','rank_unnamed')}</div>
                                            <div style={{ fontSize: 11, color: '#64748b' }}>
                                                {r.phone || translate('portal','net_no_phone')} {r.district ? `• ${r.district}` : ''}
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
                                                    {r.one_time_incentive_paid ? translate('portal','rank_paid') : translate('portal','rank_pending')}
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
                                                <Eye size={12} /> {translate('portal','rank_inspect')}
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })
                        ) : (
                            <tr>
                                <td colSpan={8} style={{ textAlign: 'center', padding: '36px 0', color: '#94a3b8' }}>
                                    {translate('portal','rank_empty')}
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
                                    {translate('portal','net_code')}: <b>{auditingPartner.dealer_code}</b> • {translate('portal','acct_phone')}: {auditingPartner.phone || '—'}
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
                        {(() => {
                            const moTarget = Number(q.mo_target ?? 250000);
                            const progress = moTarget > 0
                                ? Math.min(100, (Number(auditingPartner.lifetime_purchase_total) / moTarget) * 100)
                                : 0;

                            return (
                        <div style={{ background: '#f8fafc', padding: 14, borderRadius: 8 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, fontWeight: 700 }}>
                                <span>{translate('portal','rank_mo_milestone',{amount:money(moTarget)})}</span>
                                <span style={{ color: '#006837' }}>
                                    {Math.round(progress)}%
                                </span>
                            </div>
                            <div style={{ width: '100%', height: 8, background: '#e2e8f0', borderRadius: 4, overflow: 'hidden', marginTop: 8 }}>
                                <div style={{
                                    height: '100%',
                                    width: `${progress}%`,
                                    background: '#006837',
                                    borderRadius: 4
                                }} />
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#64748b', marginTop: 6 }}>
                                <span>{translate('portal','rank_current')}: {money(auditingPartner.lifetime_purchase_total)}</span>
                                <span>{translate('portal','rank_target')}: {money(moTarget)}</span>
                            </div>
                        </div>
                            );
                        })()}

                        {/* AM Downline Qualification Progress */}
                        <div style={{ background: '#f8fafc', padding: 14, borderRadius: 8, marginTop: 12 }}>
                            <div style={{ fontSize: 12, fontWeight: 700, color: '#1d4ed8' }}>
                                {translate('portal','rank_am_downline')}
                            </div>
                            <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                                {translate('portal','rank_am_downline_hint')}
                            </div>
                        </div>

                        {/* Royalty Status */}
                        <div style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', padding: 12, borderRadius: 8, marginTop: 12, fontSize: 12 }}>
                            <div style={{ fontWeight: 700, color: '#047857' }}>{translate('portal','rank_pool_participation')}</div>
                            <div style={{ color: '#065f46', marginTop: 2 }}>
                                {auditingPartner.current_rank === 'dealer'
                                    ? translate('portal','rank_needs_mo',{amount:money(q.mo_target ?? 250000)})
                                    : translate('portal','rank_active_pool',{share:String((rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).royaltyShare)})}
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
                                {translate('portal','rank_close_audit')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
