import React, { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { ShieldCheck, Eye } from 'lucide-react';
import { money, Table } from './UI';
import { useT } from '../shared/i18n/t';

/** A positive policy amount, or null when the policy does not set one. */
function policyAmount(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

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

    const notSet = translate('portal', 'policy_value_not_set');
    const moneyOrNotSet = (amount: number | null) => amount === null ? notSet : money(amount);
    const bikeTarget = policyAmount(rule_params?.bike_target);
    const bikeCap = policyAmount(rule_params?.bike_leg_cap);
    const carTarget = policyAmount(rule_params?.car_target);
    const carCap = policyAmount(rule_params?.car_leg_cap);

    const currentMilestones = activeTab === 'bike' ? bike_milestones : car_milestones;
    const currentTarget = activeTab === 'bike' ? bikeTarget : carTarget;
    const currentCap = activeTab === 'bike' ? bikeCap : carCap;
    const progressPct = (m: MilestoneRecord) => currentTarget === null
        ? null
        : Math.min(100, ((Number(m.qualifying_volume) || 0) / currentTarget) * 100);
    const reachedTarget = (m: MilestoneRecord) => !!m.achieved_at
        || (currentTarget !== null && (Number(m.qualifying_volume) || 0) >= currentTarget);
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
                            {translate('portal', 'rw_intro_live', { bike_cap: moneyOrNotSet(bikeCap), car_cap: moneyOrNotSet(carCap) })}
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
                            {translate('portal', 'rw_title_bike')}
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
                            {translate('portal', 'rw_title_car')}
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
                        <div style={{ fontSize: 18, fontWeight: 800, color: currentTarget === null ? '#b45309' : '#0f172a', marginTop: 2 }}>{moneyOrNotSet(currentTarget)}</div>
                        <div style={{ fontSize: 11, color: '#94a3b8' }}>{translate('portal', 'rw_kpi_target_hint')}</div>
                    </div>

                    <div style={{ background: '#f8fafc', padding: '10px 14px', borderRadius: 8 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: '#047857' }}>{translate('portal', 'rw_kpi_cap')}</div>
                        <div style={{ fontSize: 18, fontWeight: 800, color: currentCap === null ? '#b45309' : '#006837', marginTop: 2 }}>{moneyOrNotSet(currentCap)}</div>
                        <div style={{ fontSize: 11, color: '#059669' }}>{translate('portal', 'rw_kpi_cap_hint')}</div>
                    </div>

                    <div style={{ background: '#f8fafc', padding: '10px 14px', borderRadius: 8 }}>
                        <div style={{ fontSize: 11, fontWeight: 700, color: '#1d4ed8' }}>{translate('portal', 'rw_kpi_legs')}</div>
                        <div style={{ fontSize: 18, fontWeight: 800, color: '#1d4ed8', marginTop: 2 }}>
                            {currentTarget === null || currentCap === null
                                ? notSet
                                : translate('portal', 'rw_kpi_legs_value', { count: String(Math.ceil(currentTarget / currentCap)) })}
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

                <Table
                    columns={[
                        {
                            key: 'dealer',
                            header: translate('portal', 'rw_col_dealer'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => m.user?.name || '',
                            render: (m: MilestoneRecord) => (
                                <div>
                                    <div style={{ fontWeight: 600, color: '#0f172a' }}>{m.user?.name || translate('portal', 'rw_partner')}</div>
                                    <div style={{ fontSize: 11, color: '#64748b' }}>
                                        {m.user?.phone || translate('portal', 'rw_no_phone')} {m.user?.district ? `• ${m.user.district}` : ''}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'dealer_code',
                            header: translate('portal', 'rw_col_code'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => m.user?.dealer_code || '',
                            render: (m: MilestoneRecord) => <span style={{ fontWeight: 600 }}>{m.user?.dealer_code || '—'}</span>,
                        },
                        {
                            key: 'qualifying_volume',
                            header: translate('portal', 'rw_col_qual'),
                            sortable: true,
                            align: 'right',
                            sortValue: (m: MilestoneRecord) => Number(m.qualifying_volume) || 0,
                            render: (m: MilestoneRecord) => (
                                <span style={{ fontWeight: 800, color: '#006837' }}>
                                    {money(Number(m.qualifying_volume) || 0)}
                                </span>
                            ),
                        },
                        {
                            key: 'progress',
                            header: translate('portal', 'rw_col_progress'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => progressPct(m) ?? -1,
                            render: (m: MilestoneRecord) => {
                                const progress = progressPct(m);
                                if (progress === null) {
                                    return <span style={{ fontSize: 11, color: '#b45309', fontWeight: 600 }}>{notSet}</span>;
                                }
                                const pct = progress;
                                const isAchieved = reachedTarget(m);
                                return (
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 160 }}>
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
                                );
                            },
                        },
                        {
                            key: 'target_volume',
                            header: translate('portal', 'rw_col_target'),
                            sortable: true,
                            align: 'right',
                            sortValue: () => currentTarget ?? -1,
                            render: () => <span style={{ color: '#64748b' }}>{moneyOrNotSet(currentTarget)}</span>,
                        },
                        {
                            key: 'status',
                            header: translate('portal', 'rw_col_status'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => m.is_claimed ? 'claimed' : reachedTarget(m) ? 'achieved' : 'in_progress',
                            render: (m: MilestoneRecord) => {
                                const isAchieved = reachedTarget(m);
                                return (
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
                                );
                            },
                        },
                        {
                            key: 'actions',
                            header: translate('portal', 'rw_col_branches'),
                            align: 'right',
                            render: (m: MilestoneRecord) => {
                                const isAchieved = reachedTarget(m);
                                return (
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
                                );
                            },
                        },
                    ]}
                    data={currentMilestones}
                    searchPlaceholder={translate('portal', 'search')}
                    pageSize={15}
                    emptyMessage={translate('portal', 'rw_empty')}
                />
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
                                    {selectedCandidate.milestone_type === 'bike' ? translate('portal', 'rw_title_bike') : translate('portal', 'rw_title_car')}
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
                                <b>{moneyOrNotSet(currentCap)}</b>
                            </div>
                            <div>
                                <span style={{ color: '#64748b' }}>{translate('portal', 'rw_target')} </span>
                                <b>{moneyOrNotSet(currentTarget)}</b>
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
                                    const capPct = currentCap === null ? 0 : Math.min(100, (capped / currentCap) * 100);

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
                                                    <span style={{ fontSize: 11, color: '#64748b' }}> {translate('portal', 'rw_qualifying')}</span>
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
                                                        {translate('portal', 'rw_truncated', { excess: money(excess), cap: moneyOrNotSet(currentCap) })}
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
