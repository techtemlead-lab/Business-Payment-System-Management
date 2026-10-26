import React, { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { Trophy, ShieldCheck, AlertCircle, ChevronRight, Eye, CheckCircle2, TrendingUp, Sparkles } from 'lucide-react';
import { money, date } from './UI';
import { useT } from '../shared/i18n/t';

interface LegBreakdown {
    leg_user_id: number;
    leg_user_name?: string;
    raw_volume: number;
    capped_volume: number;
    excess_volume: number;
}

interface MilestoneRecord {
    id: number;
    user_id: number;
    milestone_type: 'bike' | 'car';
    qualifying_volume: number | string;
    target_volume: number | string;
    capped_leg_breakdown: LegBreakdown[] | string;
    achieved_at: string | null;
    is_claimed: boolean;
    reward_description: string | null;
    user?: {
        id: number;
        name: string;
        dealer_code: string;
        phone: string;
        district: string;
    };
}

interface CarBikeRewardsHubProps {
    bike_milestones: MilestoneRecord[];
    car_milestones: MilestoneRecord[];
    rule_params: any;
}

export default function CarBikeRewardsHub({ bike_milestones, car_milestones, rule_params }: CarBikeRewardsHubProps) {
    const translate = useT();
    const [activeTab, setActiveTab] = useState<'bike' | 'car'>('bike');
    const [selectedCandidate, setSelectedCandidate] = useState<MilestoneRecord | null>(null);

    const bikeTarget = Number(rule_params.bike_target || 5000000);
    const bikeCap = Number(rule_params.bike_leg_cap || 800000);
    const carTarget = Number(rule_params.car_target || 250000000);
    const carCap = Number(rule_params.car_leg_cap || 20000000);

    const currentMilestones = activeTab === 'bike' ? bike_milestones : car_milestones;
    const currentTarget = activeTab === 'bike' ? bikeTarget : carTarget;
    const currentCap = activeTab === 'bike' ? bikeCap : carCap;
    const rewardTitle = activeTab === 'bike' ? translate('portal', 'rw_title_bike') : translate('portal', 'rw_title_car');
    const rewardPrize = activeTab === 'bike' ? translate('portal', 'rw_prize_bike') : translate('portal', 'rw_prize_car');

    // Parse legs for selected candidate
    const legs: LegBreakdown[] = useMemo(() => {
        if (!selectedCandidate) return [];
        if (typeof selectedCandidate.capped_leg_breakdown === 'string') {
            try {
                return JSON.parse(selectedCandidate.capped_leg_breakdown);
            } catch {
                return [];
            }
        }
        return selectedCandidate.capped_leg_breakdown || [];
    }, [selectedCandidate]);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top Anti-Whale Architecture Banner */}
            <div style={{
                background: '#ffffff',
                border: '1.5px solid #006837',
                borderRadius: 12,
                padding: '20px 24px',
                boxShadow: '0 4px 14px rgba(0, 104, 55, 0.06)'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
                    <div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 700, color: '#047857' }}>
                            <ShieldCheck size={18} /> {translate('portal', 'rw_badge')}
                        </div>
                        <h2 style={{ fontSize: 20, fontWeight: 800, color: '#0f172a', margin: '6px 0 4px 0' }}>
                            {translate('portal', 'rw_heading')}
                        </h2>
                        <p style={{ fontSize: 13, color: '#64748b', maxWidth: 740, lineHeight: 1.5, margin: 0 }}>
                            {translate('portal', 'rw_intro')}
                        </p>
                    </div>

                    {/* Mode Toggle Switch */}
                    <div style={{ display: 'flex', background: '#f1f5f9', borderRadius: 8, padding: 4 }}>
                        <button
                            type="button"
                            onClick={() => { setActiveTab('bike'); setSelectedCandidate(null); }}
                            style={{
                                padding: '8px 16px',
                                fontSize: 13,
                                fontWeight: 700,
                                borderRadius: 6,
                                background: activeTab === 'bike' ? '#ffffff' : 'transparent',
                                color: activeTab === 'bike' ? '#006837' : '#64748b',
                                border: 'none',
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 6,
                                boxShadow: activeTab === 'bike' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none'
                            }}
                        >
                            {translate('portal', 'rw_tab_bike')}
                        </button>
                        <button
                            type="button"
                            onClick={() => { setActiveTab('car'); setSelectedCandidate(null); }}
                            style={{
                                padding: '8px 16px',
                                fontSize: 13,
                                fontWeight: 700,
                                borderRadius: 6,
                                background: activeTab === 'car' ? '#ffffff' : 'transparent',
                                color: activeTab === 'car' ? '#006837' : '#64748b',
                                border: 'none',
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 6,
                                boxShadow: activeTab === 'car' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none'
                            }}
                        >
                            {translate('portal', 'rw_tab_car')}
                        </button>
                    </div>
                </div>

                {/* Fund Rules Snapshot */}
                <div style={{
                    marginTop: 18,
                    paddingTop: 16,
                    borderTop: '1px solid #e2e8f0',
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                    gap: 14
                }}>
                    <div style={{ background: '#f8fafc', padding: '10px 14px', borderRadius: 8 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: '#64748b' }}>{translate('portal', 'rw_kpi_target')}</div>
                        <div style={{ fontSize: 18, fontWeight: 800, color: '#0f172a', marginTop: 2 }}>{money(currentTarget)}</div>
                        <div style={{ fontSize: 11, color: '#94a3b8' }}>{translate('portal', 'rw_kpi_target_hint')}</div>
                    </div>

                    <div style={{ background: '#f8fafc', padding: '10px 14px', borderRadius: 8 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: '#047857' }}>{translate('portal', 'rw_kpi_cap')}</div>
                        <div style={{ fontSize: 18, fontWeight: 800, color: '#006837', marginTop: 2 }}>{money(currentCap)}</div>
                        <div style={{ fontSize: 11, color: '#059669' }}>{translate('portal', 'rw_kpi_cap_hint')}</div>
                    </div>

                    <div style={{ background: '#f8fafc', padding: '10px 14px', borderRadius: 8 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: '#1d4ed8' }}>{translate('portal', 'rw_kpi_legs')}</div>
                        <div style={{ fontSize: 18, fontWeight: 800, color: '#1d4ed8', marginTop: 2 }}>
                            {translate('portal', 'rw_kpi_legs_value', { count: String(Math.ceil(currentTarget / currentCap)) })}
                        </div>
                        <div style={{ fontSize: 11, color: '#3b82f6' }}>{translate('portal', 'rw_kpi_legs_hint')}</div>
                    </div>

                    <div style={{ background: '#f0fdf4', border: '1px solid #a7f3d0', padding: '10px 14px', borderRadius: 8 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: '#047857' }}>{translate('portal', 'rw_kpi_reward')}</div>
                        <div style={{ fontSize: 14, fontWeight: 800, color: '#006837', marginTop: 2 }}>{rewardPrize}</div>
                    </div>
                </div>
            </div>

            {/* Candidate Progress Table */}
            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                <div style={{ padding: '16px 20px', borderBottom: '1px solid #e2e8f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <div>
                        <h3 style={{ fontSize: 15, fontWeight: 700, margin: 0, color: '#0f172a' }}>
                            {translate('portal', 'rw_leaderboard', { title: rewardTitle })}
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '2px 0 0 0' }}>
                            {translate('portal', 'rw_leaderboard_hint')}
                        </p>
                    </div>
                    <span style={{ fontSize: 12, fontWeight: 700, color: '#006837' }}>
                        {translate('portal', 'rw_registered', { count: String(currentMilestones.length) })}
                    </span>
                </div>

                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'rw_col_dealer')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'rw_col_code')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'rw_col_qual')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'rw_col_progress')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'rw_col_target')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'rw_col_status')}</th>
                            <th style={{ padding: '12px 16px', textAlign: 'right' }}>{translate('portal', 'rw_col_branches')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {currentMilestones.length > 0 ? (
                            currentMilestones.map(m => {
                                const qualVal = Number(m.qualifying_volume) || 0;
                                const pct = Math.min(100, (qualVal / currentTarget) * 100);
                                const isAchieved = !!m.achieved_at || qualVal >= currentTarget;
                                return (
                                    <tr key={m.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{m.user?.name || translate('portal', 'rw_partner')}</div>
                                            <div style={{ fontSize: 11, color: '#64748b' }}>
                                                {m.user?.phone || translate('portal', 'rw_no_phone')} {m.user?.district ? `• ${m.user.district}` : ''}
                                            </div>
                                        </td>
                                        <td style={{ padding: '12px 16px', fontWeight: 600 }}>{m.user?.dealer_code || '—'}</td>
                                        <td style={{ padding: '12px 16px', fontWeight: 800, color: '#006837' }}>
                                            {money(qualVal)}
                                        </td>
                                        <td style={{ padding: '12px 16px', width: 220 }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                <div style={{ flex: 1, height: 8, background: '#e2e8f0', borderRadius: 4, overflow: 'hidden' }}>
                                                    <div style={{
                                                        height: '100%',
                                                        width: `${pct}%`,
                                                        background: isAchieved ? '#059669' : '#006837',
                                                        borderRadius: 4
                                                    }} />
                                                </div>
                                                <span style={{ fontSize: 11, fontWeight: 700, color: isAchieved ? '#059669' : '#475569', minWidth: 42 }}>
                                                    {pct.toFixed(1)}%
                                                </span>
                                            </div>
                                        </td>
                                        <td style={{ padding: '12px 16px', color: '#64748b' }}>
                                            {money(currentTarget)}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <span style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 7px',
                                                borderRadius: 4,
                                                background: m.is_claimed ? '#ecfdf5' : isAchieved ? '#eff6ff' : '#f1f5f9',
                                                color: m.is_claimed ? '#047857' : isAchieved ? '#1d4ed8' : '#64748b',
                                                border: `1px solid ${m.is_claimed ? '#a7f3d0' : isAchieved ? '#bfdbfe' : '#cbd5e1'}`
                                            }}>
                                                {m.is_claimed ? translate('portal', 'rw_claimed') : isAchieved ? translate('portal', 'rw_achieved') : translate('portal', 'rw_in_progress')}
                                            </span>
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right' }}>
                                            <div style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
                                                {isAchieved && !m.is_claimed && (
                                                    <Link
                                                        href="/operations/reserve-payouts"
                                                        className="button small"
                                                        style={{ fontSize: 11, padding: '4px 10px' }}
                                                    >
                                                        {translate('portal', 'rw_record_payout')}
                                                    </Link>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedCandidate(m)}
                                                    style={{
                                                        fontSize: 11,
                                                        fontWeight: 600,
                                                        padding: '4px 10px',
                                                        background: '#f8fafc',
                                                        border: '1px solid #cbd5e1',
                                                        borderRadius: 4,
                                                        cursor: 'pointer',
                                                        display: 'inline-flex',
                                                        alignItems: 'center',
                                                        gap: 4
                                                    }}
                                                >
                                                    <Eye size={12} /> {translate('portal', 'rw_inspect')}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })
                        ) : (
                            <tr>
                                <td colSpan={7} style={{ textAlign: 'center', padding: '36px 0', color: '#94a3b8' }}>
                                    {translate('portal', 'rw_empty')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Anti-Whale Branch Inspection Modal */}
            {selectedCandidate && (
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
                        maxWidth: 620,
                        width: '100%',
                        padding: 24,
                        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1)',
                        maxHeight: '90vh',
                        overflowY: 'auto'
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                            <div>
                                <div style={{ fontSize: 11, fontWeight: 700, color: '#047857' }}>
                                    {translate('portal', 'rw_audit_badge')}
                                </div>
                                <h3 style={{ fontSize: 18, fontWeight: 800, margin: '4px 0 2px 0', color: '#0f172a' }}>
                                    {selectedCandidate.user?.name} ({selectedCandidate.user?.dealer_code})
                                </h3>
                                <div style={{ fontSize: 12, color: '#64748b' }}>
                                    {selectedCandidate.milestone_type === 'bike' ? translate('portal', 'rw_milestone_bike') : translate('portal', 'rw_milestone_car')}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedCandidate(null)}
                                style={{ background: 'none', border: 'none', fontSize: 18, color: '#94a3b8', cursor: 'pointer' }}
                            >
                                ✕
                            </button>
                        </div>

                        <div style={{
                            margin: '16px 0',
                            padding: 12,
                            background: '#f8fafc',
                            borderRadius: 8,
                            display: 'flex',
                            justifyContent: 'space-between',
                            fontSize: 12
                        }}>
                            <div>
                                <span style={{ color: '#64748b' }}>{translate('portal', 'rw_qual_vol')} </span>
                                <b style={{ color: '#006837' }}>{money(selectedCandidate.qualifying_volume)}</b>
                            </div>
                            <div>
                                <span style={{ color: '#64748b' }}>{translate('portal', 'rw_branch_cap')} </span>
                                <b>{money(currentCap)}</b>
                            </div>
                            <div>
                                <span style={{ color: '#64748b' }}>{translate('portal', 'rw_target')} </span>
                                <b>{money(currentTarget)}</b>
                            </div>
                        </div>

                        {/* Frontline Legs List */}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            <div style={{ fontSize: 12, fontWeight: 700, color: '#475569', textTransform: 'uppercase' }}>
                                {translate('portal', 'rw_legs_heading', { count: String(legs.length) })}
                            </div>

                            {legs.length > 0 ? (
                                legs.map((leg, idx) => {
                                    const raw = Number(leg.raw_volume) || 0;
                                    const capped = Number(leg.capped_volume) || 0;
                                    const excess = Number(leg.excess_volume) || 0;
                                    const isCapped = excess > 0;
                                    const capPct = Math.min(100, (capped / currentCap) * 100);

                                    return (
                                        <div key={idx} style={{
                                            border: `1px solid ${isCapped ? '#fde68a' : '#e2e8f0'}`,
                                            background: isCapped ? '#fffbeb' : '#ffffff',
                                            borderRadius: 8,
                                            padding: 12
                                        }}>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                <div style={{ fontWeight: 600, fontSize: 13, color: '#0f172a' }}>
                                                    {translate('portal', 'rw_branch_n', { n: String(idx + 1), name: leg.leg_user_name || translate('portal', 'rw_partner_id', { id: String(leg.leg_user_id) }) })}
                                                </div>
                                                <div style={{ textAlign: 'right' }}>
                                                    <span style={{ fontSize: 12, fontWeight: 800, color: '#006837' }}>
                                                        {money(capped)}
                                                    </span>
                                                    <span style={{ fontSize: 11, color: '#64748b' }}> qualifying</span>
                                                </div>
                                            </div>

                                            {/* Stacked Progress Bar */}
                                            <div style={{ width: '100%', height: 10, background: '#e2e8f0', borderRadius: 5, overflow: 'hidden', marginTop: 8, display: 'flex' }}>
                                                <div style={{
                                                    width: `${capPct}%`,
                                                    background: '#006837',
                                                    height: '100%'
                                                }} />
                                                {isCapped && (
                                                    <div style={{
                                                        width: `${Math.min(100 - capPct, 25)}%`,
                                                        background: '#d97706',
                                                        height: '100%',
                                                        opacity: 0.8
                                                    }} />
                                                )}
                                            </div>

                                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#64748b', marginTop: 6 }}>
                                                <span>{translate('portal', 'rw_raw_leg')} <b>{money(raw)}</b></span>
                                                {isCapped ? (
                                                    <span style={{ color: '#b45309', fontWeight: 600 }}>
                                                        {translate('portal', 'rw_truncated', { excess: money(excess), cap: money(currentCap) })}
                                                    </span>
                                                ) : (
                                                    <span style={{ color: '#047857' }}>{translate('portal', 'rw_full_qualifies')}</span>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })
                            ) : (
                                <div style={{ textAlign: 'center', padding: '24px 0', color: '#94a3b8', fontSize: 13 }}>
                                    {translate('portal', 'rw_no_legs')}
                                </div>
                            )}
                        </div>

                        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
                            <button
                                type="button"
                                onClick={() => setSelectedCandidate(null)}
                                style={{
                                    padding: '8px 18px',
                                    background: '#006837',
                                    color: '#ffffff',
                                    border: 'none',
                                    borderRadius: 6,
                                    fontWeight: 700,
                                    fontSize: 12,
                                    cursor: 'pointer'
                                }}
                            >
                                {translate('portal', 'rw_close')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
