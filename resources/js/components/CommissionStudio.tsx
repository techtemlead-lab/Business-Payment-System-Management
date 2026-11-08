import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { SlidersHorizontal, ShieldCheck, AlertCircle, RefreshCw, Calculator, Check } from 'lucide-react';
import { money, label, humanize } from './UI';
import { useT } from '../shared/i18n/t';

/** Pool limits the backend commission policy enforces (CommissionsSection::commissionPoolLimits). */
export interface PolicyLimits {
    dealer_pool_cap_pct?: string | number | null;
    sub_dealer_pool_cap_pct?: string | number | null;
    company_mrp_pct?: string | number | null;
    dealer_rule_keys?: string[];
    sub_dealer_rule_keys?: string[];
}

/** A policy percentage, or null when the backend did not send one. */
export function policyPercent(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
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
    version: number;
}

export default function CommissionStudio({ rules: initialRules, limits }: { rules: RuleRecord[]; limits?: PolicyLimits }) {
    const translate = useT();
    const paramLabel = (key: string) => {
        const localized = translate('portal', `cs_param_${key}`);
        return localized === `cs_param_${key}` ? humanize(key) : localized;
    };
    const {staffActions, data: pageData, auth} = usePage<any>().props;
    // Only the owner's own changes apply at once; everyone else's wait for the owner's approval.
    const needsOwnerApproval = auth?.user?.role !== 'super_admin';
    const canEdit = staffActions?.finance?.edit ?? true;
    const policyLimits: PolicyLimits = limits ?? pageData?.policy_limits ?? {};
    const dealerCap = policyPercent(policyLimits.dealer_pool_cap_pct);
    const subDealerCap = policyPercent(policyLimits.sub_dealer_pool_cap_pct);
    const companyMrpPct = policyPercent(policyLimits.company_mrp_pct);
    const notSet = translate('portal', 'policy_value_not_set');
    const capLabel = (cap: number | null) => cap === null ? notSet : `${cap.toFixed(2)}%`;
    const [rules, setRules] = useState<Record<string, RuleRecord>>(() => {
        const map: Record<string, RuleRecord> = {};
        initialRules.forEach(r => { map[r.rule_key] = { ...r }; });
        return map;
    });

    const [simOrderAmount, setSimOrderAmount] = useState<number>(1000);
    const [simMrp, setSimMrp] = useState<number>(2000);
    const [simAccount, setSimAccount] = useState<'dealer'|'sub_dealer'>('dealer');
    const [saving, setSaving] = useState(false);
    const [activeTab, setActiveTab] = useState<'editor' | 'simulator'>('editor');

    // Which rules fill the dealer pool and which are sub-dealer extras comes from the policy.
    const subDealerKeys = policyLimits.sub_dealer_rule_keys
        ?? Object.values(rules).filter(r => r.category === 'sub_dealer').map(r => r.rule_key);
    const baseKeys = policyLimits.dealer_rule_keys
        ?? Object.values(rules).filter(r => r.category !== 'operations' && !subDealerKeys.includes(r.rule_key)).map(r => r.rule_key);
    const totalPercentage = Object.values(rules).filter(r=>r.is_active&&baseKeys.includes(r.rule_key)).reduce((sum, r) => sum + (Number(r.percentage) || 0), 0);
    const subDealerPercentage = totalPercentage + Object.values(rules).filter(r=>r.is_active&&subDealerKeys.includes(r.rule_key)).reduce((sum,r)=>sum+(Number(r.percentage) || 0),0);
    const isOverAllocated = (dealerCap !== null && totalPercentage > dealerCap + 0.0001)
        || (subDealerCap !== null && subDealerPercentage > subDealerCap + 0.0001);
    const simulatedPoolPct = simAccount === 'dealer' ? totalPercentage : subDealerPercentage;
    const isPreviewUnderfunded = companyMrpPct !== null
        && simMrp * companyMrpPct / 100 + simOrderAmount * simulatedPoolPct / 100 > simOrderAmount;

    const handlePercentageChange = (key: string, val: string) => {
        const num = parseFloat(val) || 0;
        setRules(prev => ({
            ...prev,
            [key]: { ...prev[key], percentage: Math.max(0, Math.min(100, num)) }
        }));
    };

    const handleSave = () => {
        if (isOverAllocated) return;
        setSaving(true);
        router.post('/commands/commissions/rules', { rules: rules as any }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="hub">
            {/* Top Zero-Deficit Budget Guard Header */}
            <div className={`panel is-tinted cs-guard ${isOverAllocated ? 'tone-danger' : 'tone-ok'}`}>
                <div className="hub-row is-between">
                    <div>
                        <div className="hub-flag">
                            {isOverAllocated ? <AlertCircle size={18} /> : <ShieldCheck size={18} />}
                            {isOverAllocated ? translate('portal','cs_violation') : translate('portal','cs_guarantee')}
                        </div>
                        <div className="cs-guard-value">
                            {totalPercentage.toFixed(2)}% <span>/ {capLabel(dealerCap)} {translate('portal','cs_dealer_pool')} · {translate('portal','cs_sub_dealer')} {subDealerPercentage.toFixed(2)}% / {capLabel(subDealerCap)}</span>
                        </div>
                        <div className="hub-sub">
                            {isOverAllocated
                                ? translate('portal','cs_review_limits')
                                : translate('portal','cs_editable_hint')}
                        </div>
                        <div className="hub-sub">
                            {needsOwnerApproval
                                ? translate('portal','cs_owner_approval_needed')
                                : translate('portal','cs_owner_applies_now')}{' '}
                            <a className="text-link" href="/operations/policy-schedules">{translate('portal','cs_pending_changes_link')}</a>
                        </div>
                    </div>

                    <div className="hub-row">
                        <div className="hub-seg">
                            <button
                                type="button"
                                onClick={() => setActiveTab('editor')}
                                className={activeTab === 'editor' ? 'is-active' : undefined}
                            >
                                <SlidersHorizontal size={14} /> {translate('portal','cs_tab_editor')}
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('simulator')}
                                className={activeTab === 'simulator' ? 'is-active' : undefined}
                            >
                                <Calculator size={14} /> {translate('portal','cs_tab_simulator')}
                            </button>
                        </div>

                        <button
                            type="button"
                            disabled={!canEdit || isOverAllocated || saving}
                            onClick={handleSave}
                            className="button dark"
                        >
                            {saving ? <RefreshCw size={15} className="spin" /> : <Check size={16} />}
                            {saving ? translate('portal','cs_saving') : translate('portal','cs_save')}
                        </button>
                    </div>
                </div>

                {/* Progress bar */}
                <div className="hub-progress">
                    <div
                        className={isOverAllocated ? 'tone-danger' : undefined}
                        style={{ width: `${dealerCap ? Math.min(100, (totalPercentage / dealerCap) * 100) : 0}%` }}
                    />
                </div>
            </div>

            {/* TAB 1: RULES EDITOR */}
            {activeTab === 'editor' && (
                <div className="hub-grid is-xwide">
                    {Object.values(rules).length === 0 && (
                        <div className="hub-empty hub-span-all">
                            {translate('portal','cs_no_rules')}
                        </div>
                    )}
                    {Object.values(rules).map(r => {
                        const pct = Number(r.percentage) || 0;
                        return (
                            <div key={r.rule_key} className="hub-tile hub-col cs-rule">
                                <div>
                                    <div className="hub-row is-between">
                                        <span className="hub-pill tone-brand">
                                            {label(String(r.category ?? ""), translate)}
                                        </span>
                                        <span className="hub-label">v{r.version}</span>
                                    </div>
                                    <h3 className="hub-title is-sm">{(() => { const localized = translate('portal', `rule_name_${r.rule_key}`); return localized !== `rule_name_${r.rule_key}` ? localized : r.name; })()}</h3>
                                    <p className="hub-hint">{r.description}</p>
                                </div>

                                <div className="cs-rule-foot">
                                    <div className="hub-row is-between">
                                        <label>
                                            {translate('portal','cs_allocated_pct')}
                                        </label>
                                        <div className="hub-inline">
                                            <input
                                                type="number"
                                                min="0"
                                                max={dealerCap ?? 100}
                                                step="0.5"
                                                value={r.percentage}
                                                onChange={e => handlePercentageChange(r.rule_key, e.target.value)}
                                                className="cs-pct-input"
                                            />
                                            <span className="hub-label">%</span>
                                        </div>
                                    </div>

                                    {/* Slider */}
                                    <input
                                        type="range"
                                        min="0"
                                        max={dealerCap ?? 100}
                                        step="0.25"
                                        value={pct}
                                        onChange={e => handlePercentageChange(r.rule_key, e.target.value)}
                                        className="cs-range"
                                    />
                                    {Object.entries(r.parameters??{}).filter(([key,value])=>key!=='dynamic_compression'&&(value===null||typeof value!=='object')).map(([key,value])=>{
                                        const isAwardAmount = key.endsWith('_award_amount');
                                        return <label key={key} className="cs-param">{paramLabel(key)}<input type={typeof value==='boolean'?'checkbox':'number'} step="0.01" min={isAwardAmount?'0':undefined} placeholder={isAwardAmount?translate('portal','cs_award_amount_not_set'):undefined} checked={typeof value==='boolean'?value:undefined} value={typeof value==='boolean'?undefined:String(value??'')} onChange={e=>setRules(prev=>({...prev,[r.rule_key]:{...prev[r.rule_key],parameters:{...prev[r.rule_key].parameters,[key]:typeof value==='boolean'?e.target.checked:e.target.value}}}))}/></label>;
                                    })}
                                    {r.rule_key==='car_bike_incentive'&&<p className="hub-hint">{translate('portal','cs_award_amount_hint')}</p>}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* TAB 2: LIVE SIMULATOR */}
            {activeTab === 'simulator' && (
                <div className="panel">
                    <div className="cs-sim-form">
                        <label>
                            {translate('portal','cs_preview_purchase')}
                        </label>
                        <div className="cs-money">
                            <span>৳</span>
                            <input
                                type="number"
                                min="10"
                                step="10"
                                value={simOrderAmount}
                                onChange={e => setSimOrderAmount(Math.max(0, Number(e.target.value) || 0))}
                            />
                        </div>
                        <label>{translate('portal','cs_account')} <select value={simAccount} onChange={e=>setSimAccount(e.target.value as 'dealer'|'sub_dealer')}><option value="dealer">{translate('portal','cs_dealer')}</option><option value="sub_dealer">{translate('portal','cs_sub_dealer')}</option></select></label>
                        <label>{translate('portal','cs_total_mrp')} <input type="number" min="0" step="0.01" value={simMrp} onChange={e=>setSimMrp(Math.max(0,Number(e.target.value)||0))}/></label>
                        <p className={`cs-preview ${companyMrpPct===null?'tone-warn':isPreviewUnderfunded?'tone-danger':'tone-ok'}`}>{translate('portal','cs_preview_only')} {companyMrpPct===null?`${translate('portal','cs_company_retained')}: ${notSet}`:isPreviewUnderfunded?translate('portal','cs_preview_underfunded'):translate('portal','cs_preview_funded')}</p>
                    </div>

                    <div className="hub-grid is-wide">
                        {/* Company Retained Card */}
                        <div className="hub-tile is-soft">
                            <div className="hub-label">{translate('portal','cs_company_retained')}</div>
                            <div className={`hub-value${companyMrpPct === null ? ' tone-warn' : ''}`}>
                                {companyMrpPct === null ? notSet : money(simMrp * companyMrpPct / 100)}
                            </div>
                            <div className="hub-hint">{companyMrpPct === null ? notSet : translate('portal','cs_of_mrp',{pct:companyMrpPct.toFixed(2)})}</div>
                        </div>

                        {/* Each of the 9 pools */}
                        {Object.values(rules).filter(r=>r.is_active&&(baseKeys.includes(r.rule_key)||(simAccount==='sub_dealer'&&subDealerKeys.includes(r.rule_key)))).map(r => {
                            const pct = Number(r.percentage) || 0;
                            const amount = (simOrderAmount * (pct / 100));
                            return (
                                <div key={r.rule_key} className="hub-tile">
                                    <div className="hub-tile-head">
                                        <span className="hub-label tone-ok">{r.name}</span>
                                        <span className="hub-label">{pct.toFixed(1)}%</span>
                                    </div>
                                    <div className="hub-value tone-brand">
                                        {money(amount)}
                                    </div>
                                    <div className="hub-hint">{r.description}</div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
