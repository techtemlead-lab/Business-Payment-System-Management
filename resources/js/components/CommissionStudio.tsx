import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ShieldCheck, AlertCircle, RefreshCw, Check } from 'lucide-react';
import { label, humanize } from './UI';
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
    const notSet = translate('portal', 'policy_value_not_set');
    const capLabel = (cap: number | null) => cap === null ? notSet : `${cap.toFixed(2)}%`;
    const [rules, setRules] = useState<Record<string, RuleRecord>>(() => {
        const map: Record<string, RuleRecord> = {};
        initialRules.forEach(r => { map[r.rule_key] = { ...r }; });
        return map;
    });

    const [saving, setSaving] = useState(false);

    // Which rules fill the dealer pool and which are sub-dealer extras comes from the policy.
    const subDealerKeys = policyLimits.sub_dealer_rule_keys
        ?? Object.values(rules).filter(r => r.category === 'sub_dealer').map(r => r.rule_key);
    const baseKeys = policyLimits.dealer_rule_keys
        ?? Object.values(rules).filter(r => r.category !== 'operations' && !subDealerKeys.includes(r.rule_key)).map(r => r.rule_key);
    const totalPercentage = Object.values(rules).filter(r=>r.is_active&&baseKeys.includes(r.rule_key)).reduce((sum, r) => sum + (Number(r.percentage) || 0), 0);
    const subDealerPercentage = totalPercentage + Object.values(rules).filter(r=>r.is_active&&subDealerKeys.includes(r.rule_key)).reduce((sum,r)=>sum+(Number(r.percentage) || 0),0);
    const isOverAllocated = (dealerCap !== null && totalPercentage > dealerCap + 0.0001)
        || (subDealerCap !== null && subDealerPercentage > subDealerCap + 0.0001);

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
                        {canEdit && (
                            <div className="hub-sub">
                                {isOverAllocated
                                    ? translate('portal','cs_review_limits')
                                    : translate('portal','cs_editable_hint')}
                            </div>
                        )}
                        <div className="hub-sub">
                            {canEdit && (needsOwnerApproval
                                ? translate('portal','cs_owner_approval_needed')
                                : translate('portal','cs_owner_applies_now'))}{' '}
                            <a className="text-link" href="/operations/policy-schedules">{translate('portal','cs_pending_changes_link')}</a>
                        </div>
                    </div>

                    {canEdit && (
                        <div className="hub-row">
                            <button
                                type="button"
                                disabled={isOverAllocated || saving}
                                onClick={handleSave}
                                className="button dark"
                            >
                                {saving ? <RefreshCw size={15} className="spin" /> : <Check size={16} />}
                                {saving ? translate('portal','cs_saving') : translate('portal','cs_save')}
                            </button>
                        </div>
                    )}
                </div>

                {/* Progress bar */}
                <div className="hub-progress">
                    <div
                        className={isOverAllocated ? 'tone-danger' : undefined}
                        style={{ width: `${dealerCap ? Math.min(100, (totalPercentage / dealerCap) * 100) : 0}%` }}
                    />
                </div>
            </div>

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
                                            disabled={!canEdit}
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
                                    disabled={!canEdit}
                                    onChange={e => handlePercentageChange(r.rule_key, e.target.value)}
                                    className="cs-range"
                                />
                                {Object.entries(r.parameters??{}).filter(([key,value])=>key!=='dynamic_compression'&&(value===null||typeof value!=='object')).map(([key,value])=>{
                                    const isAwardAmount = key.endsWith('_award_amount');
                                    return <label key={key} className="cs-param">{paramLabel(key)}<input type={typeof value==='boolean'?'checkbox':'number'} step="0.01" disabled={!canEdit} min={isAwardAmount?'0':undefined} placeholder={isAwardAmount?translate('portal','cs_award_amount_not_set'):undefined} checked={typeof value==='boolean'?value:undefined} value={typeof value==='boolean'?undefined:String(value??'')} onChange={e=>setRules(prev=>({...prev,[r.rule_key]:{...prev[r.rule_key],parameters:{...prev[r.rule_key].parameters,[key]:typeof value==='boolean'?e.target.checked:e.target.value}}}))}/></label>;
                                })}
                                {r.rule_key==='car_bike_incentive'&&<p className="hub-hint">{translate('portal','cs_award_amount_hint')}</p>}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
