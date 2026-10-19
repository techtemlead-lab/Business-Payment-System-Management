import React, { useMemo, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { PlugZap } from 'lucide-react';
import Shell from '../components/Shell';
import { Badge, Row } from '../components/UI';

type Provider = Row & { key: string; label: string; fields: string[]; enabled: boolean };
type Group = { label: string; description: string; providers: Record<string, Provider> };

const secretFields = new Set(['api_key', 'api_secret', 'app_key', 'app_secret', 'password', 'username', 'client_secret', 'store_password']);

function fieldLabel(name: string): string {
    return name.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
}

function ProviderForm({ group, provider, version, canEdit }: { group: string; provider: Provider; version: number; canEdit: boolean }) {
    const defaults = useMemo(() => {
        const data: Row = { group, provider: provider.key, version, reason: '', enabled: provider.enabled ? '1' : '0' };
        for (const field of provider.fields) {
            if (field === 'enabled') continue;
            data[field] = secretFields.has(field) ? '' : (provider[field] ?? '');
        }
        return data;
    }, [group, provider, version]);
    const form = useForm(defaults);

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
            <div className="profile-grid" style={{ marginBottom: 12 }}>
                <div><strong>{provider.label}</strong><p className="muted">Provider key: {provider.key}</p></div>
                <div><Badge status={provider.enabled ? 'active' : 'inactive'} /></div>
            </div>
            <label>Enabled
                <select value={String(form.data.enabled)} onChange={e => form.setData('enabled', e.target.value)} disabled={!canEdit}>
                    <option value="0">Disabled (default)</option>
                    <option value="1">Enabled</option>
                </select>
            </label>
            {provider.fields.filter(field => field !== 'enabled').map(field => (
                <label key={field}>{fieldLabel(field)}
                    {field === 'mode' ? (
                        <select value={String(form.data[field] ?? 'sandbox')} onChange={e => form.setData(field, e.target.value)} disabled={!canEdit}>
                            <option value="sandbox">Sandbox</option>
                            <option value="live">Live</option>
                        </select>
                    ) : (
                        <input
                            type={secretFields.has(field) ? 'password' : field === 'timeout' ? 'number' : 'text'}
                            value={String(form.data[field] ?? '')}
                            placeholder={secretFields.has(field) && provider[`${field}_set`] ? 'Leave blank to keep the saved secret' : ''}
                            onChange={e => form.setData(field, e.target.value)}
                            disabled={!canEdit}
                            autoComplete="off"
                        />
                    )}
                    {form.errors[field] && <span className="field-error">{String(form.errors[field])}</span>}
                </label>
            ))}
            <label>Reason / approval note
                <textarea required minLength={5} maxLength={1000} rows={2} value={String(form.data.reason)} onChange={e => form.setData('reason', e.target.value)} disabled={!canEdit} />
            </label>
            {Object.keys(form.errors).length > 0 && <div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
            <button className="button dark" disabled={!canEdit || form.processing}>{form.processing ? 'Saving…' : 'Save provider settings'}</button>
        </form>
    );
}

export default function ServiceIntegrations({ integrations, startup, canEdit }: {
    integrations: { version: number; groups: Record<string, Group> };
    startup: { mode: string | null; version: number; label: string; description: string };
    canEdit: boolean;
}) {
    const { flash } = usePage<any>().props;
    const [groupKey, setGroupKey] = useState(Object.keys(integrations.groups)[0] ?? 'bdris');
    const startupForm = useForm({ mode: startup.mode ?? 'fresh', version: startup.version, reason: '' });
    const group = integrations.groups[groupKey];

    return (
        <Shell section="service-integrations">
            <Head title="Service integrations" />
            <div className="import-workspace">
                <div className="section-header">
                    <div>
                        <h2>Service integrations</h2>
                        <p className="muted">BDRIS, SMS, Maps, courier and payment gateway APIs stay disabled until you enable them and enter credentials. Manual workflows keep working without these services.</p>
                    </div>
                    <PlugZap size={30} aria-hidden="true" />
                </div>
                {flash?.success && <div className="notice" role="status">{flash.success}</div>}

                <section className="panel">
                    <h2>Finance startup mode</h2>
                    <p>{startup.description}</p>
                    <p>Current selection: <strong>{startup.label}</strong></p>
                    <form className="action-form" onSubmit={event => {
                        event.preventDefault();
                        startupForm.post('/commands/operations/integrations/startup-mode', { preserveScroll: true });
                    }}>
                        <label>How this deployment starts
                            <select value={String(startupForm.data.mode)} onChange={e => startupForm.setData('mode', e.target.value)} disabled={!canEdit}>
                                <option value="fresh">1 · Fresh application (no past ledger import)</option>
                                <option value="import">2 · Import opening balances / old Excel reports</option>
                            </select>
                        </label>
                        <label>Reason / handover note
                            <textarea required minLength={5} maxLength={1000} rows={2} value={String(startupForm.data.reason)} onChange={e => startupForm.setData('reason', e.target.value)} disabled={!canEdit} />
                        </label>
                        {Object.keys(startupForm.errors).length > 0 && <div role="alert" className="error">{Object.values(startupForm.errors).join(' ')}</div>}
                        <button className="button dark" disabled={!canEdit || startupForm.processing}>{startupForm.processing ? 'Saving…' : 'Save startup mode'}</button>
                    </form>
                    <p className="muted">Fresh mode does not require Excel. Import mode uses Data imports templates, then Opening balance review. Neither choice by itself turns on financial posting — that still needs verified opening evidence and the deployment activation switch.</p>
                </section>

                <section className="panel">
                    <h2>External services</h2>
                    <div className="import-options" style={{ marginBottom: 16 }}>
                        {Object.entries(integrations.groups).map(([key, item]) => (
                            <label key={key} className="import-choice">
                                <input type="radio" name="integration-group" checked={groupKey === key} onChange={() => setGroupKey(key)} />
                                {item.label}
                            </label>
                        ))}
                    </div>
                    {group && <>
                        <p>{group.description}</p>
                        {Object.values(group.providers).map(provider => (
                            <section className="panel narrow-panel" key={provider.key} style={{ marginTop: 16 }}>
                                <ProviderForm group={groupKey} provider={provider} version={integrations.version} canEdit={canEdit} />
                            </section>
                        ))}
                    </>}
                </section>
            </div>
        </Shell>
    );
}
