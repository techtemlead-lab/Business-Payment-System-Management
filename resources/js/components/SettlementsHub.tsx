import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Calendar, CheckCircle2, Clock, DollarSign, Award, ShieldAlert,
    ArrowUpRight, Play, RefreshCw, AlertCircle, FileText, ChevronRight, UserCheck
} from 'lucide-react';
import { money, date } from './UI';

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
    const { flash, financeActive, staffActions } = usePage<any>().props;
    const [selectedMonth, setSelectedMonth] = useState(state.current_month || new Date().toISOString().slice(0, 7));
    const [processingSalary, setProcessingSalary] = useState(false);
    const [processingRank, setProcessingRank] = useState(false);
    const [processingMatured, setProcessingMatured] = useState(false);
    const [claimingIncentive, setClaimingIncentive] = useState<number | null>(null);

    const handleSettleSalary = () => {
        if (!confirm(`Are you sure you want to finalize and distribute dealer and sub-dealer salary allocations for ${selectedMonth}? This credits qualifying earnings to available balances.`)) {
            return;
        }
        setProcessingSalary(true);
        router.post('/commands/commissions/settle-salary', { period_month: selectedMonth }, {
            onFinish: () => setProcessingSalary(false)
        });
    };

    const handleSettleRank = () => {
        if (!confirm(`Are you sure you want to finalize and distribute the leadership royalty pool for ${selectedMonth}? Each source order's captured policy determines the shares and eligible MO, AM, SM and ME leaders.`)) {
            return;
        }
        setProcessingRank(true);
        router.post('/commands/commissions/settle-rank-royalty', { period_month: selectedMonth }, {
            onFinish: () => setProcessingRank(false)
        });
    };

    const handleReleaseMatured = () => {
        setProcessingMatured(true);
        router.post('/commands/commissions/release-matured', {}, {
            onFinish: () => setProcessingMatured(false)
        });
    };

    const handleClaimIncentive = (userId: number, leaderName: string, rank: string) => {
        if (!confirm(`Confirm payout of one-time career milestone incentive for ${leaderName} (${rank.toUpperCase()})?`)) {
            return;
        }
        setClaimingIncentive(userId);
        router.post(`/commands/commissions/claim-incentive/${userId}`, {}, {
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
                        <h2 style={{ fontSize: '18px', fontWeight: 700, margin: 0, color: '#0f172a' }}>Month-End Settlements & Pipeline</h2>
                        <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>Finance-controlled month closing, cooling-off releases and funded leadership incentive claims.</p>
                    </div>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <label style={{ fontSize: '13px', fontWeight: 600, color: '#475569', display: 'flex', alignItems: 'center', gap: '8px' }}>
                        Period Month:
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
                            Cooling-Off Maturing Funds: <span style={{ color: '#0284c7' }}>{money(state.maturing_amount)}</span> ({state.maturing_count} allocations)
                        </div>
                        <div style={{ fontSize: '12px', color: '#64748b' }}>
                            Commissions held during statutory return cooling-off period before releasing into available balance.
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
                    {processingMatured ? 'Releasing…' : 'Release Matured Commissions'}
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
                                    Dealer Pool + Sub-dealer Salary
                                </span>
                                <h3 style={{ fontSize: '17px', fontWeight: 700, margin: '4px 0 0', color: '#0f172a' }}>
                                    Dealer Monthly Salary Pool
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
                                Captured policy rates
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
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Dealer Salary Pool:</span>
                                <b style={{ fontSize: '15px', color: '#0f172a' }}>{money(state.dealer_salary_pool_pending)}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Dealers Meeting Current Threshold:</span>
                                <b style={{ fontSize: '14px', color: '#10b981' }}>{state.qualified_salary_dealers_count} dealers</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Sub-dealer Salary Reserved:</span>
                                <b style={{ fontSize: '14px', color: '#2563eb' }}>
                                    {money(state.sub_dealer_salary_pending)}
                                </b>
                            </div>
                        </div>

                        <div style={{ fontSize: '12px', color: '#64748b', lineHeight: 1.5, marginBottom: '16px' }}>
                            The dealer list uses the current qualification threshold and selected month's purchases. Actual settlement evaluates each source order's captured policy and eligibility. Sub-dealer salary is credited to its own purchaser separately.
                        </div>

                        {state.qualified_salary_dealers.length > 0 && (
                            <div style={{ marginBottom: '16px', maxHeight: '140px', overflowY: 'auto', border: '1px solid #e2e8f0', borderRadius: '6px' }}>
                                <table style={{ width: '100%', fontSize: '12px', borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #e2e8f0' }}>
                                            <th style={{ padding: '6px 10px' }}>Dealer</th>
                                            <th style={{ padding: '6px 10px' }}>Code</th>
                                            <th style={{ padding: '6px 10px', textAlign: 'right' }}>Month Volume</th>
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
                        {processingSalary ? 'Executing Settlement…' : `Finalize & Settle Salary (${selectedMonth})`}
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
                                    4% Leadership Pool
                                </span>
                                <h3 style={{ fontSize: '17px', fontWeight: 700, margin: '4px 0 0', color: '#0f172a' }}>
                                    Rank Royalty Distribution
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
                                Captured policy rates
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
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Total Royalty Pool:</span>
                                <b style={{ fontSize: '15px', color: '#0f172a' }}>{money(state.rank_pool_pending)}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Tier Allocation:</span>
                                <b style={{ fontSize: '14px', color: '#7c3aed' }}>Source policy weights</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Active Qualified Leaders:</span>
                                <b style={{ fontSize: '14px', color: '#0f172a' }}>{state.qualified_rank_leaders_count} leaders</b>
                            </div>
                        </div>

                        <div style={{ fontSize: '12px', color: '#64748b', lineHeight: 1.5, marginBottom: '16px' }}>
                            Leadership tiers: <b>MO</b>, <b>AM</b>, <b>SM</b> and <b>ME</b>. The list uses the current maintenance policy; settlement uses each source order's captured weights and qualification rules, including applicable grace periods.
                        </div>

                        {state.qualified_rank_leaders.length > 0 && (
                            <div style={{ marginBottom: '16px', maxHeight: '140px', overflowY: 'auto', border: '1px solid #e2e8f0', borderRadius: '6px' }}>
                                <table style={{ width: '100%', fontSize: '12px', borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #e2e8f0' }}>
                                            <th style={{ padding: '6px 10px' }}>Leader</th>
                                            <th style={{ padding: '6px 10px' }}>Rank</th>
                                            <th style={{ padding: '6px 10px' }}>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {state.qualified_rank_leaders.map(l => (
                                            <tr key={l.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                                <td style={{ padding: '6px 10px', fontWeight: 600 }}>{l.name}</td>
                                                <td style={{ padding: '6px 10px', textTransform: 'uppercase', fontWeight: 700, color: '#7c3aed' }}>{l.rank}</td>
                                                <td style={{ padding: '6px 10px' }}>
                                                    {l.is_qualified ? (
                                                        <span style={{ color: '#059669', fontWeight: 600 }}>Active</span>
                                                    ) : l.in_grace_period ? (
                                                        <span style={{ color: '#d97706', fontWeight: 600 }}>Grace Period</span>
                                                    ) : (
                                                        <span style={{ color: '#ef4444', fontWeight: 600 }}>Demoted</span>
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
                        {processingRank ? 'Executing Settlement…' : `Finalize & Settle Rank Royalty (${selectedMonth})`}
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
                            One-Time Career Milestone Incentives (2% Pool)
                        </h3>
                        <p style={{ margin: '4px 0 0', fontSize: '13px', color: '#64748b' }}>
                            Career achievement milestone bonus: MO (৳5,000), AM (৳15,000), SM (৳50,000), ME (৳200,000).
                        </p>
                    </div>
                </div>

                <div style={{ overflowX: 'auto', border: '1px solid #e2e8f0', borderRadius: '8px' }}>
                    <table style={{ width: '100%', fontSize: '13px', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', textAlign: 'left', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '10px 14px' }}>Leader</th>
                                <th style={{ padding: '10px 14px' }}>Dealer Code</th>
                                <th style={{ padding: '10px 14px' }}>Achieved Rank</th>
                                <th style={{ padding: '10px 14px' }}>Standard Incentive</th>
                                <th style={{ padding: '10px 14px' }}>Claim Status</th>
                                <th style={{ padding: '10px 14px', textAlign: 'right' }}>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state.qualified_rank_leaders.length === 0 ? (
                                <tr>
                                    <td colSpan={6} style={{ padding: '24px', textAlign: 'center', color: '#94a3b8' }}>
                                        No rank leaders eligible for one-time milestone claims.
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
                                                        <CheckCircle2 size={14} /> Paid & Disbursed
                                                    </span>
                                                ) : (
                                                    <span style={{ color: '#d97706', fontWeight: 600, fontSize: '12px' }}>
                                                        Pending Claim
                                                    </span>
                                                )}
                                            </td>
                                            <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                                                {l.one_time_incentive_paid ? (
                                                    <span style={{ color: '#94a3b8', fontSize: '12px' }}>Settled</span>
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
                                                        {claimingIncentive === l.user_id ? 'Claiming…' : 'Approve & Pay Payout'}
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
                    Settled Pool Transactions & Journal Entries
                </h3>
                <div style={{ overflowX: 'auto', border: '1px solid #e2e8f0', borderRadius: '8px' }}>
                    <table style={{ width: '100%', fontSize: '13px', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', textAlign: 'left', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '10px 14px' }}>Date</th>
                                <th style={{ padding: '10px 14px' }}>Event Reference</th>
                                <th style={{ padding: '10px 14px' }}>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state.settled_history.length === 0 ? (
                                <tr>
                                    <td colSpan={3} style={{ padding: '20px', textAlign: 'center', color: '#94a3b8' }}>
                                        No settlement journal entries recorded yet.
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
