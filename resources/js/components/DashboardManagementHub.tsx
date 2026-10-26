import React, { useState } from 'react';
import { router, useForm, Link } from '@inertiajs/react';
import {
    SlidersHorizontal, ShieldCheck, Users, Store, Building2,
    AlertTriangle, Bell, CheckCircle2, RotateCcw, Save, Sparkles,
    Laptop, Eye, EyeOff, Lock, Unlock, ArrowRight, UserCheck,
    Layers, ToggleLeft, ToggleRight, Radio, ExternalLink
} from 'lucide-react';
import { Badge, type Row } from './UI';
import { useT } from '../shared/i18n/t';

export interface DashboardManagementHubProps {
    settings: Record<string, any>;
    defaults: Record<string, any>;
    stats: {
        dealers_count: number;
        active_dealers: number;
        sub_dealers_count: number;
        active_sub_dealers: number;
        employees_count: number;
        active_employees: number;
        super_admins_count: number;
    };
    recent_employees?: Row[];
}

export default function DashboardManagementHub({ settings, defaults, stats, recent_employees = [] }: DashboardManagementHubProps) {
    const translate = useT();
    const [activeTab, setActiveTab] = useState<'dealers' | 'subdealers' | 'employees' | 'company'>('dealers');

    const form = useForm({
        settings: {
            ...defaults,
            ...settings,
        }
    });

    const updateField = (key: string, value: any) => {
        form.setData('settings', {
            ...form.data.settings,
            [key]: value,
        });
    };

    const toggleBool = (key: string) => {
        updateField(key, !form.data.settings[key]);
    };

    const submitSave = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/commands/dashboards', {
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        if (confirm(translate('portal','dash_mgmt_reset_confirm'))) {
            router.post('/commands/dashboards/reset');
        }
    };

    return (
        <div className="dashboard-management-hub">
            {/* Top Heading Banner */}
            <div className="panel" style={{ marginBottom: 20, borderLeft: '4px solid #2e3192', background: '#ffffff' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
                    <div>
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 700, color: '#2e3192', textTransform: 'uppercase', marginBottom: 4 }}>
                            <SlidersHorizontal size={16} />
                            {translate('portal','dash_mgmt_eyebrow')}
                        </div>
                        <h2 style={{ margin: '0 0 6px 0', fontSize: 22, fontWeight: 800, color: '#0f172a' }}>
                            {translate('portal','dash_mgmt_title')}
                        </h2>
                        <p style={{ margin: 0, color: '#64748b', fontSize: 13, maxWidth: 820 }}>
                            {translate('portal','dash_mgmt_body')}
                        </p>
                    </div>

                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                        <button
                            type="button"
                            className="button"
                            onClick={handleReset}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}
                        >
                            <RotateCcw size={15} />
                            {translate('portal','dash_mgmt_restore')}
                        </button>
                        <button
                            type="button"
                            className="button dark"
                            onClick={submitSave}
                            disabled={form.processing}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700, fontSize: 12 }}
                        >
                            <Save size={15} />
                            {form.processing ? translate('portal','dash_mgmt_saving') : translate('portal','dash_mgmt_save')}
                        </button>
                    </div>
                </div>
            </div>

            {/* 4 Dashboard Overview Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16, marginBottom: 24 }}>
                {/* 1. Dealer Dashboard */}
                <div
                    onClick={() => setActiveTab('dealers')}
                    style={{
                        background: '#ffffff',
                        border: activeTab === 'dealers' ? '2px solid #2e3192' : '1px solid #e2e8f0',
                        borderRadius: 10,
                        padding: '16px 18px',
                        cursor: 'pointer',
                        boxShadow: activeTab === 'dealers' ? '0 4px 12px rgba(46, 49, 146, 0.08)' : 'none',
                        transition: 'all 0.15s ease'
                    }}
                >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <span style={{ fontSize: 12, fontWeight: 700, color: '#2e3192', display: 'flex', alignItems: 'center', gap: 6 }}>
                            <span aria-hidden="true">🤝</span> {translate('portal','dash_mgmt_card_dealer')}
                        </span>
                        {form.data.settings.dealer_maintenance_mode ? (
                            <span style={{ background: '#fee2e2', color: '#b91c1c', fontSize: 10, fontWeight: 700, padding: '2px 6px', borderRadius: 4 }}>
                                {translate('portal','dash_mgmt_badge_locked')}
                            </span>
                        ) : (
                            <span style={{ background: '#ecfdf5', color: '#047857', fontSize: 10, fontWeight: 700, padding: '2px 6px', borderRadius: 4 }}>
                                {translate('portal','dash_mgmt_badge_active')}
                            </span>
                        )}
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a' }}>
                        {stats.active_dealers} <small style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>{translate('portal','dash_mgmt_dealers_of',{total:String(stats.dealers_count)})}</small>
                    </div>
                    <p style={{ margin: '6px 0 0 0', fontSize: 11, color: '#64748b' }}>
                        {translate('portal','dash_mgmt_card_dealer_hint')}
                    </p>
                </div>

                {/* 2. Sub-Dealer Dashboard */}
                <div
                    onClick={() => setActiveTab('subdealers')}
                    style={{
                        background: '#ffffff',
                        border: activeTab === 'subdealers' ? '2px solid #059669' : '1px solid #e2e8f0',
                        borderRadius: 10,
                        padding: '16px 18px',
                        cursor: 'pointer',
                        boxShadow: activeTab === 'subdealers' ? '0 4px 12px rgba(5, 150, 105, 0.08)' : 'none',
                        transition: 'all 0.15s ease'
                    }}
                >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <span style={{ fontSize: 12, fontWeight: 700, color: '#059669', display: 'flex', alignItems: 'center', gap: 6 }}>
                            <span aria-hidden="true">🏬</span> {translate('portal','dash_mgmt_card_subdealer')}
                        </span>
                        {form.data.settings.subdealer_maintenance_mode ? (
                            <span style={{ background: '#fee2e2', color: '#b91c1c', fontSize: 10, fontWeight: 700, padding: '2px 6px', borderRadius: 4 }}>
                                {translate('portal','dash_mgmt_badge_locked')}
                            </span>
                        ) : (
                            <span style={{ background: '#ecfdf5', color: '#047857', fontSize: 10, fontWeight: 700, padding: '2px 6px', borderRadius: 4 }}>
                                {translate('portal','dash_mgmt_badge_active')}
                            </span>
                        )}
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a' }}>
                        {stats.active_sub_dealers} <small style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>{translate('portal','dash_mgmt_subdealers_of',{total:String(stats.sub_dealers_count)})}</small>
                    </div>
                    <p style={{ margin: '6px 0 0 0', fontSize: 11, color: '#64748b' }}>
                        {translate('portal','dash_mgmt_card_subdealer_hint')}
                    </p>
                </div>

                {/* 3. Employee Dashboard */}
                <div
                    onClick={() => setActiveTab('employees')}
                    style={{
                        background: '#ffffff',
                        border: activeTab === 'employees' ? '2px solid #0284c7' : '1px solid #e2e8f0',
                        borderRadius: 10,
                        padding: '16px 18px',
                        cursor: 'pointer',
                        boxShadow: activeTab === 'employees' ? '0 4px 12px rgba(2, 132, 199, 0.08)' : 'none',
                        transition: 'all 0.15s ease'
                    }}
                >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <span style={{ fontSize: 12, fontWeight: 700, color: '#0284c7', display: 'flex', alignItems: 'center', gap: 6 }}>
                            <span aria-hidden="true">👥</span> {translate('portal','dash_mgmt_card_employee')}
                        </span>
                        <span style={{ background: '#e0f2fe', color: '#0369a1', fontSize: 10, fontWeight: 700, padding: '2px 6px', borderRadius: 4 }}>
                            {translate('portal','dash_mgmt_badge_permitted')}
                        </span>
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a' }}>
                        {stats.active_employees} <small style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>{translate('portal','dash_mgmt_employees_of',{total:String(stats.employees_count)})}</small>
                    </div>
                    <p style={{ margin: '6px 0 0 0', fontSize: 11, color: '#64748b' }}>
                        {translate('portal','dash_mgmt_card_employee_hint')}
                    </p>
                </div>

                {/* 4. Company & Super Dashboard */}
                <div
                    onClick={() => setActiveTab('company')}
                    style={{
                        background: '#ffffff',
                        border: activeTab === 'company' ? '2px solid #011627' : '1px solid #e2e8f0',
                        borderRadius: 10,
                        padding: '16px 18px',
                        cursor: 'pointer',
                        boxShadow: activeTab === 'company' ? '0 4px 12px rgba(1, 22, 39, 0.08)' : 'none',
                        transition: 'all 0.15s ease'
                    }}
                >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <span style={{ fontSize: 12, fontWeight: 700, color: '#011627', display: 'flex', alignItems: 'center', gap: 6 }}>
                            <span aria-hidden="true">🏢</span> {translate('portal','dash_mgmt_card_company')}
                        </span>
                        <span style={{ background: '#f1f5f9', color: '#334155', fontSize: 10, fontWeight: 700, padding: '2px 6px', borderRadius: 4 }}>
                            {translate('portal','dash_mgmt_badge_root')}
                        </span>
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a' }}>
                        {stats.super_admins_count} <small style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>{translate('portal','dash_mgmt_super_admins')}</small>
                    </div>
                    <p style={{ margin: '6px 0 0 0', fontSize: 11, color: '#64748b' }}>
                        {translate('portal','dash_mgmt_card_company_hint')}
                    </p>
                </div>
            </div>

            {/* Sub-Navigation Tabs */}
            <div style={{ display: 'flex', gap: 8, borderBottom: '2px solid #e2e8f0', marginBottom: 20, flexWrap: 'wrap' }}>
                <button
                    type="button"
                    onClick={() => setActiveTab('dealers')}
                    style={{
                        padding: '10px 18px',
                        fontSize: 13,
                        fontWeight: 700,
                        border: 'none',
                        background: 'none',
                        cursor: 'pointer',
                        borderBottom: activeTab === 'dealers' ? '3px solid #2e3192' : '3px solid transparent',
                        color: activeTab === 'dealers' ? '#2e3192' : '#64748b',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                    }}
                >
                    <span aria-hidden="true">🤝</span> {translate('portal','dash_mgmt_tab_dealer')}
                </button>

                <button
                    type="button"
                    onClick={() => setActiveTab('subdealers')}
                    style={{
                        padding: '10px 18px',
                        fontSize: 13,
                        fontWeight: 700,
                        border: 'none',
                        background: 'none',
                        cursor: 'pointer',
                        borderBottom: activeTab === 'subdealers' ? '3px solid #059669' : '3px solid transparent',
                        color: activeTab === 'subdealers' ? '#059669' : '#64748b',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                    }}
                >
                    <span aria-hidden="true">🏬</span> {translate('portal','dash_mgmt_tab_subdealer')}
                </button>

                <button
                    type="button"
                    onClick={() => setActiveTab('employees')}
                    style={{
                        padding: '10px 18px',
                        fontSize: 13,
                        fontWeight: 700,
                        border: 'none',
                        background: 'none',
                        cursor: 'pointer',
                        borderBottom: activeTab === 'employees' ? '3px solid #0284c7' : '3px solid transparent',
                        color: activeTab === 'employees' ? '#0284c7' : '#64748b',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                    }}
                >
                    <span aria-hidden="true">👥</span> {translate('portal','dash_mgmt_tab_employee')}
                </button>

                <button
                    type="button"
                    onClick={() => setActiveTab('company')}
                    style={{
                        padding: '10px 18px',
                        fontSize: 13,
                        fontWeight: 700,
                        border: 'none',
                        background: 'none',
                        cursor: 'pointer',
                        borderBottom: activeTab === 'company' ? '3px solid #011627' : '3px solid transparent',
                        color: activeTab === 'company' ? '#011627' : '#64748b',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                    }}
                >
                    <span aria-hidden="true">🏢</span> {translate('portal','dash_mgmt_tab_company')}
                </button>
            </div>

            {/* TAB CONTENT */}
            <form onSubmit={submitSave} className="action-form">
                {/* 1. DEALER DASHBOARD TAB */}
                {activeTab === 'dealers' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                        {/* Feature Toggles Card */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
                                        {translate('portal','dash_mgmt_dealer_switches_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_dealer_switches_hint')}
                                    </p>
                                </div>
                                <Layers size={20} className="text-muted" />
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 12, marginTop: 12 }}>
                                {[
                                    { key: 'dealer_network_tree_enabled', label: translate('portal','dash_mgmt_toggle_network'), desc: translate('portal','dash_mgmt_toggle_network_desc') },
                                    { key: 'dealer_ranks_enabled', label: translate('portal','dash_mgmt_toggle_ranks'), desc: translate('portal','dash_mgmt_toggle_ranks_desc') },
                                    { key: 'dealer_rewards_enabled', label: translate('portal','dash_mgmt_toggle_rewards'), desc: translate('portal','dash_mgmt_toggle_rewards_desc') },
                                    { key: 'dealer_orders_enabled', label: translate('portal','dash_mgmt_toggle_orders'), desc: translate('portal','dash_mgmt_toggle_orders_desc') },
                                    { key: 'dealer_claims_enabled', label: translate('portal','dash_mgmt_toggle_claims'), desc: translate('portal','dash_mgmt_toggle_claims_desc') },
                                    { key: 'dealer_catalog_enabled', label: translate('portal','dash_mgmt_toggle_catalog'), desc: translate('portal','dash_mgmt_toggle_catalog_desc') },
                                ].map(toggle => {
                                    const active = Boolean(form.data.settings[toggle.key]);
                                    return (
                                        <div
                                            key={toggle.key}
                                            onClick={() => toggleBool(toggle.key)}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'flex-start',
                                                justifyContent: 'space-between',
                                                gap: 12,
                                                padding: '12px 14px',
                                                borderRadius: 8,
                                                border: active ? '1px solid #c7d2fe' : '1px solid #e2e8f0',
                                                background: active ? '#f8faff' : '#ffffff',
                                                cursor: 'pointer',
                                                userSelect: 'none'
                                            }}
                                        >
                                            <div>
                                                <b style={{ fontSize: 13, color: active ? '#1e1b4b' : '#334155', display: 'block' }}>{toggle.label}</b>
                                                <small style={{ fontSize: 11, color: '#64748b', display: 'block', marginTop: 2, lineHeight: 1.3 }}>{toggle.desc}</small>
                                            </div>
                                            <span style={{ fontSize: 20, color: active ? '#2e3192' : '#cbd5e1', cursor: 'pointer' }}>
                                                {active ? '●' : '○'}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        {/* Broadcast Announcement to Dealers */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
                                        {translate('portal','dash_mgmt_dealer_announce_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_dealer_announce_hint')}
                                    </p>
                                </div>
                                <Bell size={20} color="#2e3192" />
                            </div>

                            <div style={{ marginTop: 12 }}>
                                <label style={{ display: 'block', marginBottom: 10 }}>
                                    {translate('portal','dash_mgmt_announce_label')}
                                    <textarea
                                        rows={3}
                                        value={form.data.settings.dealer_announcement || ''}
                                        placeholder={translate('portal','dash_mgmt_announce_placeholder_dealer')}
                                        onChange={e => updateField('dealer_announcement', e.target.value)}
                                        style={{ width: '100%', marginTop: 4 }}
                                    />
                                </label>

                                <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <span style={{ fontSize: 12, fontWeight: 600, color: '#475569' }}>{translate('portal','dash_mgmt_banner_style')}</span>
                                    {['info', 'success', 'warning'].map(t => (
                                        <label key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                                            <input
                                                type="radio"
                                                name="dealer_announcement_type"
                                                checked={form.data.settings.dealer_announcement_type === t}
                                                onChange={() => updateField('dealer_announcement_type', t)}
                                            />
                                            <span style={{ textTransform: 'capitalize' }}>{t}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        </section>

                        {/* Dealer Maintenance Mode */}
                        <section className="panel" style={{ borderLeft: '4px solid #e11d48' }}>
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#9f1239' }}>
                                        {translate('portal','dash_mgmt_dealer_lock_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_dealer_lock_hint')}
                                    </p>
                                </div>
                                <AlertTriangle size={20} color="#e11d48" />
                            </div>

                            <div style={{ marginTop: 12, display: 'flex', alignItems: 'center', gap: 12 }}>
                                <button
                                    type="button"
                                    onClick={() => toggleBool('dealer_maintenance_mode')}
                                    className={`button ${form.data.settings.dealer_maintenance_mode ? 'dark' : ''}`}
                                    style={{
                                        background: form.data.settings.dealer_maintenance_mode ? '#9f1239' : '#ffffff',
                                        color: form.data.settings.dealer_maintenance_mode ? '#ffffff' : '#0f172a',
                                        borderColor: '#e2e8f0',
                                        fontSize: 12,
                                        fontWeight: 700
                                    }}
                                >
                                    {form.data.settings.dealer_maintenance_mode ? `🚨 ${translate('portal','dash_mgmt_lockout_on')}` : `✓ ${translate('portal','dash_mgmt_lockout_off')}`}
                                </button>
                                <span style={{ fontSize: 12, color: '#64748b' }}>
                                    {form.data.settings.dealer_maintenance_mode ? translate('portal','dash_mgmt_dealer_lock_on_hint') : translate('portal','dash_mgmt_dealer_lock_off_hint')}
                                </span>
                            </div>
                        </section>
                    </div>
                )}

                {/* 2. SUB-DEALER DASHBOARD TAB */}
                {activeTab === 'subdealers' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                        {/* Feature Toggles */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
                                        {translate('portal','dash_mgmt_sub_switches_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_sub_switches_hint')}
                                    </p>
                                </div>
                                <Store size={20} className="text-muted" />
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 12, marginTop: 12 }}>
                                {[
                                    { key: 'subdealer_salary_enabled', label: translate('portal','dash_mgmt_toggle_sub_salary'), desc: translate('portal','dash_mgmt_toggle_sub_salary_desc') },
                                    { key: 'subdealer_orders_enabled', label: translate('portal','dash_mgmt_toggle_sub_orders'), desc: translate('portal','dash_mgmt_toggle_sub_orders_desc') },
                                    { key: 'subdealer_deliveries_enabled', label: translate('portal','dash_mgmt_toggle_sub_deliveries'), desc: translate('portal','dash_mgmt_toggle_sub_deliveries_desc') },
                                    { key: 'subdealer_catalog_enabled', label: translate('portal','dash_mgmt_toggle_sub_catalog'), desc: translate('portal','dash_mgmt_toggle_sub_catalog_desc') },
                                ].map(toggle => {
                                    const active = Boolean(form.data.settings[toggle.key]);
                                    return (
                                        <div
                                            key={toggle.key}
                                            onClick={() => toggleBool(toggle.key)}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'flex-start',
                                                justifyContent: 'space-between',
                                                gap: 12,
                                                padding: '12px 14px',
                                                borderRadius: 8,
                                                border: active ? '1px solid #a7f3d0' : '1px solid #e2e8f0',
                                                background: active ? '#f0fdf4' : '#ffffff',
                                                cursor: 'pointer',
                                                userSelect: 'none'
                                            }}
                                        >
                                            <div>
                                                <b style={{ fontSize: 13, color: active ? '#064e3b' : '#334155', display: 'block' }}>{toggle.label}</b>
                                                <small style={{ fontSize: 11, color: '#64748b', display: 'block', marginTop: 2, lineHeight: 1.3 }}>{toggle.desc}</small>
                                            </div>
                                            <span style={{ fontSize: 20, color: active ? '#059669' : '#cbd5e1', cursor: 'pointer' }}>
                                                {active ? '●' : '○'}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        {/* Broadcast Announcement to Sub-Dealers */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
                                        {translate('portal','dash_mgmt_sub_announce_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_sub_announce_hint')}
                                    </p>
                                </div>
                                <Bell size={20} color="#059669" />
                            </div>

                            <div style={{ marginTop: 12 }}>
                                <label style={{ display: 'block', marginBottom: 10 }}>
                                    {translate('portal','dash_mgmt_announce_label')}
                                    <textarea
                                        rows={3}
                                        value={form.data.settings.subdealer_announcement || ''}
                                        placeholder={translate('portal','dash_mgmt_announce_placeholder_sub')}
                                        onChange={e => updateField('subdealer_announcement', e.target.value)}
                                        style={{ width: '100%', marginTop: 4 }}
                                    />
                                </label>

                                <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <span style={{ fontSize: 12, fontWeight: 600, color: '#475569' }}>{translate('portal','dash_mgmt_banner_style')}</span>
                                    {['info', 'success', 'warning'].map(t => (
                                        <label key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                                            <input
                                                type="radio"
                                                name="subdealer_announcement_type"
                                                checked={form.data.settings.subdealer_announcement_type === t}
                                                onChange={() => updateField('subdealer_announcement_type', t)}
                                            />
                                            <span style={{ textTransform: 'capitalize' }}>{t}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        </section>

                        {/* Sub-Dealer Maintenance Mode */}
                        <section className="panel" style={{ borderLeft: '4px solid #e11d48' }}>
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#9f1239' }}>
                                        {translate('portal','dash_mgmt_sub_lock_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_sub_lock_hint')}
                                    </p>
                                </div>
                                <AlertTriangle size={20} color="#e11d48" />
                            </div>

                            <div style={{ marginTop: 12, display: 'flex', alignItems: 'center', gap: 12 }}>
                                <button
                                    type="button"
                                    onClick={() => toggleBool('subdealer_maintenance_mode')}
                                    className={`button ${form.data.settings.subdealer_maintenance_mode ? 'dark' : ''}`}
                                    style={{
                                        background: form.data.settings.subdealer_maintenance_mode ? '#9f1239' : '#ffffff',
                                        color: form.data.settings.subdealer_maintenance_mode ? '#ffffff' : '#0f172a',
                                        borderColor: '#e2e8f0',
                                        fontSize: 12,
                                        fontWeight: 700
                                    }}
                                >
                                    {form.data.settings.subdealer_maintenance_mode ? `🚨 ${translate('portal','dash_mgmt_sub_lockout_on')}` : `✓ ${translate('portal','dash_mgmt_lockout_off')}`}
                                </button>
                            </div>
                        </section>
                    </div>
                )}

                {/* 3. EMPLOYEE DASHBOARD TAB */}
                {activeTab === 'employees' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
                                        {translate('portal','dash_mgmt_employee_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_employee_hint')}
                                    </p>
                                </div>
                                <Link
                                    href="/employees"
                                    className="button small dark"
                                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
                                >
                                    <UserCheck size={16} /> {translate('portal','dash_mgmt_open_employees')}
                                </Link>
                            </div>

                            <div style={{ marginTop: 14 }}>
                                <label style={{ display: 'block', marginBottom: 12 }}>
                                    {translate('portal','dash_mgmt_staff_announce_label')}
                                    <textarea
                                        rows={3}
                                        value={form.data.settings.employee_announcement || ''}
                                        placeholder={translate('portal','dash_mgmt_announce_placeholder_staff')}
                                        onChange={e => updateField('employee_announcement', e.target.value)}
                                        style={{ width: '100%', marginTop: 4 }}
                                    />
                                </label>
                            </div>

                            {/* Recent employees list */}
                            {recent_employees.length > 0 && (
                                <div style={{ marginTop: 16 }}>
                                    <h4 style={{ fontSize: 13, fontWeight: 700, margin: '0 0 8px 0', color: '#334155' }}>
                                        {translate('portal','dash_mgmt_recent_employees')}
                                    </h4>
                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 10 }}>
                                        {recent_employees.map(emp => {
                                            const perms: string[] = Array.isArray(emp.profile?.permissions) ? emp.profile.permissions : [];
                                            return (
                                                <div key={emp.id} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, padding: '10px 12px' }}>
                                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                        <b style={{ fontSize: 12, color: '#0f172a' }}>{emp.name}</b>
                                                        <Badge status={emp.status} />
                                                    </div>
                                                    <small style={{ color: '#64748b', fontSize: 11, display: 'block', marginTop: 2 }}>{emp.email}</small>
                                                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginTop: 6 }}>
                                                        {perms.slice(0, 4).map(p => (
                                                            <span key={p} style={{ fontSize: 9, background: '#e2e8f0', padding: '1px 5px', borderRadius: 3, fontWeight: 600 }}>
                                                                {p}
                                                            </span>
                                                        ))}
                                                        {perms.length > 4 && (
                                                            <span style={{ fontSize: 9, color: '#64748b', fontWeight: 600 }}>{translate('portal','dash_mgmt_more_perms',{count:String(perms.length - 4)})}</span>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </section>
                    </div>
                )}

                {/* 4. COMPANY HUB TAB */}
                {activeTab === 'company' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
                                        {translate('portal','dash_mgmt_company_title')}
                                    </h3>
                                    <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                        {translate('portal','dash_mgmt_company_hint')}
                                    </p>
                                </div>
                                <Building2 size={20} className="text-muted" />
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 12, marginTop: 12 }}>
                                {[
                                    { key: 'company_treasury_enabled', label: translate('portal','dash_mgmt_toggle_treasury'), desc: translate('portal','dash_mgmt_toggle_treasury_desc') },
                                    { key: 'company_commissions_enabled', label: translate('portal','dash_mgmt_toggle_commissions'), desc: translate('portal','dash_mgmt_toggle_commissions_desc') },
                                    { key: 'company_customization_enabled', label: translate('portal','dash_mgmt_toggle_cms'), desc: translate('portal','dash_mgmt_toggle_cms_desc') },
                                    { key: 'company_audit_enabled', label: translate('portal','dash_mgmt_toggle_audit'), desc: translate('portal','dash_mgmt_toggle_audit_desc') },
                                ].map(toggle => {
                                    const active = Boolean(form.data.settings[toggle.key]);
                                    return (
                                        <div
                                            key={toggle.key}
                                            onClick={() => toggleBool(toggle.key)}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'flex-start',
                                                justifyContent: 'space-between',
                                                gap: 12,
                                                padding: '12px 14px',
                                                borderRadius: 8,
                                                border: active ? '1px solid #cbd5e1' : '1px solid #e2e8f0',
                                                background: active ? '#f8fafc' : '#ffffff',
                                                cursor: 'pointer',
                                                userSelect: 'none'
                                            }}
                                        >
                                            <div>
                                                <b style={{ fontSize: 13, color: active ? '#0f172a' : '#475569', display: 'block' }}>{toggle.label}</b>
                                                <small style={{ fontSize: 11, color: '#64748b', display: 'block', marginTop: 2, lineHeight: 1.3 }}>{toggle.desc}</small>
                                            </div>
                                            <span style={{ fontSize: 20, color: active ? '#011627' : '#cbd5e1', cursor: 'pointer' }}>
                                                {active ? '●' : '○'}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    </div>
                )}

                {/* Bottom Action Footer */}
                <div style={{
                    marginTop: 24,
                    paddingTop: 16,
                    borderTop: '1px solid #e2e8f0',
                    display: 'flex',
                    justifyContent: 'flex-end',
                    gap: 12,
                    alignItems: 'center'
                }}>
                    <button
                        type="button"
                        className="button"
                        onClick={handleReset}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
                    >
                        <RotateCcw size={15} /> {translate('portal','dash_mgmt_reset_footer')}
                    </button>
                    <button
                        type="submit"
                        className="button dark"
                        disabled={form.processing}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700, padding: '10px 20px' }}
                    >
                        <Save size={16} /> {form.processing ? translate('portal','dash_mgmt_saving') : translate('portal','dash_mgmt_save')}
                    </button>
                </div>
            </form>
        </div>
    );
}
