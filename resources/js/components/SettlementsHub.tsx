import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Calendar, CheckCircle2, Clock, DollarSign, Award, ShieldAlert,
    ArrowUpRight, Play, RefreshCw, AlertCircle, FileText, ChevronRight, UserCheck
} from 'lucide-react';
import { money, date } from './UI';
import { useT } from '../shared/i18n/t';

interface SettlementState {
    current_month: string;
    salary_pool_pending: number;
    dealer_salary_pool_pending: number;
    sub_dealer_salary_pending: number;
    salary_pool_allocations_count: number;
    qualified_salary_dealers_count: number;
    qualified_salary_dealers: Array<{
        id: number;
        user_id: number;
        name: string;
        dealer_code: string;
        monthly_volume: number;
    }>;
    rank_pool_pending: number;
    rank_pool_allocations_count: number;
    qualified_rank_leaders_count: number;
    qualified_rank_leaders: Array<{
        id: number;
        user_id: number;
        name: string;
        dealer_code: string;
        rank: string;
        paid_as_rank: string;
        is_qualified: boolean;
        in_grace_period: boolean;
        monthly_volume: number;
        one_time_incentive_paid: boolean;
    }>;
    maturing_count: number;
    maturing_amount: number;
    settled_history: Array<{
        id: string;
        event_key: string;
        description: string;
        created_at: string;
    }>;
}

export default function SettlementsHub({ state }: { state: SettlementState }) {
    const translate = useT();
    const { flash, financeActive, staffActions } = usePage<any>().props;
    const [selectedMonth, setSelectedMonth] = useState(state.current_month || new Date().toISOString().slice(0, 7));
    const [processingSalary, setProcessingSalary] = useState(false);
    const [processingRank, setProcessingRank] = useState(false);
    const [processingMatured, setProcessingMatured] = useState(false);
    const [claimingIncentive, setClaimingIncentive] = useState<number | null>(null);

    const handleSettleSalary = () => {
        if (!confirm(translate('portal', 'st_confirm_salary', { month: selectedMonth }))) {
            return;
        }
        setProcessingSalary(true);
        router.post('/commands/commissions/settle-salary', { period_month: selectedMonth, request_key: crypto.randomUUID() }, {
            onFinish: () => setProcessingSalary(false)
        });
    };

    const handleSettleRank = () => {
        if (!confirm(translate('portal', 'st_confirm_rank', { month: selectedMonth }))) {
            return;
        }
        setProcessingRank(true);
        router.post('/commands/commissions/settle-rank-royalty', { period_month: selectedMonth, request_key: crypto.randomUUID() }, {
            onFinish: () => setProcessingRank(false)
        });
    };

    const handleReleaseMatured = () => {
        setProcessingMatured(true);
        router.post('/commands/commissions/release-matured', { request_key: crypto.randomUUID() }, {
            onFinish: () => setProcessingMatured(false)
        });
    };

    const handleClaimIncentive = (userId: number, leaderName: string, rank: string) => {
        if (!confirm(translate('portal', 'st_confirm_incentive', { name: leaderName, rank: rank.toUpperCase() }))) {
            return;
        }
        setClaimingIncentive(userId);
        router.post(`/commands/commissions/claim-incentive/${userId}`, { request_key: crypto.randomUUID() }, {
            onFinish: () => setClaimingIncentive(null)
        });
    };

    return (
        <div className="settlements-hub" style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
            {/* Top Operational Bar */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '20px 24px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: '16px',
                boxShadow: '0 1px 3px rgba(0,0,0,0.05)'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
                    <div style={{
                        width: '44px',
                        height: '44px',
                        borderRadius: '10px',
                        background: '#eff6ff',
                        color: '#2563eb',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center'
                    }}>
                        <Calendar size={24} />
                    </div>
                    <div>
                        <h2 style={{ fontSize: '18px', fontWeight: 700, margin: 0, color: '#0f172a' }}>{translate('portal', 'st_heading')}</h2>
                        <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_sub')}</p>
                    </div>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <label style={{ fontSize: '13px', fontWeight: 600, color: '#475569', display: 'flex', alignItems: 'center', gap: '8px' }}>
                        {translate('portal', 'st_period')}
                        <input
                            type="month"
                            value={selectedMonth}
                            onChange={e => {setSelectedMonth(e.target.value);router.get('/settlements',{period_month:e.target.value},{preserveScroll:true});}}
                            style={{
                                padding: '6px 12px',
                                border: '1px solid #cbd5e1',
                                borderRadius: '6px',
                                fontSize: '13px',
                                fontWeight: 600,
                                color: '#1e293b'
                            }}
                        />
                    </label>
                </div>
            </div>

            {/* Cooling-off Maturation Banner */}
            <div style={{
                background: '#f8fafc',
                border: '1px solid #cbd5e1',
                borderRadius: '12px',
                padding: '18px 24px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: '16px'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <Clock size={22} color="#0284c7" />
                    <div>
                        <div style={{ fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>
                            {translate('portal', 'st_cooling_title', { amount: money(state.maturing_amount), count: String(state.maturing_count) })}
                        </div>
                        <div style={{ fontSize: '12px', color: '#64748b' }}>
                            {translate('portal', 'st_cooling_hint')}
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    className="button small"
                    disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || processingMatured || state.maturing_count === 0}
                    onClick={handleReleaseMatured}
                    style={{
                        background: '#0284c7',
                        color: '#ffffff',
                        border: 'none',
                        padding: '8px 16px',
                        borderRadius: '6px',
                        fontWeight: 600,
                        cursor: state.maturing_count === 0 ? 'not-allowed' : 'pointer',
                        opacity: state.maturing_count === 0 ? 0.6 : 1,
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '6px'
                    }}
                >
                    <RefreshCw size={15} className={processingMatured ? 'spin' : ''} />
                    {processingMatured ? translate('portal', 'st_releasing') : translate('portal', 'st_release')}
                </button>
            </div>

            {/* Two Main Settlement Cards: Salary Pool & Rank Royalty Pool */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: '20px' }}>

                {/* 1. Dealer Monthly Salary Pool (2%) */}
                <div style={{
                    background: '#ffffff',
                    border: '1px solid #e2e8f0',
                    borderRadius: '12px',
                    padding: '22px',
                    boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between'
                }}>
                    <div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px' }}>
                            <div>
                                <span style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.05em', color: '#10b981', textTransform: 'uppercase' }}>
                                    {translate('portal', 'st_salary_badge')}
                                </span>
                                <h3 style={{ fontSize: '17px', fontWeight: 700, margin: '4px 0 0', color: '#0f172a' }}>
                                    {translate('portal', 'st_salary_title')}
                                </h3>
                            </div>
                            <span style={{
                                background: '#ecfdf5',
                                color: '#059669',
                                padding: '4px 10px',
                                borderRadius: '20px',
                                fontSize: '12px',
                                fontWeight: 700
                            }}>
                                {translate('portal', 'st_policy_rates')}
                            </span>
                        </div>

                        <div style={{
                            background: '#f8fafc',
                            border: '1px solid #f1f5f9',
                            borderRadius: '8px',
                            padding: '14px',
                            marginBottom: '16px'
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_dealer_pool')}</span>
                                <b style={{ fontSize: '15px', color: '#0f172a' }}>{money(state.dealer_salary_pool_pending)}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_dealers_threshold')}</span>
                                <b style={{ fontSize: '14px', color: '#10b981' }}>{translate('portal', 'st_dealers_count', { count: String(state.qualified_salary_dealers_count) })}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_sub_reserved')}</span>
                                <b style={{ fontSize: '14px', color: '#2563eb' }}>
                                    {money(state.sub_dealer_salary_pending)}
                                </b>
                            </div>
                        </div>

                        <div style={{ fontSize: '12px', color: '#64748b', lineHeight: 1.5, marginBottom: '16px' }}>
                            {translate('portal', 'st_salary_hint')}
                        </div>

                        {state.qualified_salary_dealers.length > 0 && (
                            <div style={{ marginBottom: '16px', maxHeight: '140px', overflowY: 'auto', border: '1px solid #e2e8f0', borderRadius: '6px' }}>
                                <table style={{ width: '100%', fontSize: '12px', borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #e2e8f0' }}>
                                            <th style={{ padding: '6px 10px' }}>{translate('portal', 'st_col_dealer')}</th>
                                            <th style={{ padding: '6px 10px' }}>{translate('portal', 'st_col_code')}</th>
                                            <th style={{ padding: '6px 10px', textAlign: 'right' }}>{translate('portal', 'st_col_volume')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {state.qualified_salary_dealers.map(d => (
                                            <tr key={d.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                                <td style={{ padding: '6px 10px', fontWeight: 600 }}>{d.name}</td>
                                                <td style={{ padding: '6px 10px', color: '#64748b' }}>{d.dealer_code}</td>
                                                <td style={{ padding: '6px 10px', textAlign: 'right', fontWeight: 600, color: '#10b981' }}>{money(d.monthly_volume)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    <button
                        type="button"
                        disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || processingSalary}
                        onClick={handleSettleSalary}
                        style={{
                            width: '100%',
                            background: '#10b981',
                            color: '#ffffff',
                            border: 'none',
                            padding: '10px 18px',
                            borderRadius: '8px',
                            fontSize: '14px',
                            fontWeight: 700,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: '8px'
                        }}
                    >
                        <Play size={16} fill="#ffffff" />
                        {processingSalary ? translate('portal', 'st_executing') : translate('portal', 'st_settle_salary', { month: selectedMonth })}
                    </button>
                </div>

                {/* 2. Leadership Rank Royalty Pool (4%) */}
                <div style={{
                    background: '#ffffff',
                    border: '1px solid #e2e8f0',
                    borderRadius: '12px',
                    padding: '22px',
                    boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between'
                }}>
                    <div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px' }}>
                            <div>
                                <span style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.05em', color: '#8b5cf6', textTransform: 'uppercase' }}>
                                    {translate('portal', 'st_rank_badge')}
                                </span>
                                <h3 style={{ fontSize: '17px', fontWeight: 700, margin: '4px 0 0', color: '#0f172a' }}>
                                    {translate('portal', 'st_rank_title')}
                                </h3>
                            </div>
                            <span style={{
                                background: '#f5f3ff',
                                color: '#7c3aed',
                                padding: '4px 10px',
                                borderRadius: '20px',
                                fontSize: '12px',
                                fontWeight: 700
                            }}>
                                {translate('portal', 'st_policy_rates')}
                            </span>
                        </div>

                        <div style={{
                            background: '#f8fafc',
                            border: '1px solid #f1f5f9',
                            borderRadius: '8px',
                            padding: '14px',
                            marginBottom: '16px'
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_royalty_pool')}</span>
                                <b style={{ fontSize: '15px', color: '#0f172a' }}>{money(state.rank_pool_pending)}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_tier_alloc')}</span>
                                <b style={{ fontSize: '14px', color: '#7c3aed' }}>{translate('portal', 'st_policy_weights')}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>{translate('portal', 'st_active_leaders')}</span>
                                <b style={{ fontSize: '14px', color: '#0f172a' }}>{translate('portal', 'st_leaders_count', { count: String(state.qualified_rank_leaders_count) })}</b>
                            </div>
                        </div>

                        <div style={{ fontSize: '12px', color: '#64748b', lineHeight: 1.5, marginBottom: '16px' }}>
                            {translate('portal', 'st_rank_hint')}
                        </div>

                        {state.qualified_rank_leaders.length > 0 && (
                            <div style={{ marginBottom: '16px', maxHeight: '140px', overflowY: 'auto', border: '1px solid #e2e8f0', borderRadius: '6px' }}>
                                <table style={{ width: '100%', fontSize: '12px', borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #e2e8f0' }}>
                                            <th style={{ padding: '6px 10px' }}>{translate('portal', 'st_col_leader')}</th>
                                            <th style={{ padding: '6px 10px' }}>{translate('portal', 'st_col_rank')}</th>
                                            <th style={{ padding: '6px 10px' }}>{translate('portal', 'st_col_status')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {state.qualified_rank_leaders.map(l => (
                                            <tr key={l.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                                <td style={{ padding: '6px 10px', fontWeight: 600 }}>{l.name}</td>
                                                <td style={{ padding: '6px 10px', textTransform: 'uppercase', fontWeight: 700, color: '#7c3aed' }}>{l.rank}</td>
                                                <td style={{ padding: '6px 10px' }}>
                                                    {l.is_qualified ? (
                                                        <span style={{ color: '#059669', fontWeight: 600 }}>{translate('portal', 'st_active')}</span>
                                                    ) : l.in_grace_period ? (
                                                        <span style={{ color: '#d97706', fontWeight: 600 }}>{translate('portal', 'st_grace')}</span>
                                                    ) : (
                                                        <span style={{ color: '#ef4444', fontWeight: 600 }}>{translate('portal', 'st_demoted')}</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    <button
                        type="button"
                        disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || processingRank}
                        onClick={handleSettleRank}
                        style={{
                            width: '100%',
                            background: '#7c3aed',
                            color: '#ffffff',
                            border: 'none',
                            padding: '10px 18px',
                            borderRadius: '8px',
                            fontSize: '14px',
                            fontWeight: 700,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: '8px'
                        }}
                    >
                        <Play size={16} fill="#ffffff" />
                        {processingRank ? translate('portal', 'st_executing') : translate('portal', 'st_settle_rank', { month: selectedMonth })}
                    </button>
                </div>
            </div>

            {/* One-Time Rank Incentives Table */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '24px',
                boxShadow: '0 1px 3px rgba(0,0,0,0.04)'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                    <div>
                        <h3 style={{ fontSize: '17px', fontWeight: 700, margin: 0, color: '#0f172a' }}>
                            {translate('portal', 'st_incentive_title')}
                        </h3>
                        <p style={{ margin: '4px 0 0', fontSize: '13px', color: '#64748b' }}>
                            {translate('portal', 'st_incentive_hint')}
                        </p>
                    </div>
                </div>

                <div style={{ overflowX: 'auto', border: '1px solid #e2e8f0', borderRadius: '8px' }}>
                    <table style={{ width: '100%', fontSize: '13px', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', textAlign: 'left', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_leader')}</th>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_dcode')}</th>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_achieved')}</th>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_incentive')}</th>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_claim')}</th>
                                <th style={{ padding: '10px 14px', textAlign: 'right' }}>{translate('portal', 'st_col_action')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state.qualified_rank_leaders.length === 0 ? (
                                <tr>
                                    <td colSpan={6} style={{ padding: '24px', textAlign: 'center', color: '#94a3b8' }}>
                                        {translate('portal', 'st_no_incentive')}
                                    </td>
                                </tr>
                            ) : (
                                state.qualified_rank_leaders.map(l => {
                                    const incentiveAmounts: Record<string, number> = {
                                        mo: 5000,
                                        am: 15000,
                                        sm: 50000,
                                        me: 200000
                                    };
                                    const amount = incentiveAmounts[l.rank] || 5000;
                                    return (
                                        <tr key={l.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                            <td style={{ padding: '10px 14px', fontWeight: 600 }}>{l.name}</td>
                                            <td style={{ padding: '10px 14px', color: '#64748b' }}>{l.dealer_code}</td>
                                            <td style={{ padding: '10px 14px' }}>
                                                <span style={{
                                                    background: '#f1f5f9',
                                                    color: '#334155',
                                                    padding: '3px 8px',
                                                    borderRadius: '4px',
                                                    fontWeight: 700,
                                                    fontSize: '11px',
                                                    textTransform: 'uppercase'
                                                }}>
                                                    {l.rank}
                                                </span>
                                            </td>
                                            <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>
                                                {money(amount)}
                                            </td>
                                            <td style={{ padding: '10px 14px' }}>
                                                {l.one_time_incentive_paid ? (
                                                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', color: '#059669', fontWeight: 600, fontSize: '12px' }}>
                                                        <CheckCircle2 size={14} /> {translate('portal', 'st_paid')}
                                                    </span>
                                                ) : (
                                                    <span style={{ color: '#d97706', fontWeight: 600, fontSize: '12px' }}>
                                                        {translate('portal', 'st_pending_claim')}
                                                    </span>
                                                )}
                                            </td>
                                            <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                                                {l.one_time_incentive_paid ? (
                                                    <span style={{ color: '#94a3b8', fontSize: '12px' }}>{translate('portal', 'st_settled')}</span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="button small"
                                                        disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || claimingIncentive === l.user_id}
                                                        onClick={() => handleClaimIncentive(l.user_id, l.name, l.rank)}
                                                        style={{
                                                            background: '#0f172a',
                                                            color: '#ffffff',
                                                            padding: '4px 10px',
                                                            fontSize: '11px',
                                                            fontWeight: 600,
                                                            borderRadius: '4px',
                                                            cursor: 'pointer'
                                                        }}
                                                    >
                                                        {claimingIncentive === l.user_id ? translate('portal', 'st_claiming') : translate('portal', 'st_approve_pay')}
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Settled Journal Audit Trail */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '24px',
                boxShadow: '0 1px 3px rgba(0,0,0,0.04)'
            }}>
                <h3 style={{ fontSize: '17px', fontWeight: 700, margin: '0 0 14px', color: '#0f172a' }}>
                    {translate('portal', 'st_journal_title')}
                </h3>
                <div style={{ overflowX: 'auto', border: '1px solid #e2e8f0', borderRadius: '8px' }}>
                    <table style={{ width: '100%', fontSize: '13px', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', textAlign: 'left', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_date')}</th>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_event')}</th>
                                <th style={{ padding: '10px 14px' }}>{translate('portal', 'st_col_desc')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state.settled_history.length === 0 ? (
                                <tr>
                                    <td colSpan={3} style={{ padding: '20px', textAlign: 'center', color: '#94a3b8' }}>
                                        {translate('portal', 'st_no_journal')}
                                    </td>
                                </tr>
                            ) : (
                                state.settled_history.map(entry => (
                                    <tr key={entry.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '10px 14px', color: '#64748b' }}>{date(entry.created_at)}</td>
                                        <td style={{ padding: '10px 14px', fontFamily: 'monospace', fontWeight: 600, color: '#2563eb' }}>{entry.event_key}</td>
                                        <td style={{ padding: '10px 14px', color: '#0f172a' }}>{entry.description}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
