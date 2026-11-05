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

    const signTone = (value: number): string => (value > 0 ? 'tone-danger' : value < 0 ? 'tone-ok' : '');
    const capTone = isExceedingCap ? 'tone-danger' : 'tone-ok';

    return (
        <div className="hub sim">
            {error && (
                <div role="alert" className="error sim-alert">
                    <AlertTriangle size={16} />
                    <span>{error}</span>
                </div>
            )}
            {/* Header Banner & Cap Guardrail */}
            <div className={`panel is-tinted sim-banner ${capTone}`}>
                <div className="ops-row is-between is-top is-wrap is-wide">
                    <div>
                        <div className="hub-kicker">
                            <span className="hub-pill">
                                {isExceedingCap || dealerCap === null ? <AlertTriangle size={15} /> : <ShieldCheck size={15} />}
                                {capBadge}
                            </span>
                            <span className="ops-fs-sm ops-muted">
                                {translate('portal','sim_tested_against',{amount:money(simData.gross_sales_tested,{symbol:false}),count:String(simData.orders_analyzed_count)})}
                            </span>
                        </div>
                        <h2 className="sim-title">
                            {translate('portal','sim_title')}
                        </h2>
                        <p className="sim-lead">
                            {translate('portal','sim_body')}
                        </p>
                    </div>

                    <div className="ops-row is-loose">
                        <button
                            type="button"
                            onClick={runSimulation}
                            disabled={loading}
                            className={`button dark sim-run${isExceedingCap ? ' is-blocked' : ''}`}
                        >
                            {loading ? <RefreshCw size={16} className="spin" /> : <Play size={16} />}
                            {loading ? translate('portal','sim_running') : translate('portal','sim_run')}
                        </button>
                    </div>
                </div>

                {/* Pool Sum Progress Bar */}
                <div className="ops-mt-5">
                    <div className="ops-row is-between is-wrap ops-fs-sm ops-semibold ops-mb-2">
                        <span className={capTone}>
                            {translate('portal','sim_proposed_pool',{pct:totalProposedPct.toFixed(2)})}
                        </span>
                        <span className="ops-muted">
                            {translate('portal','sim_company_retention_live',{pct:(100 - totalProposedPct).toFixed(2),floor:retentionFloor})}
                        </span>
                    </div>
                    <div className="hub-progress is-lg sim-pool">
                        <div className={capTone} style={{ width: `${Math.min(100, Math.max(0, totalProposedPct))}%` }} />
                        <div className="tone-info" style={{ width: `${Math.max(0, 100 - Math.min(100, totalProposedPct))}%` }} />
                    </div>
                </div>
            </div>

            {/* Presets Quick Picker */}
            <div className="ops-card ops-row is-wide is-wrap">
                <div className="ops-row ops-fs-sm ops-bold ops-text-2">
                    <Sparkles size={16} className="sim-accent-icon" />
                    {translate('portal','sim_scenarios')}
                </div>
                <div className="ops-row is-wrap ops-grow">
                    {presets.map((p, idx) => (
                        <button
                            key={idx}
                            type="button"
                            onClick={() => applyPreset(p)}
                            className="button small sim-preset"
                            title={translate('portal',p.descKey)}
                        >
                            {translate('portal',p.nameKey)}
                        </button>
                    ))}
                </div>
            </div>

            {/* KPI Cards: Baseline vs Simulated */}
            <div className="hub-grid">
                <div className="hub-tile">
                    <span className="hub-label">{translate('portal','sim_gross_sales')}</span>
                    <div className="ops-figure">
                        {money(simData.gross_sales_tested)}
                    </div>
                    <span className="hub-hint ops-medium tone-ok">
                        {simData.is_synthetic_benchmark ? translate('portal','sim_benchmark') : translate('portal','sim_real_orders')}
                    </span>
                </div>

                <div className="hub-tile">
                    <span className="hub-label">{translate('portal','sim_commissions')}</span>
                    <div className="ops-figure tone-ok">
                        {money(simData.simulated.total_commissions)}
                    </div>
                    <span className="hub-hint">
                        {translate('portal','sim_baseline',{amount:money(simData.baseline.total_commissions,{symbol:false})})}
                    </span>
                </div>

                <div className="hub-tile">
                    <span className="hub-label">{translate('portal','sim_variance')}</span>
                    <div className={`ops-figure ops-row is-snug ${signTone(simData.variance.amount)}`}>
                        {simData.variance.amount > 0 ? <TrendingUp size={22} /> : simData.variance.amount < 0 ? <TrendingDown size={22} /> : null}
                        {signedMoney(simData.variance.amount)}
                    </div>
                    <span className="hub-hint">
                        {translate('portal','sim_vs_baseline',{pct:`${simData.variance.pct > 0 ? '+' : ''}${simData.variance.pct.toFixed(2)}`})}
                    </span>
                </div>

                <div className="hub-tile">
                    <span className="hub-label">{translate('portal','sim_retained')}</span>
                    <div className="ops-figure tone-info">
                        {simData.simulated.retention_pct.toFixed(2)}%
                    </div>
                    <span className="hub-hint">
                        {translate('portal','sim_net_margin',{amount:money(simData.simulated.company_retention,{symbol:false})})}
                    </span>
                </div>
            </div>

            {/* Parameter Adjustment Sliders & Inputs */}
            <div className="panel">
                <div className="ops-mb-5">
                    <h3 className="hub-title is-lg">
                        {translate('portal','sim_adjust_title')}
                    </h3>
                    <p className="hub-sub">
                        {translate('portal','sim_adjust_hint')}
                    </p>
                </div>

                <div className="hub-grid is-xwide">
                    {Object.keys(SLICE_METADATA).map(key => {
                        const meta = SLICE_METADATA[key];
                        const currentVal = rates[key] ?? 0;
                        return (
                            <div key={key} className="ops-card is-soft">
                                <div className="ops-row is-between ops-mb-2">
                                    <span className="hub-strong ops-fs-base">
                                        {translate('portal',meta.labelKey)}
                                    </span>
                                    <div className="ops-row is-tight">
                                        <input
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            max={dealerCap ?? 100}
                                            value={currentVal}
                                            onChange={(e) => handleRateChange(key, parseFloat(e.target.value) || 0)}
                                            className="cs-pct-input sim-pct-input"
                                        />
                                        <span className="ops-fs-sm ops-semibold ops-muted">%</span>
                                    </div>
                                </div>
                                <input
                                    type="range"
                                    min="0"
                                    max={dealerCap ?? 100}
                                    step="0.25"
                                    value={currentVal}
                                    onChange={(e) => handleRateChange(key, parseFloat(e.target.value) || 0)}
                                    className="cs-range"
                                />
                                <div className="ops-meta ops-mt-1">
                                    {translate('portal',meta.descKey)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Slices Breakdown Comparison Table */}
            <div className="panel table-panel">
                <div className="sim-table-head">
                    <h3 className="hub-title">
                        {translate('portal','sim_breakdown_title')}
                    </h3>
                    <span className="ops-fs-sm ops-muted">
                        {translate('portal','sim_breakdown_hint')}
                    </span>
                </div>

                <div className="table-wrap">
                    <table className="sim-table">
                        <thead>
                            <tr>
                                <th>{translate('portal','sim_col_stream')}</th>
                                <th className="is-num">{translate('portal','sim_col_base_pct')}</th>
                                <th className="is-num">{translate('portal','sim_col_sim_pct')}</th>
                                <th className="is-num">{translate('portal','sim_col_delta')}</th>
                                <th className="is-num">{translate('portal','sim_col_base_pay')}</th>
                                <th className="is-num">{translate('portal','sim_col_sim_pay')}</th>
                                <th className="is-num">{translate('portal','sim_col_cash_var')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sliceRows.map(({ slice, currentSimPct, simAmount, deltaPct, deltaAmount }) => {
                                return (
                                    <tr key={slice.key}>
                                        <td className="is-strong">
                                            {SLICE_METADATA[slice.key] ? translate('portal',SLICE_METADATA[slice.key].labelKey) : slice.name}
                                        </td>
                                        <td className="is-num is-muted">
                                            {slice.baseline_pct.toFixed(2)}%
                                        </td>
                                        <td className="is-num is-strong tone-ok">
                                            {currentSimPct.toFixed(2)}%
                                        </td>
                                        <td className={`is-num is-semibold ${signTone(deltaPct) || 'is-muted'}`}>
                                            {deltaPct > 0 ? `+${deltaPct.toFixed(2)}%` : deltaPct < 0 ? `${deltaPct.toFixed(2)}%` : '0.00%'}
                                        </td>
                                        <td className="is-num is-muted">
                                            {money(slice.baseline_amount)}
                                        </td>
                                        <td className="is-num is-strong">
                                            {money(simAmount)}
                                        </td>
                                        <td className={`is-num is-strong ${signTone(deltaAmount) || 'is-muted'}`}>
                                            {signedMoney(deltaAmount)}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td className="is-strong">{translate('portal','sim_total_pool')}</td>
                                <td className="is-num is-muted">{baselinePoolPct.toFixed(2)}%</td>
                                <td className={`is-num ${capTone}`}>
                                    {totalProposedPct.toFixed(2)}%
                                </td>
                                <td className={`is-num ${totalProposedPct > baselinePoolPct ? 'tone-danger' : 'tone-ok'}`}>
                                    {`${totalProposedPct > baselinePoolPct ? '+' : ''}${(totalProposedPct - baselinePoolPct).toFixed(2)}%`}
                                </td>
                                <td className="is-num is-muted">
                                    {money(simData.baseline.total_commissions)}
                                </td>
                                <td className="is-num is-strong">
                                    {money(simulatedPayoutTotal)}
                                </td>
                                <td className={`is-num ${payoutVariance > 0 ? 'tone-danger' : 'tone-ok'}`}>
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
