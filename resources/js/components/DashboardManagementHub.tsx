import React, { useState } from 'react';
import { router, useForm, Link } from '@inertiajs/react';
import {
    SlidersHorizontal, ShieldCheck, Users, Store, Building2,
    AlertTriangle, Bell, CheckCircle2, RotateCcw, Save, Sparkles,
    Laptop, Eye, EyeOff, Lock, Unlock, ArrowRight, UserCheck,
    Layers, ToggleLeft, ToggleRight, Radio, ExternalLink
} from 'lucide-react';
import { Badge, type Row, number } from './UI';
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
            <div className="panel dmh-banner">
                <div className="ops-row is-between is-top is-wrap is-wide">
                    <div>
                        <div className="dmh-eyebrow tone-info">
                            <SlidersHorizontal size={16} />
                            {translate('portal','dash_mgmt_eyebrow')}
                        </div>
                        <h2 className="dmh-title">
                            {translate('portal','dash_mgmt_title')}
                        </h2>
                        <p className="dmh-lead">
                            {translate('portal','dash_mgmt_body')}
                        </p>
                    </div>

                    <div className="ops-row is-wrap is-loose">
                        <button
                            type="button"
                            className="button dmh-action"
                            onClick={handleReset}
                        >
                            <RotateCcw size={15} />
                            {translate('portal','dash_mgmt_restore')}
                        </button>
                        <button
                            type="button"
                            className="button dark dmh-action"
                            onClick={submitSave}
                            disabled={form.processing}
                        >
                            <Save size={15} />
                            {form.processing ? translate('portal','dash_mgmt_saving') : translate('portal','dash_mgmt_save')}
                        </button>
                    </div>
                </div>
            </div>

            {/* 4 Dashboard Overview Cards */}
            <div className="hub-grid ops-mb-6">
                {/* 1. Dealer Dashboard */}
                <div
                    onClick={() => setActiveTab('dealers')}
                    className={`ops-card dmh-card tone-info${activeTab === 'dealers' ? ' is-active' : ''}`}
                >
                    <div className="dmh-card-head">
                        <span className="dmh-card-label">
                            <span aria-hidden="true">🤝</span> {translate('portal','dash_mgmt_card_dealer')}
                        </span>
                        {form.data.settings.dealer_maintenance_mode ? (
                            <span className="dmh-flag tone-danger">
                                {translate('portal','dash_mgmt_badge_locked')}
                            </span>
                        ) : (
                            <span className="dmh-flag tone-ok">
                                {translate('portal','dash_mgmt_badge_active')}
                            </span>
                        )}
                    </div>
                    <div className="ops-figure">
                        {stats.active_dealers} <small>{translate('portal','dash_mgmt_dealers_of',{total:String(stats.dealers_count)})}</small>
                    </div>
                    <p className="ops-meta">
                        {translate('portal','dash_mgmt_card_dealer_hint')}
                    </p>
                </div>

                {/* 2. Sub-Dealer Dashboard */}
                <div
                    onClick={() => setActiveTab('subdealers')}
                    className={`ops-card dmh-card tone-ok${activeTab === 'subdealers' ? ' is-active' : ''}`}
                >
                    <div className="dmh-card-head">
                        <span className="dmh-card-label">
                            <span aria-hidden="true">🏬</span> {translate('portal','dash_mgmt_card_subdealer')}
                        </span>
                        {form.data.settings.subdealer_maintenance_mode ? (
                            <span className="dmh-flag tone-danger">
                                {translate('portal','dash_mgmt_badge_locked')}
                            </span>
                        ) : (
                            <span className="dmh-flag tone-ok">
                                {translate('portal','dash_mgmt_badge_active')}
                            </span>
                        )}
                    </div>
                    <div className="ops-figure">
                        {stats.active_sub_dealers} <small>{translate('portal','dash_mgmt_subdealers_of',{total:String(stats.sub_dealers_count)})}</small>
                    </div>
                    <p className="ops-meta">
                        {translate('portal','dash_mgmt_card_subdealer_hint')}
                    </p>
                </div>

                {/* 3. Employee Dashboard */}
                <div
                    onClick={() => setActiveTab('employees')}
                    className={`ops-card dmh-card tone-teal${activeTab === 'employees' ? ' is-active' : ''}`}
                >
                    <div className="dmh-card-head">
                        <span className="dmh-card-label">
                            <span aria-hidden="true">👥</span> {translate('portal','dash_mgmt_card_employee')}
                        </span>
                        <span className="dmh-flag tone-teal">
                            {translate('portal','dash_mgmt_badge_permitted')}
                        </span>
                    </div>
                    <div className="ops-figure">
                        {stats.active_employees} <small>{translate('portal','dash_mgmt_employees_of',{total:String(stats.employees_count)})}</small>
                    </div>
                    <p className="ops-meta">
                        {translate('portal','dash_mgmt_card_employee_hint')}
                    </p>
                </div>

                {/* 4. Company & Super Dashboard */}
                <div
                    onClick={() => setActiveTab('company')}
                    className={`ops-card dmh-card tone-neutral${activeTab === 'company' ? ' is-active' : ''}`}
                >
                    <div className="dmh-card-head">
                        <span className="dmh-card-label">
                            <span aria-hidden="true">🏢</span> {translate('portal','dash_mgmt_card_company')}
                        </span>
                        <span className="dmh-flag tone-neutral">
                            {translate('portal','dash_mgmt_badge_root')}
                        </span>
                    </div>
                    <div className="ops-figure">
                        {number(stats.super_admins_count)} <small>{translate('portal','dash_mgmt_super_admins')}</small>
                    </div>
                    <p className="ops-meta">
                        {translate('portal','dash_mgmt_card_company_hint')}
                    </p>
                </div>
            </div>

            {/* Sub-Navigation Tabs */}
            <div className="dmh-tabs">
                <button
                    type="button"
                    onClick={() => setActiveTab('dealers')}
                    className={`dmh-tab tone-info${activeTab === 'dealers' ? ' is-active' : ''}`}
                >
                    <span aria-hidden="true">🤝</span> {translate('portal','dash_mgmt_tab_dealer')}
                </button>

                <button
                    type="button"
                    onClick={() => setActiveTab('subdealers')}
                    className={`dmh-tab tone-ok${activeTab === 'subdealers' ? ' is-active' : ''}`}
                >
                    <span aria-hidden="true">🏬</span> {translate('portal','dash_mgmt_tab_subdealer')}
                </button>

                <button
                    type="button"
                    onClick={() => setActiveTab('employees')}
                    className={`dmh-tab tone-teal${activeTab === 'employees' ? ' is-active' : ''}`}
                >
                    <span aria-hidden="true">👥</span> {translate('portal','dash_mgmt_tab_employee')}
                </button>

                <button
                    type="button"
                    onClick={() => setActiveTab('company')}
                    className={`dmh-tab tone-neutral${activeTab === 'company' ? ' is-active' : ''}`}
                >
                    <span aria-hidden="true">🏢</span> {translate('portal','dash_mgmt_tab_company')}
                </button>
            </div>

            {/* TAB CONTENT */}
            <form onSubmit={submitSave} className="action-form">
                {/* 1. DEALER DASHBOARD TAB */}
                {activeTab === 'dealers' && (
                    <div className="ops-stack is-loose">
                        {/* Feature Toggles Card */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_dealer_switches_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_dealer_switches_hint')}
                                    </p>
                                </div>
                                <Layers size={20} className="text-muted" />
                            </div>

                            <div className="dmh-toggles tone-info">
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
                                            className={`dmh-toggle${active ? ' is-on' : ''}`}
                                        >
                                            <div>
                                                <b>{toggle.label}</b>
                                                <small>{toggle.desc}</small>
                                            </div>
                                            <span className="dmh-toggle-dot">
                                                {active ? '●' : '○'}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        {/* Monthly top-sellers board (dealers and sub-dealers) */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_top_sellers_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_top_sellers_hint')}
                                    </p>
                                </div>
                                <Sparkles size={20} className="text-muted" />
                            </div>

                            <div className="dmh-toggles tone-info">
                                {[
                                    { key: 'top_sellers_enabled', label: translate('portal','dash_mgmt_toggle_top_sellers'), desc: translate('portal','dash_mgmt_toggle_top_sellers_desc') },
                                    { key: 'top_sellers_show_amounts', label: translate('portal','dash_mgmt_toggle_top_sellers_amounts'), desc: translate('portal','dash_mgmt_toggle_top_sellers_amounts_desc') },
                                ].map(toggle => {
                                    const active = Boolean(form.data.settings[toggle.key]);
                                    return (
                                        <div
                                            key={toggle.key}
                                            onClick={() => toggleBool(toggle.key)}
                                            className={`dmh-toggle${active ? ' is-on' : ''}`}
                                        >
                                            <div>
                                                <b>{toggle.label}</b>
                                                <small>{toggle.desc}</small>
                                            </div>
                                            <span className="dmh-toggle-dot">
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
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_dealer_announce_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_dealer_announce_hint')}
                                    </p>
                                </div>
                                <Bell size={20} className="tone-info" />
                            </div>

                            <div className="ops-mt-3">
                                <label className="ops-block ops-mb-3">
                                    {translate('portal','dash_mgmt_announce_label')}
                                    <textarea
                                        rows={3}
                                        value={form.data.settings.dealer_announcement || ''}
                                        placeholder={translate('portal','dash_mgmt_announce_placeholder_dealer')}
                                        onChange={e => updateField('dealer_announcement', e.target.value)}
                                        className="ops-full ops-mt-1"
                                    />
                                </label>

                                <div className="ops-row is-wrap is-wide">
                                    <span className="ops-fs-xs ops-semibold ops-text-2">{translate('portal','dash_mgmt_banner_style')}</span>
                                    {['info', 'success', 'warning'].map(t => (
                                        <label key={t} className="dmh-radio">
                                            <input
                                                type="radio"
                                                name="dealer_announcement_type"
                                                checked={form.data.settings.dealer_announcement_type === t}
                                                onChange={() => updateField('dealer_announcement_type', t)}
                                            />
                                            <span className="hub-capital">{t}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        </section>

                        {/* Dealer Maintenance Mode */}
                        <section className="panel dmh-lock">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_dealer_lock_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_dealer_lock_hint')}
                                    </p>
                                </div>
                                <AlertTriangle size={20} className="tone-danger" />
                            </div>

                            <div className="ops-row is-loose ops-mt-3">
                                <button
                                    type="button"
                                    onClick={() => toggleBool('dealer_maintenance_mode')}
                                    className={`button dmh-lock-button ${form.data.settings.dealer_maintenance_mode ? 'dark is-on' : ''}`}
                                >
                                    {form.data.settings.dealer_maintenance_mode ? `🚨 ${translate('portal','dash_mgmt_lockout_on')}` : `✓ ${translate('portal','dash_mgmt_lockout_off')}`}
                                </button>
                                <span className="ops-caption">
                                    {form.data.settings.dealer_maintenance_mode ? translate('portal','dash_mgmt_dealer_lock_on_hint') : translate('portal','dash_mgmt_dealer_lock_off_hint')}
                                </span>
                            </div>
                        </section>
                    </div>
                )}

                {/* 2. SUB-DEALER DASHBOARD TAB */}
                {activeTab === 'subdealers' && (
                    <div className="ops-stack is-loose">
                        {/* Feature Toggles */}
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_sub_switches_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_sub_switches_hint')}
                                    </p>
                                </div>
                                <Store size={20} className="text-muted" />
                            </div>

                            <div className="dmh-toggles tone-ok">
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
                                            className={`dmh-toggle${active ? ' is-on' : ''}`}
                                        >
                                            <div>
                                                <b>{toggle.label}</b>
                                                <small>{toggle.desc}</small>
                                            </div>
                                            <span className="dmh-toggle-dot">
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
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_sub_announce_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_sub_announce_hint')}
                                    </p>
                                </div>
                                <Bell size={20} className="tone-ok" />
                            </div>

                            <div className="ops-mt-3">
                                <label className="ops-block ops-mb-3">
                                    {translate('portal','dash_mgmt_announce_label')}
                                    <textarea
                                        rows={3}
                                        value={form.data.settings.subdealer_announcement || ''}
                                        placeholder={translate('portal','dash_mgmt_announce_placeholder_sub')}
                                        onChange={e => updateField('subdealer_announcement', e.target.value)}
                                        className="ops-full ops-mt-1"
                                    />
                                </label>

                                <div className="ops-row is-wrap is-wide">
                                    <span className="ops-fs-xs ops-semibold ops-text-2">{translate('portal','dash_mgmt_banner_style')}</span>
                                    {['info', 'success', 'warning'].map(t => (
                                        <label key={t} className="dmh-radio">
                                            <input
                                                type="radio"
                                                name="subdealer_announcement_type"
                                                checked={form.data.settings.subdealer_announcement_type === t}
                                                onChange={() => updateField('subdealer_announcement_type', t)}
                                            />
                                            <span className="hub-capital">{t}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        </section>

                        {/* Sub-Dealer Maintenance Mode */}
                        <section className="panel dmh-lock">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_sub_lock_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_sub_lock_hint')}
                                    </p>
                                </div>
                                <AlertTriangle size={20} className="tone-danger" />
                            </div>

                            <div className="ops-row is-loose ops-mt-3">
                                <button
                                    type="button"
                                    onClick={() => toggleBool('subdealer_maintenance_mode')}
                                    className={`button dmh-lock-button ${form.data.settings.subdealer_maintenance_mode ? 'dark is-on' : ''}`}
                                >
                                    {form.data.settings.subdealer_maintenance_mode ? `🚨 ${translate('portal','dash_mgmt_sub_lockout_on')}` : `✓ ${translate('portal','dash_mgmt_lockout_off')}`}
                                </button>
                            </div>
                        </section>
                    </div>
                )}

                {/* 3. EMPLOYEE DASHBOARD TAB */}
                {activeTab === 'employees' && (
                    <div className="ops-stack is-loose">
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_employee_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_employee_hint')}
                                    </p>
                                </div>
                                <Link
                                    href="/employees"
                                    className="button small dark"
                                >
                                    <UserCheck size={16} /> {translate('portal','dash_mgmt_open_employees')}
                                </Link>
                            </div>

                            <div className="ops-mt-3">
                                <label className="ops-block ops-mb-3">
                                    {translate('portal','dash_mgmt_staff_announce_label')}
                                    <textarea
                                        rows={3}
                                        value={form.data.settings.employee_announcement || ''}
                                        placeholder={translate('portal','dash_mgmt_announce_placeholder_staff')}
                                        onChange={e => updateField('employee_announcement', e.target.value)}
                                        className="ops-full ops-mt-1"
                                    />
                                </label>
                            </div>

                            {/* Recent employees list */}
                            {recent_employees.length > 0 && (
                                <div className="ops-mt-4">
                                    <h4 className="dmh-subhead">
                                        {translate('portal','dash_mgmt_recent_employees')}
                                    </h4>
                                    <div className="dmh-people">
                                        {recent_employees.map(emp => {
                                            const perms: string[] = Array.isArray(emp.profile?.permissions) ? emp.profile.permissions : [];
                                            return (
                                                <div key={emp.id} className="ops-card is-soft is-tight">
                                                    <div className="ops-row is-between">
                                                        <b className="ops-fs-xs hub-strong">{emp.name}</b>
                                                        <Badge status={emp.status} />
                                                    </div>
                                                    <small className="ops-meta ops-block dmh-email">{emp.email}</small>
                                                    <div className="ops-row is-wrap is-tight ops-mt-2">
                                                        {perms.slice(0, 4).map(p => (
                                                            <span key={p} className="dmh-perm">
                                                                {p}
                                                            </span>
                                                        ))}
                                                        {perms.length > 4 && (
                                                            <span className="ops-meta ops-semibold">{translate('portal','dash_mgmt_more_perms',{count:String(perms.length - 4)})}</span>
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
                    <div className="ops-stack is-loose">
                        <section className="panel">
                            <div className="panel-heading">
                                <div>
                                    <h3 className="hub-title">
                                        {translate('portal','dash_mgmt_company_title')}
                                    </h3>
                                    <p className="hub-sub">
                                        {translate('portal','dash_mgmt_company_hint')}
                                    </p>
                                </div>
                                <Building2 size={20} className="text-muted" />
                            </div>

                            <div className="dmh-toggles tone-neutral">
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
                                            className={`dmh-toggle${active ? ' is-on' : ''}`}
                                        >
                                            <div>
                                                <b>{toggle.label}</b>
                                                <small>{toggle.desc}</small>
                                            </div>
                                            <span className="dmh-toggle-dot">
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
                <div className="ops-row is-end is-loose dmh-footer">
                    <button
                        type="button"
                        className="button"
                        onClick={handleReset}
                    >
                        <RotateCcw size={15} /> {translate('portal','dash_mgmt_reset_footer')}
                    </button>
                    <button
                        type="submit"
                        className="button dark dmh-submit"
                        disabled={form.processing}
                    >
                        <Save size={16} /> {form.processing ? translate('portal','dash_mgmt_saving') : translate('portal','dash_mgmt_save')}
                    </button>
                </div>
            </form>
        </div>
    );
}
