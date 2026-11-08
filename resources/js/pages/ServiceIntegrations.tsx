import React, { useMemo, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PlugZap } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Row, humanize } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Provider = Row & { key: string; label: string; fields: string[]; enabled: boolean };
type Group = { label: string; description: string; providers: Record<string, Provider> };

const secretFields = new Set(['api_key', 'api_secret', 'app_key', 'app_secret', 'password', 'username', 'client_secret', 'store_password']);


function ProviderForm({ group, provider, version, canEdit }: { group: string; provider: Provider; version: number; canEdit: boolean }) {
    const translate = useT();
    const [balanceMsg, setBalanceMsg] = useState('');
    const [balanceBusy, setBalanceBusy] = useState(false);
    const defaults = useMemo(() => {
        const data: Row = { group, provider: provider.key, version, reason: '', enabled: provider.enabled ? '1' : '0' };
        for (const field of provider.fields) {
            if (field === 'enabled') continue;
            data[field] = secretFields.has(field) ? '' : (provider[field] ?? '');
        }
        return data;
    }, [group, provider, version]);
    const form = useForm(defaults);

    /** A provider can name a field its own way, such as Greenweb's "token". */
    const fieldLabel = (field: string): string => {
        for (const key of [`si_field_${provider.key}_${field}`, `si_field_${field}`]) {
            const label = translate('portal', key);
            if (label !== key) {
                return label;
            }
        }
        return humanize(field);
    };

    const checkBalance = async () => {
        setBalanceBusy(true);
        setBalanceMsg('');
        try {
            const response = await fetch(`/commands/operations/integrations/sms-balance?provider=${encodeURIComponent(provider.key)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const json = await response.json();
            setBalanceMsg(String(json?.data?.message || translate('portal', 'si_sms_balance_unavailable')));
        } catch {
            setBalanceMsg(translate('portal', 'si_sms_balance_unavailable'));
        } finally {
            setBalanceBusy(false);
        }
    };

    return (
        <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.transform(input => ({
                ...input,
                enabled: input.enabled === true || input.enabled === '1',
                version,
            }));
            form.post('/commands/operations/integrations/providers', { preserveScroll: true });
        }}>
            <div className="profile-grid ops-mb-3">
                <div><strong>{provider.label}</strong><p className="muted">{translate('portal', 'si_provider_key')}: {provider.key}</p></div>
                <div><Badge status={provider.enabled ? 'active' : 'inactive'} /></div>
            </div>
            <label>{translate('portal', 'si_enabled')}
                <select value={String(form.data.enabled)} onChange={e => form.setData('enabled', e.target.value)} disabled={!canEdit}>
                    <option value="0">{translate('portal', 'si_disabled_default')}</option>
                    <option value="1">{translate('portal', 'si_enabled_option')}</option>
                </select>
            </label>
            {provider.fields.filter(field => field !== 'enabled').map(field => (
                <label key={field}>{fieldLabel(field)}
                    {field === 'mode' ? (
                        <select value={String(form.data[field] ?? 'sandbox')} onChange={e => form.setData(field, e.target.value)} disabled={!canEdit}>
                            <option value="sandbox">{translate('portal', 'si_mode_sandbox')}</option>
                            <option value="live">{translate('portal', 'si_mode_live')}</option>
                        </select>
                    ) : field === 'encryption' ? (
                        <select value={String(form.data[field] || 'tls')} onChange={e => form.setData(field, e.target.value)} disabled={!canEdit}>
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="none">None</option>
                        </select>
                    ) : field === 'status_map' ? (
                        <textarea rows={5} value={String(form.data[field] ?? '')} onChange={e => form.setData(field, e.target.value)} disabled={!canEdit} />
                    ) : (
                        <input
                            type={secretFields.has(field) ? 'password' : (field === 'timeout' || field === 'port') ? 'number' : 'text'}
                            value={String(form.data[field] ?? '')}
                            placeholder={secretFields.has(field) && provider[`${field}_set`] ? translate('portal', 'si_secret_keep') : ''}
                            onChange={e => form.setData(field, e.target.value)}
                            disabled={!canEdit}
                            autoComplete="off"
                        />
                    )}
                    {form.errors[field] && <span className="field-error">{String(form.errors[field])}</span>}
                </label>
            ))}
            <label>{translate('portal', 'si_reason')}
                <textarea required minLength={5} maxLength={1000} rows={2} value={String(form.data.reason)} onChange={e => form.setData('reason', e.target.value)} disabled={!canEdit} />
            </label>
            {Object.keys(form.errors).length > 0 && <div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
            <div className="ops-row is-wrap">
                <button className="button dark" disabled={!canEdit || form.processing}>{form.processing ? translate('portal', 'si_saving') : translate('portal', 'si_save_provider')}</button>
                {group === 'sms' && provider.enabled && (
                    <button type="button" className="button" disabled={balanceBusy} onClick={checkBalance}>
                        {balanceBusy ? translate('portal', 'si_saving') : translate('portal', 'si_sms_balance_check')}
                    </button>
                )}
            </div>
            {balanceMsg && <p className="muted ops-mt-3">{translate('portal', 'si_sms_balance')}: {balanceMsg}</p>}
        </form>
    );
}

export default function ServiceIntegrations({ integrations, startup, canEdit, ownerOnlyGroups = [], channelHint }: {
    integrations: { version: number; groups: Record<string, Group> };
    ownerOnlyGroups?: string[];
    startup: { mode: string | null; version: number; label: string; description: string };
    canEdit: boolean;
    channelHint?: string;
}) {
    const translate = useT();
    const { flash, portal } = usePage<any>().props;
    const [groupKey, setGroupKey] = useState(Object.keys(integrations.groups)[0] ?? 'bdris');
    const startupForm = useForm({ mode: startup.mode ?? 'fresh', version: startup.version, reason: '' });
    const group = integrations.groups[groupKey];

    return (
        <Shell section="service-integrations">
            <Head title={translate('portal', 'si_head_title')} />
            <div className="import-workspace">
                <div className="section-header">
                    <div>
                        <h2>{translate('portal', 'si_heading')}</h2>
                        <p className="muted">{translate('portal', 'si_heading_hint')}</p>
                    </div>
                    <PlugZap size={30} aria-hidden="true" />
                </div>
                {flash?.success && <div className="notice" role="status">{flash.success}</div>}
                <p className="muted">{channelHint || translate('portal', 'si_channel_hint')}</p>

                <section className="panel">
                    <h2>{translate('portal', 'si_startup_title')}</h2>
                    <p>{startup.description}</p>
                    <p>{translate('portal', 'si_current_selection')}: <strong>{startup.label}</strong></p>
                    <form className="action-form" onSubmit={event => {
                        event.preventDefault();
                        startupForm.post('/commands/operations/integrations/startup-mode', { preserveScroll: true });
                    }}>
                        <label>{translate('portal', 'si_startup_how')}
                            <select value={String(startupForm.data.mode)} onChange={e => startupForm.setData('mode', e.target.value)} disabled={!canEdit}>
                                <option value="fresh">{translate('portal', 'si_mode_fresh')}</option>
                                <option value="import">{translate('portal', 'si_mode_import')}</option>
                            </select>
                        </label>
                        <label>{translate('portal', 'si_startup_reason')}
                            <textarea required minLength={5} maxLength={1000} rows={2} value={String(startupForm.data.reason)} onChange={e => startupForm.setData('reason', e.target.value)} disabled={!canEdit} />
                        </label>
                        {Object.keys(startupForm.errors).length > 0 && <div role="alert" className="error">{Object.values(startupForm.errors).join(' ')}</div>}
                        <button className="button dark" disabled={!canEdit || startupForm.processing}>{startupForm.processing ? translate('portal', 'si_saving') : translate('portal', 'si_save_startup')}</button>
                    </form>
                    <p className="muted">{translate('portal', 'si_startup_note')}</p>
                </section>

                <section className="panel">
                    <h2>{translate('portal', 'si_external_title')}</h2>
                    <div className="import-options ops-mb-4">
                        {Object.entries(integrations.groups).map(([key, item]) => (
                            <label key={key} className="import-choice">
                                <input type="radio" name="integration-group" checked={groupKey === key} onChange={() => setGroupKey(key)} />
                                {item.label}
                            </label>
                        ))}
                    </div>
                    {group && <>
                        <p>{group.description}</p>
                        {groupKey === 'couriers' && portal === 'sadmin' && (
                            <div className="notice ops-mb-3">
                                <p>{translate('courier_areas', 'open_page_hint')}</p>
                                <Link className="button small" href="/operations/courier-areas">{translate('courier_areas', 'open_page')}</Link>
                            </div>
                        )}
                        {Object.values(group.providers).map(provider => (
                            <section className="panel narrow-panel ops-mt-4" key={provider.key}>
                                <ProviderForm group={groupKey} provider={provider} version={integrations.version} canEdit={canEdit && !ownerOnlyGroups.includes(groupKey)} />
                            </section>
                        ))}
                    </>}
                </section>
            </div>
        </Shell>
    );
}
