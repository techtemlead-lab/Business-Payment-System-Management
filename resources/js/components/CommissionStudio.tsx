import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { SlidersHorizontal, Percent, ShieldCheck, AlertCircle, RefreshCw, Calculator, Check, ArrowRight } from 'lucide-react';
import { money } from './UI';
import { useT } from '../shared/i18n/t';

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

export default function CommissionStudio({ rules: initialRules }: { rules: RuleRecord[] }) {
    const translate = useT();
    const {staffActions} = usePage<any>().props;
    const canEdit = staffActions?.finance?.edit ?? true;
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

    // Calculate sum of percentages
    const baseKeys = ['own_dealer_cashback','direct_referral','d2d_multitier','area_dealer_pool','dealer_monthly_salary','rank_royalty','rank_incentive','company_marketing','car_bike_incentive'];
    const totalPercentage = Object.values(rules).filter(r=>r.is_active&&baseKeys.includes(r.rule_key)).reduce((sum, r) => sum + (Number(r.percentage) || 0), 0);
    const subDealerPercentage = totalPercentage + Object.values(rules).filter(r=>r.is_active&&['additional_rank','sub_dealer_salary','sub_dealer_tour'].includes(r.rule_key)).reduce((sum,r)=>sum+Number(r.percentage),0);
    const isOverAllocated = totalPercentage > 40.0001 || subDealerPercentage > 50.0001;
    const remainingBuffer = Math.max(0, 40.00 - totalPercentage);

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
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top Zero-Deficit Budget Guard Header */}
            <div style={{
                background: isOverAllocated ? '#fef2f2' : '#f0fdf4',
                border: `1.5px solid ${isOverAllocated ? '#fca5a5' : '#a7f3d0'}`,
                borderRadius: 12,
                padding: '16px 20px',
                boxShadow: '0 2px 8px rgba(0,0,0,0.04)'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
                    <div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 700, color: isOverAllocated ? '#b91c1c' : '#047857', textTransform: 'uppercase' }}>
                            {isOverAllocated ? <AlertCircle size={18} /> : <ShieldCheck size={18} />}
                            {isOverAllocated ? translate('portal','cs_violation') : translate('portal','cs_guarantee')}
                        </div>
                        <div style={{ fontSize: 24, fontWeight: 800, color: isOverAllocated ? '#dc2626' : '#006837', marginTop: 2 }}>
                            {totalPercentage.toFixed(2)}% <span style={{ fontSize: 14, color: '#64748b', fontWeight: 500 }}>/ 40.00% {translate('portal','cs_dealer_pool')} · {translate('portal','cs_sub_dealer')} {subDealerPercentage.toFixed(2)}% / 50.00%</span>
                        </div>
                        <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
                            {isOverAllocated
                                ? translate('portal','cs_review_limits')
                                : translate('portal','cs_editable_hint')}
                        </div>
                    </div>

                    <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                        <div style={{ display: 'flex', background: '#e2e8f0', borderRadius: 8, padding: 3 }}>
                            <button
                                type="button"
                                onClick={() => setActiveTab('editor')}
                                style={{
                                    padding: '6px 14px', fontSize: 12, fontWeight: 700, borderRadius: 6,
                                    background: activeTab === 'editor' ? '#ffffff' : 'transparent',
                                    color: activeTab === 'editor' ? '#006837' : '#64748b',
                                    border: 'none', cursor: 'pointer'
                                }}
                            >
                                <SlidersHorizontal size={14} style={{ display: 'inline', marginRight: 4 }} /> {translate('portal','cs_tab_editor')}
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('simulator')}
                                style={{
                                    padding: '6px 14px', fontSize: 12, fontWeight: 700, borderRadius: 6,
                                    background: activeTab === 'simulator' ? '#ffffff' : 'transparent',
                                    color: activeTab === 'simulator' ? '#006837' : '#64748b',
                                    border: 'none', cursor: 'pointer'
                                }}
                            >
                                <Calculator size={14} style={{ display: 'inline', marginRight: 4 }} /> {translate('portal','cs_tab_simulator')}
                            </button>
                        </div>

                        <button
                            type="button"
                            disabled={!canEdit || isOverAllocated || saving}
                            onClick={handleSave}
                            style={{
                                padding: '9px 20px',
                                background: isOverAllocated ? '#cbd5e1' : '#006837',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 8,
                                fontWeight: 700,
                                fontSize: 13,
                                cursor: isOverAllocated || saving ? 'not-allowed' : 'pointer',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                boxShadow: isOverAllocated ? 'none' : '0 2px 6px rgba(0,104,55,0.25)'
                            }}
                        >
                            {saving ? <RefreshCw size={15} className="spin" /> : <Check size={16} />}
                            {saving ? translate('portal','cs_saving') : translate('portal','cs_save')}
                        </button>
                    </div>
                </div>

                {/* Progress bar */}
                <div style={{ width: '100%', height: 8, background: '#e2e8f0', borderRadius: 4, marginTop: 14, overflow: 'hidden' }}>
                    <div
                        style={{
                            width: `${Math.min(100, (totalPercentage / 40.00) * 100)}%`,
                            height: '100%',
                            background: isOverAllocated ? '#ef4444' : '#006837',
                            transition: 'width 0.2s ease, background 0.2s ease'
                        }}
                    />
                </div>
            </div>

            {/* TAB 1: RULES EDITOR */}
            {activeTab === 'editor' && (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(340px, 1fr))', gap: 16 }}>
                    {Object.values(rules).length === 0 && (
                        <div style={{ gridColumn: '1 / -1', padding: 24, border: '1px dashed #cbd5e1', borderRadius: 12, background: '#f8fafc', color: '#64748b', fontSize: 13 }}>
                            {translate('portal','cs_no_rules')}
                        </div>
                    )}
                    {Object.values(rules).map(r => {
                        const pct = Number(r.percentage) || 0;
                        return (
                            <div key={r.rule_key} style={{
                                background: '#ffffff',
                                border: '1.5px solid #e2e8f0',
                                borderRadius: 12,
                                padding: '16px 18px',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'space-between',
                                boxShadow: '0 1px 3px rgba(0,0,0,0.03)'
                            }}>
                                <div>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                                        <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.5, color: '#047857', background: '#ecfdf5', padding: '2px 8px', borderRadius: 4 }}>
                                            {r.category}
                                        </span>
                                        <span style={{ fontSize: 11, color: '#94a3b8', fontWeight: 600 }}>v{r.version}</span>
                                    </div>
                                    <h3 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', margin: '4px 0' }}>{(() => { const localized = translate('portal', `rule_name_${r.rule_key}`); return localized !== `rule_name_${r.rule_key}` ? localized : r.name; })()}</h3>
                                    <p style={{ fontSize: 12, color: '#64748b', margin: '4px 0 14px 0', lineHeight: 1.4 }}>{r.description}</p>
                                </div>

                                <div style={{ borderTop: '1px solid #f1f5f9', paddingTop: 12 }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                        <label style={{ fontSize: 12, fontWeight: 700, color: '#334155' }}>
                                            {translate('portal','cs_allocated_pct')}
                                        </label>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                            <input
                                                type="number"
                                                min="0"
                                                max="40"
                                                step="0.5"
                                                value={r.percentage}
                                                onChange={e => handlePercentageChange(r.rule_key, e.target.value)}
                                                style={{
                                                    width: 68,
                                                    padding: '4px 8px',
                                                    border: '1.5px solid #cbd5e1',
                                                    borderRadius: 6,
                                                    fontWeight: 800,
                                                    fontSize: 14,
                                                    color: '#006837',
                                                    textAlign: 'right'
                                                }}
                                            />
                                            <span style={{ fontSize: 13, fontWeight: 700, color: '#64748b' }}>%</span>
                                        </div>
                                    </div>

                                    {/* Slider */}
                                    <input
                                        type="range"
                                        min="0"
                                        max="20"
                                        step="0.25"
                                        value={pct}
                                        onChange={e => handlePercentageChange(r.rule_key, e.target.value)}
                                        style={{ width: '100%', accentColor: '#006837', cursor: 'pointer' }}
                                    />
                                    {Object.entries(r.parameters??{}).filter(([key,value])=>key!=='dynamic_compression'&&typeof value!=='object').map(([key,value])=><label key={key} style={{display:'block',fontSize:11,marginTop:8}}>{key.replaceAll('_',' ')}<input type={typeof value==='boolean'?'checkbox':'number'} step="0.01" checked={typeof value==='boolean'?value:undefined} value={typeof value==='boolean'?undefined:String(value)} onChange={e=>setRules(prev=>({...prev,[r.rule_key]:{...prev[r.rule_key],parameters:{...prev[r.rule_key].parameters,[key]:typeof value==='boolean'?e.target.checked:e.target.value}}}))} style={{marginLeft:8,maxWidth:130}}/></label>)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* TAB 2: LIVE SIMULATOR */}
            {activeTab === 'simulator' && (
                <div style={{ background: '#ffffff', border: '1.5px solid #e2e8f0', borderRadius: 12, padding: 22, boxShadow: '0 2px 10px rgba(0,0,0,0.04)' }}>
                    <div style={{ maxWidth: 450, marginBottom: 24 }}>
                        <label style={{ display: 'block', fontSize: 13, fontWeight: 700, color: '#0f172a', marginBottom: 6 }}>
                            {translate('portal','cs_preview_purchase')}
                        </label>
                        <div style={{ position: 'relative' }}>
                            <span style={{ position: 'absolute', left: 12, top: 11, fontSize: 15, fontWeight: 700, color: '#006837' }}>৳</span>
                            <input
                                type="number"
                                min="10"
                                step="10"
                                value={simOrderAmount}
                                onChange={e => setSimOrderAmount(Math.max(0, Number(e.target.value) || 0))}
                                style={{ width: '100%', padding: '10px 14px 10px 32px', fontSize: 16, fontWeight: 800, color: '#006837', border: '1.5px solid #a7f3d0', borderRadius: 8, background: '#f0fdf4' }}
                            />
                        </div>
                        <label style={{display:'block',marginTop:12}}>{translate('portal','cs_account')} <select value={simAccount} onChange={e=>setSimAccount(e.target.value as 'dealer'|'sub_dealer')}><option value="dealer">{translate('portal','cs_dealer')}</option><option value="sub_dealer">{translate('portal','cs_sub_dealer')}</option></select></label>
                        <label style={{display:'block',marginTop:12}}>{translate('portal','cs_total_mrp')} <input type="number" min="0" step="0.01" value={simMrp} onChange={e=>setSimMrp(Math.max(0,Number(e.target.value)||0))}/></label>
                        <p style={{fontSize:12,color:simMrp*.3+simOrderAmount*(simAccount==='dealer'?totalPercentage:subDealerPercentage)/100>simOrderAmount?'#b91c1c':'#047857'}}>{translate('portal','cs_preview_only')} {simMrp*.3+simOrderAmount*(simAccount==='dealer'?totalPercentage:subDealerPercentage)/100>simOrderAmount?translate('portal','cs_preview_underfunded'):translate('portal','cs_preview_funded')}</p>
                    </div>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 16 }}>
                        {/* Company Retained Card */}
                        <div style={{ background: '#f8fafc', border: '1.5px solid #cbd5e1', borderRadius: 10, padding: 16 }}>
                            <div style={{ fontSize: 12, fontWeight: 700, color: '#475569', textTransform: 'uppercase' }}>{translate('portal','cs_company_retained')}</div>
                            <div style={{ fontSize: 22, fontWeight: 800, color: '#0f172a', margin: '4px 0' }}>
                                ৳ {(simMrp * 0.30).toFixed(2)}
                            </div>
                            <div style={{ fontSize: 11.5, color: '#64748b' }}>{translate('portal','cs_of_mrp',{pct:'30.00'})}</div>
                        </div>

                        {/* Each of the 9 pools */}
                        {Object.values(rules).filter(r=>r.is_active&&(baseKeys.includes(r.rule_key)||(simAccount==='sub_dealer'&&r.category==='sub_dealer'))).map(r => {
                            const pct = Number(r.percentage) || 0;
                            const amount = (simOrderAmount * (pct / 100));
                            return (
                                <div key={r.rule_key} style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                        <span style={{ fontSize: 12, fontWeight: 700, color: '#047857' }}>{r.name}</span>
                                        <span style={{ fontSize: 12, fontWeight: 700, color: '#64748b' }}>{pct.toFixed(1)}%</span>
                                    </div>
                                    <div style={{ fontSize: 20, fontWeight: 800, color: '#006837', margin: '4px 0' }}>
                                        ৳ {amount.toFixed(2)}
                                    </div>
                                    <div style={{ fontSize: 11, color: '#94a3b8' }}>{r.description}</div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
