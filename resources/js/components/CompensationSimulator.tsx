import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ShieldCheck, AlertTriangle, TrendingUp, TrendingDown, RefreshCw, Play, Sparkles } from 'lucide-react';
import { money } from './UI';
import { policyPercent, type PolicyLimits } from './CommissionStudio';
import { useT } from '../shared/i18n/t';

interface SimulationResult {
    gross_sales_tested: number;
    orders_analyzed_count: number;
    account_mix?: {
        dealer_purchase_value: string | number;
        sub_dealer_purchase_value: string | number;
    };
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

const SLICE_METADATA: Record<string, { labelKey: string; group: 'direct' | 'leadership' | 'area_corp'; descKey: string }> = {
    own_dealer_cashback: { labelKey: 'sim_slice_own_dealer_cashback', group: 'direct', descKey: 'sim_slice_own_dealer_cashback_desc' },
    direct_referral: { labelKey: 'sim_slice_direct_referral', group: 'direct', descKey: 'sim_slice_direct_referral_desc' },
    d2d_gen2: { labelKey: 'sim_slice_d2d_gen2', group: 'direct', descKey: 'sim_slice_d2d_gen2_desc' },
    d2d_gen3: { labelKey: 'sim_slice_d2d_gen3', group: 'direct', descKey: 'sim_slice_d2d_gen3_desc' },
    area_dealer_pool: { labelKey: 'sim_slice_area_dealer_pool', group: 'area_corp', descKey: 'sim_slice_area_dealer_pool_desc' },
    dealer_monthly_salary: { labelKey: 'sim_slice_dealer_monthly_salary', group: 'leadership', descKey: 'sim_slice_dealer_monthly_salary_desc' },
    rank_royalty: { labelKey: 'sim_slice_rank_royalty', group: 'leadership', descKey: 'sim_slice_rank_royalty_desc' },
    rank_incentive: { labelKey: 'sim_slice_rank_incentive', group: 'leadership', descKey: 'sim_slice_rank_incentive_desc' },
    company_marketing: { labelKey: 'sim_slice_company_marketing', group: 'area_corp', descKey: 'sim_slice_company_marketing_desc' },
    car_bike_incentive: { labelKey: 'sim_slice_car_bike_incentive', group: 'leadership', descKey: 'sim_slice_car_bike_incentive_desc' },
};

/** What-if scenarios. The first button ("current live rates") is built from the live policy below. */
const SCENARIO_PRESETS: Array<{ nameKey: string; descKey: string; rates: Record<string, number> }> = [
    {
        nameKey: 'sim_preset_conservative',
        descKey: 'sim_preset_conservative_desc',
        rates: {
            own_dealer_cashback: 4.0, direct_referral: 8.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 9.0, dealer_monthly_salary: 2.0, rank_royalty: 4.0,
            rank_incentive: 2.0, company_marketing: 1.5, car_bike_incentive: 1.5
        }
    },
    {
        nameKey: 'sim_preset_frontline',
        descKey: 'sim_preset_frontline_desc',
        rates: {
            own_dealer_cashback: 6.0, direct_referral: 12.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 8.0, dealer_monthly_salary: 2.0, rank_royalty: 3.5,
            rank_incentive: 1.5, company_marketing: 1.5, car_bike_incentive: 1.5
        }
    },
    {
        nameKey: 'sim_preset_leadership',
        descKey: 'sim_preset_leadership_desc',
        rates: {
            own_dealer_cashback: 4.0, direct_referral: 9.0, d2d_gen2: 2.0, d2d_gen3: 1.0,
            area_dealer_pool: 8.0, dealer_monthly_salary: 3.0, rank_royalty: 6.0,
            rank_incentive: 2.0, company_marketing: 2.0, car_bike_incentive: 3.0
        }
    }
];

export default function CompensationSimulator({
    initialSimulation,
    activeRules,
    limits,
}: {
    initialSimulation: SimulationResult;
    activeRules: RuleRecord[];
    limits?: PolicyLimits;
}) {
    const translate = useT();
    const { data: pageData } = usePage<any>().props;
    const policyLimits: PolicyLimits = limits ?? pageData?.policy_limits ?? {};
    const dealerCap = policyPercent(policyLimits.dealer_pool_cap_pct);
    const subDealerCap = policyPercent(policyLimits.sub_dealer_pool_cap_pct);
    const subDealerKeys = policyLimits.sub_dealer_rule_keys ?? [];
    const notSet = translate('portal', 'policy_value_not_set');
    const [simData, setSimData] = useState<SimulationResult>(initialSimulation);
    const [loading, setLoading] = useState(false);
    const liveRates: Record<string, number> = Object.fromEntries(
        initialSimulation.slices_comparison.map(slice => [slice.key, slice.baseline_pct]),
    );
    const [rates, setRates] = useState<Record<string, number>>(() => Object.fromEntries(
        initialSimulation.slices_comparison.map(slice => [slice.key, slice.simulated_pct]),
    ));
    const presets = [
        { nameKey: 'sim_preset_current', descKey: 'sim_preset_current_desc', rates: liveRates },
        ...SCENARIO_PRESETS,
    ];

    // Dealer pool and sub-dealer extras are checked against their own policy limits.
    const sumRates = (keep: (key: string) => boolean) => Object.entries(rates)
        .filter(([key]) => keep(key))
        .reduce((sum, [, value]) => sum + (Number(value) || 0), 0);
    const totalProposedPct = sumRates(key => !subDealerKeys.includes(key));
    const subDealerProposedPct = totalProposedPct + sumRates(key => subDealerKeys.includes(key));
    const isExceedingCap = (dealerCap !== null && totalProposedPct > dealerCap + 0.0001)
        || (subDealerCap !== null && subDealerProposedPct > subDealerCap + 0.0001);
    const capBadge = dealerCap === null
        ? translate('portal', 'sim_cap_not_set')
        : translate('portal', isExceedingCap ? 'sim_cap_breach_live' : 'sim_cap_ok_live', { pct: dealerCap.toFixed(2) });
    const retentionFloor = dealerCap === null ? notSet : `${(100 - dealerCap).toFixed(2)}%`;

    const handleRateChange = (key: string, value: number) => {
        setRates(prev => ({
            ...prev,
            [key]: Math.max(0, Math.min(dealerCap ?? 100, Math.round(value * 100) / 100))
        }));
    };

    const applyPreset = (preset: { rates: Record<string, number> }) => {
        setRates(prev => ({ ...prev, ...preset.rates }));
    };

    /** Sub-dealer extras are paid only on sub-dealer purchases, the same basis the backend uses. */
    const basisFor = (key: string) => subDealerKeys.includes(key)
        ? Number(simData.account_mix?.sub_dealer_purchase_value ?? 0)
        : simData.gross_sales_tested;
    const signedMoney = (amount: number) => `${amount > 0 ? '+' : amount < 0 ? '-' : ''}${money(Math.abs(amount))}`;
    const sliceRows = simData.slices_comparison.map(slice => {
        const currentSimPct = rates[slice.key] ?? slice.simulated_pct;
        const simAmount = roundAmount(basisFor(slice.key) * (currentSimPct / 100));
        return {
            slice,
            currentSimPct,
            simAmount,
            deltaPct: roundAmount(currentSimPct - slice.baseline_pct),
            deltaAmount: roundAmount(simAmount - slice.baseline_amount),
        };
    });
    const baselinePoolPct = simData.slices_comparison
        .filter(slice => !subDealerKeys.includes(slice.key))
        .reduce((sum, slice) => sum + slice.baseline_pct, 0);
    const simulatedPayoutTotal = roundAmount(sliceRows.reduce((sum, row) => sum + row.simAmount, 0));
    const payoutVariance = roundAmount(simulatedPayoutTotal - simData.baseline.total_commissions);

    const [error, setError] = useState<string | null>(null);

    const runSimulation = async () => {
        setLoading(true);
        setError(null);
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
                return;
            }

            const payload = await response.json().catch(() => null);
            const message = payload?.message
                || (payload?.errors ? Object.values(payload.errors).flat().join(' ') : null)
                || translate('portal','sim_error_failed',{status:String(response.status)});
            setError(String(message));
        } catch {
            setError(translate('portal','sim_error_reach'));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
            {error && (
                <div role="alert" style={{
                    background: '#fef2f2',
                    border: '1px solid #fecaca',
                    color: '#991b1b',
                    borderRadius: 10,
                    padding: '12px 14px',
                    fontSize: 13,
                    display: 'flex',
                    gap: 8,
                    alignItems: 'flex-start',
                }}>
                    <AlertTriangle size={16} style={{ marginTop: 2, flexShrink: 0 }} />
                    <span>{error}</span>
                </div>
            )}
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
                                {isExceedingCap || dealerCap === null ? <AlertTriangle size={15} /> : <ShieldCheck size={15} />}
                                {capBadge}
                            </span>
                            <span style={{ fontSize: '13px', color: '#64748b' }}>
                                {translate('portal','sim_tested_against',{amount:money(simData.gross_sales_tested,{symbol:false}),count:String(simData.orders_analyzed_count)})}
                            </span>
                        </div>
                        <h2 style={{ margin: 0, fontSize: '24px', fontWeight: 800, color: '#0f172a' }}>
                            {translate('portal','sim_title')}
                        </h2>
                        <p style={{ margin: '6px 0 0 0', fontSize: '14px', color: '#475569', maxWidth: '750px' }}>
                            {translate('portal','sim_body')}
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
                            {loading ? translate('portal','sim_running') : translate('portal','sim_run')}
                        </button>
                    </div>
                </div>

                {/* Pool Sum Progress Bar */}
                <div style={{ marginTop: '20px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', fontWeight: 600, marginBottom: '6px' }}>
                        <span style={{ color: isExceedingCap ? '#b91c1c' : '#047857' }}>
                            {translate('portal','sim_proposed_pool',{pct:totalProposedPct.toFixed(2)})}
                        </span>
                        <span style={{ color: '#64748b' }}>
                            {translate('portal','sim_company_retention_live',{pct:(100 - totalProposedPct).toFixed(2),floor:retentionFloor})}
                        </span>
                    </div>
                    <div style={{ width: '100%', height: '12px', background: '#e2e8f0', borderRadius: '6px', overflow: 'hidden', display: 'flex' }}>
                        <div style={{
                            width: `${Math.min(100, Math.max(0, totalProposedPct))}%`,
                            background: isExceedingCap ? '#ef4444' : '#059669',
                            transition: 'width 0.2s ease'
                        }} />
                        <div style={{
                            width: `${Math.max(0, 100 - Math.min(100, totalProposedPct))}%`,
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
                    {translate('portal','sim_scenarios')}
                </div>
                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', flex: 1 }}>
                    {presets.map((p, idx) => (
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
                            title={translate('portal',p.descKey)}
                        >
                            {translate('portal',p.nameKey)}
                        </button>
                    ))}
                </div>
            </div>

            {/* KPI Cards: Baseline vs Simulated */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '16px' }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal','sim_gross_sales')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#0f172a', marginTop: '6px' }}>
                        {money(simData.gross_sales_tested)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#047857', fontWeight: 500 }}>
                        {simData.is_synthetic_benchmark ? translate('portal','sim_benchmark') : translate('portal','sim_real_orders')}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal','sim_commissions')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#047857', marginTop: '6px' }}>
                        {money(simData.simulated.total_commissions)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal','sim_baseline',{amount:money(simData.baseline.total_commissions,{symbol:false})})}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal','sim_variance')}</span>
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
                        {signedMoney(simData.variance.amount)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal','sim_vs_baseline',{pct:`${simData.variance.pct > 0 ? '+' : ''}${simData.variance.pct.toFixed(2)}`})}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal','sim_retained')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#0284c7', marginTop: '6px' }}>
                        {simData.simulated.retention_pct.toFixed(2)}%
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal','sim_net_margin',{amount:money(simData.simulated.company_retention,{symbol:false})})}
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
                        {translate('portal','sim_adjust_title')}
                    </h3>
                    <p style={{ margin: '4px 0 0 0', fontSize: '13px', color: '#64748b' }}>
                        {translate('portal','sim_adjust_hint')}
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
                                        {translate('portal',meta.labelKey)}
                                    </span>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                                        <input
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            max={dealerCap ?? 100}
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
                                    max={dealerCap ?? 100}
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
                                    {translate('portal',meta.descKey)}
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
                        {translate('portal','sim_breakdown_title')}
                    </h3>
                    <span style={{ fontSize: '13px', color: '#64748b' }}>
                        {translate('portal','sim_breakdown_hint')}
                    </span>
                </div>

                <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '12px 20px', fontWeight: 600 }}>{translate('portal','sim_col_stream')}</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>{translate('portal','sim_col_base_pct')}</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>{translate('portal','sim_col_sim_pct')}</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>{translate('portal','sim_col_delta')}</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>{translate('portal','sim_col_base_pay')}</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>{translate('portal','sim_col_sim_pay')}</th>
                                <th style={{ padding: '12px 20px', fontWeight: 600, textAlign: 'right' }}>{translate('portal','sim_col_cash_var')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sliceRows.map(({ slice, currentSimPct, simAmount, deltaPct, deltaAmount }) => {
                                return (
                                    <tr key={slice.key} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 20px', fontWeight: 600, color: '#1e293b' }}>
                                            {SLICE_METADATA[slice.key] ? translate('portal',SLICE_METADATA[slice.key].labelKey) : slice.name}
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
                                            {money(slice.baseline_amount)}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right', fontWeight: 700, color: '#0f172a' }}>
                                            {money(simAmount)}
                                        </td>
                                        <td style={{
                                            padding: '12px 20px',
                                            textAlign: 'right',
                                            fontWeight: 700,
                                            color: deltaAmount > 0 ? '#b91c1c' : deltaAmount < 0 ? '#047857' : '#64748b'
                                        }}>
                                            {signedMoney(deltaAmount)}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr style={{ background: '#f8fafc', fontWeight: 700, borderTop: '2px solid #e2e8f0' }}>
                                <td style={{ padding: '14px 20px', color: '#0f172a' }}>{translate('portal','sim_total_pool')}</td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: '#64748b' }}>{baselinePoolPct.toFixed(2)}%</td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: isExceedingCap ? '#dc2626' : '#047857' }}>
                                    {totalProposedPct.toFixed(2)}%
                                </td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: totalProposedPct > baselinePoolPct ? '#dc2626' : '#047857' }}>
                                    {`${totalProposedPct > baselinePoolPct ? '+' : ''}${(totalProposedPct - baselinePoolPct).toFixed(2)}%`}
                                </td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: '#64748b' }}>
                                    {money(simData.baseline.total_commissions)}
                                </td>
                                <td style={{ padding: '14px 16px', textAlign: 'right', color: '#0f172a' }}>
                                    {money(simulatedPayoutTotal)}
                                </td>
                                <td style={{ padding: '14px 20px', textAlign: 'right', color: payoutVariance > 0 ? '#dc2626' : '#047857' }}>
                                    {signedMoney(payoutVariance)}
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
