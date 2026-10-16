import React, { useState, useEffect } from 'react';
import { 
    SlidersHorizontal, Percent, ShieldCheck, AlertTriangle, TrendingUp, 
    TrendingDown, RefreshCw, Play, CheckCircle2, ArrowRight, BarChart3, 
    DollarSign, Info, RotateCcw, Sparkles, Layers
} from 'lucide-react';
import { money } from './UI';

interface SimulationResult {
    gross_sales_tested: number;
    orders_analyzed_count: number;
    is_synthetic_benchmark: boolean;
    total_proposed_pct: number;
    exceeds_cap: boolean;
    baseline: {
        total_commissions: number;
        company_retention: number;
        retention_pct: number;
        categories: Record<string, number>;
    };
    simulated: {
        total_commissions: number;
        company_retention: number;
        retention_pct: number;
        categories: Record<string, number>;
    };
    variance: {
        amount: number;
        pct: number;
    };
    slices_comparison: Array<{
        key: string;
        name: string;
        baseline_pct: number;
        simulated_pct: number;
        delta_pct: number;
        baseline_amount: number;
        simulated_amount: number;
        delta_amount: number;
    }>;
}

interface RuleRecord {
    id: number;
    rule_key: string;
    category: string;
    name: string;
    description: string;
    percentage: string | number;
    amount_threshold: string | number;
    cap_limit: string | number;
    parameters: any;
    is_active: boolean;
}

const SLICE_METADATA: Record<string, { label: string; group: 'direct' | 'leadership' | 'area_corp'; desc: string }> = {
    own_dealer_cashback: { label: 'Own Dealer Cashback', group: 'direct', desc: 'Instant cash credit to purchasing dealer upon order delivery' },
    direct_referral: { label: 'Direct Referral Bonus', group: 'direct', desc: 'Sponsorship commission paid to immediate frontline upline' },
    d2d_gen2: { label: 'D2D Generation 2', group: 'direct', desc: 'Second-tier indirect team bonus (compressed)' },
    d2d_gen3: { label: 'D2D Generation 3', group: 'direct', desc: 'Third-tier indirect team bonus (breakage routed to company)' },
    area_dealer_pool: { label: 'Area Dealer Revenue Pool', group: 'area_corp', desc: 'Split across Division (2%), District (2%), Upazila (3%), Union (3%)' },
    dealer_monthly_salary: { label: 'Monthly Salary Pool', group: 'leadership', desc: 'Monthly pool split equally among dealers with ≥ ৳10k volume' },
    rank_royalty: { label: 'Rank Royalty Pool', group: 'leadership', desc: '4% pool split among active Paid-As MO, AM, SM, ME leaders' },
    rank_incentive: { label: 'Rank Career Milestones', group: 'leadership', desc: 'One-time career advancement bonuses (৳5k - ৳50k)' },
    company_marketing: { label: 'Marketing & Brand Fund', group: 'area_corp', desc: 'National advertising, campaigns and digital marketing' },
    car_bike_incentive: { label: 'Car & Bike Asset Reserve', group: 'leadership', desc: 'Escrow fund with anti-whale maximum branch volume limits' },
};

const PRESETS = [
    {
        name: 'Default Baseline Plan (40% Pool)',
        desc: 'Canonical 60/40 zero-deficit company retention plan',
        rates: {
            own_dealer_cashback: 5.0, direct_referral: 10.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 10.0, dealer_monthly_salary: 2.0, rank_royalty: 4.0,
            rank_incentive: 2.0, company_marketing: 2.0, car_bike_incentive: 2.0
        }
    },
    {
        name: 'Conservative Margin Guard (35% Pool)',
        desc: 'Expands company gross retention to 65% for increased operating buffer',
        rates: {
            own_dealer_cashback: 4.0, direct_referral: 8.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 9.0, dealer_monthly_salary: 2.0, rank_royalty: 4.0,
            rank_incentive: 2.0, company_marketing: 1.5, car_bike_incentive: 1.5
        }
    },
    {
        name: 'High Retail Frontline Incentive (39% Pool)',
        desc: 'Accelerates field acquisition with 12% direct referral and 6% cashback',
        rates: {
            own_dealer_cashback: 6.0, direct_referral: 12.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 8.0, dealer_monthly_salary: 2.0, rank_royalty: 3.5,
            rank_incentive: 1.5, company_marketing: 1.5, car_bike_incentive: 1.5
        }
    },
    {
        name: 'Leadership & Loyalty Focus (40% Pool)',
        desc: 'Amplifies leadership pools to 6% royalty and 3% monthly salary',
        rates: {
            own_dealer_cashback: 4.0, direct_referral: 9.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 8.0, dealer_monthly_salary: 3.0, rank_royalty: 6.0,
            rank_incentive: 2.0, company_marketing: 2.0, car_bike_incentive: 3.0
        }
    }
];

export default function CompensationSimulator({ 
    initialSimulation, 
    activeRules 
}: { 
    initialSimulation: SimulationResult; 
    activeRules: RuleRecord[];
}) {
    const [simData, setSimData] = useState<SimulationResult>(initialSimulation);
    const [loading, setLoading] = useState(false);
    const [rates, setRates] = useState<Record<string, number>>(() => {
        const initial: Record<string, number> = {};
        initialSimulation.slices_comparison.forEach(s => {
            initial[s.key] = s.simulated_pct;
        });
        return initial;
    });

    // Real-time sum calculation
    const totalProposedPct = Object.values(rates).reduce((a, b) => a + (Number(b) || 0), 0);
    const isExceedingCap = totalProposedPct > 40.0001;
    const remainingBuffer = Math.max(0, 40.00 - totalProposedPct);

    const handleRateChange = (key: string, value: number) => {
        setRates(prev => ({
            ...prev,
            [key]: Math.max(0, Math.min(40, Math.round(value * 100) / 100))
        }));
    };

    const applyPreset = (preset: typeof PRESETS[0]) => {
        setRates({ ...preset.rates });
    };

    const runSimulation = async () => {
        setLoading(true);
        try {
            const csrfToken = document.cookie
                .split('; ')
                .find(row => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1];

            const response = await fetch('/commands/commissions/simulate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                },
                body: JSON.stringify({ overrides: rates })
            });

            if (response.ok) {
                const result = await response.json();
                setSimData(result);
            }
        } catch (e) {
            console.error('Failed to run simulation', e);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
            {/* Header Banner & Cap Guardrail */}
            <div style={{
                background: isExceedingCap ? '#fef2f2' : '#f0fdf4',
                border: `1.5px solid ${isExceedingCap ? '#fca5a5' : '#86efac'}`,
                borderRadius: '14px',
                padding: '24px',
                boxShadow: '0 2px 10px rgba(0,0,0,0.03)'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '16px' }}>
                    <div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' }}>
                            <span style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '6px',
                                padding: '4px 10px',
                                borderRadius: '6px',
                                fontSize: '12px',
                                fontWeight: 700,
                                background: isExceedingCap ? '#fee2e2' : '#dcfce7',
                                color: isExceedingCap ? '#b91c1c' : '#15803d'
                            }}>
                                {isExceedingCap ? <AlertTriangle size={15} /> : <ShieldCheck size={15} />}
                                {isExceedingCap ? 'CAP CEILING BREACHED (> 40.00%)' : 'ZERO-DEFICIT COMPLIANT (≤ 40.00%)'}
                            </span>
                            <span style={{ fontSize: '13px', color: '#64748b' }}>
                                Tested against ৳{simData.gross_sales_tested.toLocaleString()} sales volume ({simData.orders_analyzed_count} orders)
                            </span>
                        </div>
                        <h2 style={{ margin: 0, fontSize: '24px', fontWeight: 800, color: '#0f172a' }}>
                            What-If Compensation Backtester
                        </h2>
                        <p style={{ margin: '6px 0 0 0', fontSize: '14px', color: '#475569', maxWidth: '750px' }}>
                            Model parameter modifications in-memory against actual commerce order logs. Every trial guarantees company retention cannot drop below 60.00% without trigger alerts.
                        </p>
                    </div>

                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                        <button
                            type="button"
                            onClick={runSimulation}
                            disabled={loading}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '8px',
                                background: isExceedingCap ? '#94a3b8' : '#047857',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: '8px',
                                padding: '12px 20px',
                                fontSize: '14px',
                                fontWeight: 700,
                                cursor: isExceedingCap ? 'not-allowed' : 'pointer',
                                boxShadow: '0 2px 6px rgba(4,120,87,0.25)'
                            }}
                        >
                            {loading ? <RefreshCw size={16} className="spin" /> : <Play size={16} />}
                            {loading ? 'Simulating…' : 'Run What-If Backtest'}
                        </button>
                    </div>
                </div>

                {/* Pool Sum Progress Bar */}
                <div style={{ marginTop: '20px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', fontWeight: 600, marginBottom: '6px' }}>
                        <span style={{ color: isExceedingCap ? '#b91c1c' : '#047857' }}>
                            Proposed Total Pool: <b>{totalProposedPct.toFixed(2)}%</b> of Collected Cash
                        </span>
                        <span style={{ color: '#64748b' }}>
                            Company Retention: <b>{(100 - totalProposedPct).toFixed(2)}%</b> (Min Req: 60.00%)
                        </span>
                    </div>
                    <div style={{ width: '100%', height: '12px', background: '#e2e8f0', borderRadius: '6px', overflow: 'hidden', display: 'flex' }}>
                        <div style={{
                            width: `${Math.min(100, (totalProposedPct / 40) * 100 * 0.4)}%`,
                            background: isExceedingCap ? '#ef4444' : '#059669',
                            transition: 'width 0.2s ease'
                        }} />
                        <div style={{
                            width: `${Math.max(0, 100 - Math.min(100, (totalProposedPct / 40) * 100 * 0.4))}%`,
                            background: '#0284c7'
                        }} />
                    </div>
                </div>
            </div>

            {/* Presets Quick Picker */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '16px 20px',
                display: 'flex',
                alignItems: 'center',
                gap: '16px',
                flexWrap: 'wrap'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px', fontWeight: 700, color: '#334155' }}>
                    <Sparkles size={16} color="#047857" />
                    Strategic Scenarios:
                </div>
                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', flex: 1 }}>
                    {PRESETS.map((p, idx) => (
                        <button
                            key={idx}
                            type="button"
                            onClick={() => applyPreset(p)}
                            style={{
                                background: '#f8fafc',
                                border: '1px solid #cbd5e1',
                                borderRadius: '6px',
                                padding: '6px 12px',
                                fontSize: '12px',
                                fontWeight: 600,
                                color: '#1e293b',
                                cursor: 'pointer',
                                transition: 'all 0.15s ease'
                            }}
                            onMouseOver={(e) => { e.currentTarget.style.borderColor = '#047857'; e.currentTarget.style.background = '#f0fdf4'; }}
                            onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#f8fafc'; }}
                            title={p.desc}
                        >
                            {p.name}
                        </button>
                    ))}
                </div>
            </div>

            {/* KPI Cards: Baseline vs Simulated */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '16px' }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Gross Sales Analyzed</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#0f172a', marginTop: '6px' }}>
                        ৳{simData.gross_sales_tested.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#047857', fontWeight: 500 }}>
                        {simData.is_synthetic_benchmark ? 'Standard Benchmark Set' : 'Real Commerce Delivered Orders'}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Simulated Commissions</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#047857', marginTop: '6px' }}>
                        ৳{simData.simulated.total_commissions.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        Baseline: ৳{simData.baseline.total_commissions.toLocaleString()}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Disbursement Variance</span>
                    <div style={{ 
                        fontSize: '24px', 
                        fontWeight: 800, 
                        color: simData.variance.amount > 0 ? '#b91c1c' : simData.variance.amount < 0 ? '#047857' : '#0f172a', 
                        marginTop: '6px',
                        display: 'flex',
                        alignItems: 'center',
                        gap: '6px'
                    }}>
                        {simData.variance.amount > 0 ? <TrendingUp size={22} /> : simData.variance.amount < 0 ? <TrendingDown size={22} /> : null}
                        {simData.variance.amount > 0 ? '+' : ''}৳{simData.variance.amount.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {simData.variance.pct > 0 ? '+' : ''}{simData.variance.pct.toFixed(2)}% vs Baseline
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Company Retained Margin</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#0284c7', marginTop: '6px' }}>
                        {simData.simulated.retention_pct.toFixed(2)}%
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        ৳{simData.simulated.company_retention.toLocaleString()} net gross margin
                    </span>
                </div>
            </div>

            {/* Parameter Adjustment Sliders & Inputs */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '24px'
            }}>
                <div style={{ marginBottom: '20px' }}>
                    <h3 style={{ margin: 0, fontSize: '18px', fontWeight: 700, color: '#0f172a' }}>
                        Adjust Slice Percentages
                    </h3>
                    <p style={{ margin: '4px 0 0 0', fontSize: '13px', color: '#64748b' }}>
                        Drag sliders or type exact percentages for each category. Changes calculate instantaneous margin impacts.
                    </p>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '20px' }}>
                    {Object.keys(SLICE_METADATA).map(key => {
                        const meta = SLICE_METADATA[key];
                        const currentVal = rates[key] ?? 0;
                        return (
                            <div key={key} style={{
                                padding: '16px',
                                borderRadius: '8px',
                                background: '#f8fafc',
                                border: '1px solid #e2e8f0'
                            }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                                    <span style={{ fontSize: '14px', fontWeight: 700, color: '#1e293b' }}>
                                        {meta.label}
                                    </span>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                                        <input
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            max="30"
                                            value={currentVal}
                                            onChange={(e) => handleRateChange(key, parseFloat(e.target.value) || 0)}
                                            style={{
                                                width: '64px',
                                                padding: '4px 8px',
                                                borderRadius: '6px',
                                                border: '1px solid #cbd5e1',
                                                fontSize: '13px',
                                                fontWeight: 700,
                                                textAlign: 'right'
                                            }}
                                        />
                                        <span style={{ fontSize: '13px', fontWeight: 600, color: '#64748b' }}>%</span>
                                    </div>
                                </div>
                                <input
                                    type="range"
                                    min="0"
                                    max="20"
                                    step="0.25"
                                    value={currentVal}
                                    onChange={(e) => handleRateChange(key, parseFloat(e.target.value) || 0)}
                                    style={{
                                        width: '100%',
                                        accentColor: '#047857',
                                        cursor: 'pointer'
                                    }}
                                />
                                <div style={{ fontSize: '11px', color: '#64748b', marginTop: '4px' }}>
                                    {meta.desc}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Slices Breakdown Comparison Table */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                overflow: 'hidden'
            }}>
                <div style={{ padding: '20px 24px', borderBottom: '1px solid #e2e8f0' }}>
                    <h3 style={{ margin: 0, fontSize: '16px', fontWeight: 700, color: '#0f172a' }}>
                        Compensation Slices Variance Breakdown
                    </h3>
                    <span style={{ fontSize: '13px', color: '#64748b' }}>
                        Detailed baseline vs simulated variance across all 10 commission streams
                    </span>
                </div>

                <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '12px 20px', fontWeight: 600 }}>Commission Stream</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Baseline %</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Simulated %</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Rate Delta</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Baseline Payout</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Simulated Payout</th>
                                <th style={{ padding: '12px 20px', fontWeight: 600, textAlign: 'right' }}>Cash Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            {simData.slices_comparison.map(slice => {
                                const currentSimPct = rates[slice.key] ?? slice.simulated_pct;
                                const simAmount = roundAmount(simData.gross_sales_tested * (currentSimPct / 100));
                                const deltaPct = roundAmount(currentSimPct - slice.baseline_pct);
                                const deltaAmount = roundAmount(simAmount - slice.baseline_amount);

                                return (
                                    <tr key={slice.key} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 20px', fontWeight: 600, color: '#1e293b' }}>
                                            {slice.name}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right', color: '#64748b' }}>
                                            {slice.baseline_pct.toFixed(2)}%
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right', fontWeight: 700, color: '#047857' }}>
                                            {currentSimPct.toFixed(2)}%
                                        </td>
                                        <td style={{ 
                                            padding: '12px 16px', 
                                            textAlign: 'right', 
                                            fontWeight: 600,
                                            color: deltaPct > 0 ? '#b91c1c' : deltaPct < 0 ? '#047857' : '#64748b'
                                        }}>
                                            {deltaPct > 0 ? `+${deltaPct.toFixed(2)}%` : deltaPct < 0 ? `${deltaPct.toFixed(2)}%` : '0.00%'}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right', color: '#64748b' }}>
                                            ৳{slice.baseline_amount.toLocaleString()}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right', fontWeight: 700, color: '#0f172a' }}>
                                            ৳{simAmount.toLocaleString()}
                                        </td>
                                        <td style={{ 
                                            padding: '12px 20px', 
                                            textAlign: 'right', 
                                            fontWeight: 700,
                                            color: deltaAmount > 0 ? '#b91c1c' : deltaAmount < 0 ? '#047857' : '#64748b'
                                        }}>
                                            {deltaAmount > 0 ? `+৳${deltaAmount.toLocaleString()}` : deltaAmount < 0 ? `-৳${Math.abs(deltaAmount).toLocaleString()}` : '৳0.00'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr style={{ background: '#f8fafc', fontWeight: 700, borderTop: '2px solid #e2e8f0' }}>
                                <td style={{ padding: '14px 20px', color: '#0f172a' }}>Total Commission Pool</td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: '#64748b' }}>40.00%</td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: isExceedingCap ? '#dc2626' : '#047857' }}>
                                    {totalProposedPct.toFixed(2)}%
                                </td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: totalProposedPct > 40 ? '#dc2626' : '#047857' }}>
                                    {(totalProposedPct - 40).toFixed(2)}%
                                </td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: '#64748b' }}>
                                    ৳{simData.baseline.total_commissions.toLocaleString()}
                                </td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: '#0f172a' }}>
                                    ৳{roundAmount(simData.gross_sales_tested * (totalProposedPct / 100)).toLocaleString()}
                                </td>
                                <td style={{ padding: '14px 20px', textAlign: 'right', color: isExceedingCap ? '#dc2626' : '#047857' }}>
                                    ৳{roundAmount(simData.gross_sales_tested * ((totalProposedPct - 40) / 100)).toLocaleString()}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    );
}

function roundAmount(val: number): number {
    return Math.round(val * 100) / 100;
}
