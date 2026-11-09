import React, { useState } from 'react';
import { router, useForm, Link } from '@inertiajs/react';
import { RotateCcw, UserCheck } from 'lucide-react';
import { Badge, number, type Row } from './UI';
import OfficePage from './office/OfficePage';
import { settingsBack } from './office/sections/SettingsForm';
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

type TabKey = 'dealers' | 'subdealers' | 'employees' | 'company';

export default function DashboardManagementHub({ settings, defaults, stats, recent_employees = [] }: DashboardManagementHubProps) {
    const translate = useT();
    const [activeTab, setActiveTab] = useState<TabKey>('dealers');

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
        if (confirm(translate('portal', 'dash_mgmt_reset_confirm'))) {
            router.post('/commands/dashboards/reset');
        }
    };

    const tabs: { key: TabKey; label: string; count: string; locked: boolean }[] = [
        { key: 'dealers', label: translate('office_settings', 'dash_tab_dealers'), count: `${stats.active_dealers}/${stats.dealers_count}`, locked: Boolean(form.data.settings.dealer_maintenance_mode) },
        { key: 'subdealers', label: translate('office_settings', 'dash_tab_subdealers'), count: `${stats.active_sub_dealers}/${stats.sub_dealers_count}`, locked: Boolean(form.data.settings.subdealer_maintenance_mode) },
        { key: 'employees', label: translate('office_settings', 'dash_tab_employees'), count: `${stats.active_employees}/${stats.employees_count}`, locked: false },
        { key: 'company', label: translate('office_settings', 'dash_tab_company'), count: '', locked: false },
    ];

    const toggles = (items: { key: string; label: string; desc: string }[]) => (
        <div className="set-toggles">
            {items.map((toggle) => (
                <label key={toggle.key} className="set-toggle">
                    <input type="checkbox" role="switch" checked={Boolean(form.data.settings[toggle.key])} onChange={() => toggleBool(toggle.key)} />
                    <span><b>{toggle.label}</b><small>{toggle.desc}</small></span>
                </label>
            ))}
        </div>
    );

    const announcement = (fieldKey: string, label: string, placeholder: string, typeKey?: string) => (
        <>
            <label>
                {label}
                <textarea
                    rows={3}
                    value={form.data.settings[fieldKey] || ''}
                    placeholder={placeholder}
                    onChange={(event) => updateField(fieldKey, event.target.value)}
                />
            </label>
            {typeKey && (
                <div className="set-radios">
                    <span>{translate('portal', 'dash_mgmt_banner_style')}</span>
                    {['info', 'success', 'warning'].map((tone) => (
                        <label key={tone}>
                            <input type="radio" name={typeKey} checked={form.data.settings[typeKey] === tone} onChange={() => updateField(typeKey, tone)} />
                            {translate('portal', `dash_mgmt_banner_style_${tone}`)}
                        </label>
                    ))}
                </div>
            )}
        </>
    );

    const lockSwitch = (fieldKey: string, onLabel: string, offLabel: string, onHint?: string, offHint?: string) => {
        const locked = Boolean(form.data.settings[fieldKey]);
        return (
            <label className="aa-switch">
                <input type="checkbox" role="switch" checked={locked} onChange={() => toggleBool(fieldKey)} />
                <span><b>{locked ? onLabel : offLabel}</b>{(locked ? onHint : offHint) && <small>{locked ? onHint : offHint}</small>}</span>
            </label>
        );
    };

    return (
        <OfficePage title={translate('portal', 'dashboards')} back={settingsBack(translate)}>
            <div className="set-segments" role="tablist" aria-label={translate('portal', 'dashboards')}>
                {tabs.map((tab) => (
                    <button key={tab.key} type="button" role="tab" aria-selected={activeTab === tab.key} aria-pressed={activeTab === tab.key} onClick={() => setActiveTab(tab.key)}>
                        {tab.label} {tab.count && <span className="set-count">{tab.count}</span>}
                        {tab.locked && <span className="badge inactive">{translate('portal', 'dash_mgmt_badge_locked')}</span>}
                    </button>
                ))}
            </div>
            <form onSubmit={submitSave} className="action-form set-sheet">
                {activeTab === 'dealers' && (
                    <>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_dealer_switches_title')}</h2>
                            {toggles([
                                { key: 'dealer_network_tree_enabled', label: translate('portal', 'dash_mgmt_toggle_network'), desc: translate('portal', 'dash_mgmt_toggle_network_desc') },
                                { key: 'dealer_ranks_enabled', label: translate('portal', 'dash_mgmt_toggle_ranks'), desc: translate('portal', 'dash_mgmt_toggle_ranks_desc') },
                                { key: 'dealer_rewards_enabled', label: translate('portal', 'dash_mgmt_toggle_rewards'), desc: translate('portal', 'dash_mgmt_toggle_rewards_desc') },
                                { key: 'dealer_orders_enabled', label: translate('portal', 'dash_mgmt_toggle_orders'), desc: translate('portal', 'dash_mgmt_toggle_orders_desc') },
                                { key: 'dealer_claims_enabled', label: translate('portal', 'dash_mgmt_toggle_claims'), desc: translate('portal', 'dash_mgmt_toggle_claims_desc') },
                                { key: 'dealer_catalog_enabled', label: translate('portal', 'dash_mgmt_toggle_catalog'), desc: translate('portal', 'dash_mgmt_toggle_catalog_desc') },
                            ])}
                        </section>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_top_sellers_title')}</h2>
                            {toggles([
                                { key: 'top_sellers_enabled', label: translate('portal', 'dash_mgmt_toggle_top_sellers'), desc: translate('portal', 'dash_mgmt_toggle_top_sellers_desc') },
                                { key: 'top_sellers_show_amounts', label: translate('portal', 'dash_mgmt_toggle_top_sellers_amounts'), desc: translate('portal', 'dash_mgmt_toggle_top_sellers_amounts_desc') },
                            ])}
                        </section>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_dealer_announce_title')}</h2>
                            {announcement('dealer_announcement', translate('portal', 'dash_mgmt_announce_label'), translate('portal', 'dash_mgmt_announce_placeholder_dealer'), 'dealer_announcement_type')}
                        </section>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_dealer_lock_title')}</h2>
                            {lockSwitch('dealer_maintenance_mode', translate('portal', 'dash_mgmt_lockout_on'), translate('portal', 'dash_mgmt_lockout_off'), translate('portal', 'dash_mgmt_dealer_lock_on_hint'), translate('portal', 'dash_mgmt_dealer_lock_off_hint'))}
                        </section>
                    </>
                )}

                {activeTab === 'subdealers' && (
                    <>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_sub_switches_title')}</h2>
                            {toggles([
                                { key: 'subdealer_salary_enabled', label: translate('portal', 'dash_mgmt_toggle_sub_salary'), desc: translate('portal', 'dash_mgmt_toggle_sub_salary_desc') },
                                { key: 'subdealer_orders_enabled', label: translate('portal', 'dash_mgmt_toggle_sub_orders'), desc: translate('portal', 'dash_mgmt_toggle_sub_orders_desc') },
                                { key: 'subdealer_deliveries_enabled', label: translate('portal', 'dash_mgmt_toggle_sub_deliveries'), desc: translate('portal', 'dash_mgmt_toggle_sub_deliveries_desc') },
                                { key: 'subdealer_catalog_enabled', label: translate('portal', 'dash_mgmt_toggle_sub_catalog'), desc: translate('portal', 'dash_mgmt_toggle_sub_catalog_desc') },
                            ])}
                        </section>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_sub_announce_title')}</h2>
                            {announcement('subdealer_announcement', translate('portal', 'dash_mgmt_announce_label'), translate('portal', 'dash_mgmt_announce_placeholder_sub'), 'subdealer_announcement_type')}
                        </section>
                        <section className="set-block">
                            <h2>{translate('portal', 'dash_mgmt_sub_lock_title')}</h2>
                            {lockSwitch('subdealer_maintenance_mode', translate('portal', 'dash_mgmt_sub_lockout_on'), translate('portal', 'dash_mgmt_lockout_off'))}
                        </section>
                    </>
                )}

                {activeTab === 'employees' && (
                    <section className="set-block">
                        <div className="set-provider-head">
                            <h2>{translate('portal', 'dash_mgmt_employee_title')}</h2>
                            <Link href="/employees" className="button small"><UserCheck size={16} /> {translate('portal', 'dash_mgmt_open_employees')}</Link>
                        </div>
                        {announcement('employee_announcement', translate('portal', 'dash_mgmt_staff_announce_label'), translate('portal', 'dash_mgmt_announce_placeholder_staff'))}
                        {recent_employees.length > 0 && (
                            <div className="ops-mt-4">
                                <h3>{translate('portal', 'dash_mgmt_recent_employees')}</h3>
                                <ul className="set-people">
                                    {recent_employees.map((emp) => {
                                        const perms: string[] = Array.isArray(emp.profile?.permissions) ? emp.profile.permissions : [];
                                        return (
                                            <li key={emp.id}>
                                                <div className="set-provider-head"><b>{emp.name}</b><Badge status={emp.status} /></div>
                                                <small className="cell-sub">{emp.email}</small>
                                                {perms.length > 0 && <small className="cell-sub">{perms.slice(0, 4).join(', ')}{perms.length > 4 ? ` · ${translate('portal', 'dash_mgmt_more_perms', { count: String(perms.length - 4) })}` : ''}</small>}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )}
                    </section>
                )}

                {activeTab === 'company' && (
                    <section className="set-block">
                        <h2>{translate('portal', 'dash_mgmt_company_title')}</h2>
                        <p className="set-line">{number(stats.super_admins_count)} {translate('portal', 'dash_mgmt_super_admins')}</p>
                        {toggles([
                            { key: 'birthday_sms_enabled', label: translate('portal', 'dash_mgmt_toggle_birthday_sms'), desc: translate('portal', 'dash_mgmt_toggle_birthday_sms_desc') },
                        ])}
                        <label>
                            {translate('portal', 'dash_mgmt_support_overdue_hours')}
                            <input type="number" min={1} max={720} step={1} value={form.data.settings.support_overdue_hours ?? 24} onChange={(event) => updateField('support_overdue_hours', event.target.value)} />
                            <small className="muted">{translate('portal', 'dash_mgmt_support_overdue_hours_hint')}</small>
                        </label>
                        <label>
                            {translate('dealer_tools', 'setting_inactive_days')}
                            <input type="number" min={7} max={365} step={1} value={form.data.settings.partner_inactive_days ?? 30} onChange={(event) => updateField('partner_inactive_days', event.target.value)} />
                            <small className="muted">{translate('dealer_tools', 'setting_inactive_days_hint')}</small>
                        </label>
                    </section>
                )}

                <div className="set-foot">
                    <button type="submit" className="button dark" disabled={form.processing}>
                        {form.processing ? translate('portal', 'dash_mgmt_saving') : translate('portal', 'dash_mgmt_save')}
                    </button>
                    <button type="button" className="button" onClick={handleReset}>
                        <RotateCcw size={15} /> {translate('portal', 'dash_mgmt_reset_footer')}
                    </button>
                </div>
            </form>
        </OfficePage>
    );
}
