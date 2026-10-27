import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Row, label } from './UI';
import PartnerCommissionOverride from './PartnerCommissionOverride';
import PartnerBenefitControls from './PartnerBenefitControls';
import { useT } from '../shared/i18n/t';

const profileFields = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'shop_name', 'area_code'];

type KycDoc = {
    id: string;
    kind: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    note: string | null;
    created_at: string;
};

type PasswordStatus = {
    managed_by: string;
    can_reveal: boolean;
    password: string | null;
};

const kycKinds = ['nid_front', 'nid_back', 'birth_certificate', 'agreement', 'photo', 'other'] as const;

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export default function AccountEditor({ account, onDone }: { account: Row; onDone: () => void }) {
    const translate = useT();
    const caps = ((usePage().props as any).auth?.capabilities ?? []) as string[];
    const canKyc = caps.includes('identity_write');
    const canAccounts = caps.includes('accounts');
    const isPartner = ['dealer', 'sub_dealer'].includes(account.role);
    const isSubDealer = account.role === 'sub_dealer';
    const form = useForm({
        name: account.name,
        dealer_code: account.dealer_code ?? '',
        phone: account.phone ?? '',
        division: account.division ?? '',
        district: account.district ?? '',
        place_code: account.profile?.place_code ?? account.profile?.area_code ?? '',
        latitude: account.latitude ?? '',
        longitude: account.longitude ?? '',
        directory_visible: account.directory_visible ? '1' : '0',
        status: account.status,
        profile: Object.fromEntries(profileFields.map(field => [field, account.profile?.[field] ?? (field === 'shop_name' ? (account.profile?.pharmacy ?? '') : '')])),
        reason: '',
    });
    const kyc = useForm({
        kind: 'nid_front',
        note: '',
        document: null as File | null,
    });
    const [docs, setDocs] = useState<KycDoc[]>([]);
    const [docsError, setDocsError] = useState('');
    const [passwordStatus, setPasswordStatus] = useState<PasswordStatus | null>(null);
    const [passwordError, setPasswordError] = useState('');
    const [passwordBusy, setPasswordBusy] = useState(false);
    const [passwordVisible, setPasswordVisible] = useState(false);

    useEffect(() => {
        if (!canKyc || !isPartner) {
            return;
        }
        let cancelled = false;
        fetch(`/commands/accounts/${account.id}/kyc-documents`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('failed');
                }
                return response.json();
            })
            .then((json) => {
                if (!cancelled) {
                    setDocs(json.data || []);
                    setDocsError('');
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setDocsError(translate('portal', 'kyc_load_failed'));
                }
            });
        return () => { cancelled = true; };
    }, [account.id, canKyc, isPartner]);

    useEffect(() => {
        if (!canAccounts || !isPartner) {
            return;
        }
        let cancelled = false;
        fetch(`/commands/accounts/${account.id}/password-status`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('failed');
                }
                return response.json();
            })
            .then((json) => {
                if (!cancelled) {
                    setPasswordStatus(json.data || null);
                    setPasswordError('');
                    setPasswordVisible(false);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPasswordError(translate('portal', 'acct_password_load_failed'));
                }
            });
        return () => { cancelled = true; };
    }, [account.id, canAccounts, isPartner, translate]);

    const resetPartnerPassword = () => {
        if (passwordBusy) {
            return;
        }
        setPasswordBusy(true);
        setPasswordError('');
        fetch(`/commands/accounts/${account.id}/reset-password`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        })
            .then(async (response) => {
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(json.message || 'failed');
                }
                setPasswordStatus(json.data || null);
                setPasswordVisible(true);
            })
            .catch(() => {
                setPasswordError(translate('portal', 'acct_password_reset_failed'));
            })
            .finally(() => setPasswordBusy(false));
    };

    return <>
        <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.transform((data) => {
                const profile = { ...data.profile };
                if (isSubDealer && profile.shop_name) {
                    profile.pharmacy = profile.shop_name;
                }
                return { ...data, profile };
            });
            form.patch(`/commands/accounts/${account.id}`, { onSuccess: onDone });
        }}>
            <p className="muted">{translate('portal', 'acct_edit_notice')}</p>
            <div className="form-grid">
                <label>{translate('portal', 'acct_full_name')}
                    <input required value={form.data.name} onChange={event => form.setData('name', event.target.value)} />
                </label>
                {isPartner && <label>{translate('portal', 'acct_dealer_code_unique')}
                    <input value={form.data.dealer_code} onChange={event => form.setData('dealer_code', event.target.value.toUpperCase())} />
                </label>}
                <label>{translate('portal', 'acct_phone_number')}
                    <input value={form.data.phone} onChange={event => form.setData('phone', event.target.value)} />
                </label>
                {isPartner ? <>
                    <label>{isSubDealer ? translate('portal', 'acct_union_code') : translate('portal', 'acct_place_code')}
                        <input required value={form.data.place_code} onChange={event => form.setData('place_code', event.target.value.toUpperCase())} placeholder={isSubDealer ? translate('portal', 'acct_union_code_ph') : translate('portal', 'acct_place_code_ph')} />
                        {form.errors.place_code && <span className="field-error">{form.errors.place_code}</span>}
                    </label>
                    <label>{translate('portal', 'acct_division')}
                        <input readOnly value={form.data.division} />
                    </label>
                    <label>{translate('portal', 'acct_district_plain')}
                        <input readOnly value={form.data.district} />
                    </label>
                    <label>{translate('portal', 'acct_latitude')}
                        <input type="number" step="any" min="-90" max="90" value={form.data.latitude} onChange={event => form.setData('latitude', event.target.value)} placeholder={translate('portal', 'acct_latitude_ph')} />
                        {form.errors.latitude && <span className="field-error">{form.errors.latitude}</span>}
                    </label>
                    <label>{translate('portal', 'acct_longitude')}
                        <input type="number" step="any" min="-180" max="180" value={form.data.longitude} onChange={event => form.setData('longitude', event.target.value)} placeholder={translate('portal', 'acct_longitude_ph')} />
                        {form.errors.longitude && <span className="field-error">{form.errors.longitude}</span>}
                    </label>
                </> : <>
                    <label>{translate('portal', 'acct_division')}
                        <input value={form.data.division} onChange={event => form.setData('division', event.target.value)} />
                    </label>
                    <label>{translate('portal', 'acct_district_plain')}
                        <input value={form.data.district} onChange={event => form.setData('district', event.target.value)} />
                    </label>
                </>}
                {profileFields.map(field => {
                    if (field === 'shop_name' && !isSubDealer) {
                        return null;
                    }
                    if (field === 'pharmacy' && isSubDealer) {
                        return null;
                    }
                    if (isPartner && ['upazila', 'union', 'area_code'].includes(field)) {
                        const fieldLabel = label(field);
                        return <label key={field}>{fieldLabel}<input readOnly maxLength={150} value={form.data.profile[field]} /></label>;
                    }
                    const fieldLabel = field === 'shop_name' ? translate('portal', 'acct_shop_name_plain') : field === 'pharmacy' ? translate('portal', 'acct_pharmacy') : label(field);
                    return <label key={field}>{fieldLabel}<input maxLength={150} value={form.data.profile[field]} onChange={event => form.setData('profile', { ...form.data.profile, [field]: event.target.value })} /></label>;
                })}
                <label>{translate('portal', 'col_status')}<select value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="active">{translate('portal', 'status_active')}</option><option value="suspended">{translate('portal', 'status_suspended')}</option></select></label>
                {account.role === 'dealer' && <label>{translate('portal', 'acct_directory_visibility')}<select value={form.data.directory_visible} onChange={event => form.setData('directory_visible', event.target.value)}><option value="0">{translate('portal', 'acct_private')}</option><option value="1">{translate('portal', 'acct_publish_contact')}</option></select></label>}
            </div>
            <label>{translate('portal', 'acct_correction_reason')}<textarea required maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            {Object.keys(form.errors).length > 0 && <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
            <button className="button dark" disabled={form.processing}>{translate('portal', 'acct_save_correction')}</button>
        </form>
        {canKyc && isPartner && (
            <section className="panel" style={{ marginTop: 16 }}>
                <div className="panel-heading">
                    <div>
                        <h3>{translate('portal', 'kyc_documents')}</h3>
                        <p className="muted">{translate('portal', 'kyc_documents_hint')}</p>
                    </div>
                </div>
                <form className="action-form" onSubmit={event => {
                    event.preventDefault();
                    if (!kyc.data.document) {
                        return;
                    }
                    kyc.post(`/commands/accounts/${account.id}/kyc-documents`, {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => {
                            kyc.reset('note', 'document');
                            fetch(`/commands/accounts/${account.id}/kyc-documents`, {
                                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                            })
                                .then(response => response.json())
                                .then(json => setDocs(json.data || []))
                                .catch(() => setDocsError(translate('portal', 'kyc_load_failed')));
                        },
                    });
                }}>
                    <div className="form-grid">
                        <label>{translate('portal', 'kyc_kind')}
                            <select value={kyc.data.kind} onChange={event => kyc.setData('kind', event.target.value)}>
                                {kycKinds.map(kind => <option key={kind} value={kind}>{translate('portal', `kyc_kind_${kind}`)}</option>)}
                            </select>
                        </label>
                        <label>{translate('portal', 'kyc_file')}
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" onChange={event => kyc.setData('document', event.target.files?.[0] ?? null)} />
                            {kyc.errors.document && <span className="field-error">{kyc.errors.document}</span>}
                        </label>
                        <label>{translate('portal', 'kyc_note')}
                            <input value={kyc.data.note} maxLength={500} onChange={event => kyc.setData('note', event.target.value)} />
                        </label>
                    </div>
                    <button className="button dark" disabled={kyc.processing || !kyc.data.document}>{translate('portal', 'kyc_upload')}</button>
                </form>
                {docsError && <p className="muted" style={{ marginTop: 10 }}>{docsError}</p>}
                {docs.length > 0 ? (
                    <ul style={{ marginTop: 14, paddingLeft: 18, display: 'grid', gap: 6 }}>
                        {docs.map(doc => (
                            <li key={doc.id}>
                                <a className="text-link" href={`/commands/accounts/${account.id}/kyc-documents/${doc.id}`}>
                                    {translate('portal', `kyc_kind_${doc.kind}`)} · {doc.original_name}
                                </a>
                                {doc.note ? <span className="muted"> — {doc.note}</span> : null}
                            </li>
                        ))}
                    </ul>
                ) : !docsError ? (
                    <p className="muted" style={{ marginTop: 12 }}>{translate('portal', 'kyc_empty')}</p>
                ) : null}
            </section>
        )}

        {canAccounts && isPartner && (
            <section className="panel" style={{ marginTop: 16 }}>
                <div className="panel-heading">
                    <div>
                        <h3>{translate('portal', 'acct_password_title')}</h3>
                        <p className="muted">{translate('portal', 'acct_password_hint')}</p>
                    </div>
                </div>
                {passwordError && <p className="muted" role="alert">{passwordError}</p>}
                {passwordStatus?.can_reveal && passwordStatus.password ? (
                    <div className="metric-grid two" style={{ marginBottom: 12 }}>
                        <div className="metric">
                            <span>{translate('portal', 'acct_password_current')}</span>
                            <strong style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', letterSpacing: 0.4 }}>
                                {passwordVisible ? passwordStatus.password : '••••••••••••'}
                            </strong>
                        </div>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'end', flexWrap: 'wrap' }}>
                            <button type="button" className="button" onClick={() => setPasswordVisible((value) => !value)}>
                                {passwordVisible ? translate('portal', 'acct_password_hide') : translate('portal', 'acct_password_show')}
                            </button>
                        </div>
                    </div>
                ) : (
                    <p className="muted" style={{ marginBottom: 12 }}>
                        {translate('portal', 'acct_password_self_changed')}
                    </p>
                )}
                <button type="button" className="button dark" disabled={passwordBusy} onClick={resetPartnerPassword}>
                    {passwordBusy ? translate('portal', 'acct_password_resetting') : translate('portal', 'acct_password_reset')}
                </button>
            </section>
        )}

        {canAccounts && isPartner && (
            <section className="panel">
                <div className="panel-heading"><div><h2>{translate('portal', 'link_staff_access')}</h2><p className="muted">{translate('portal', 'linked_staff_role')}</p></div></div>
                {account.linked_staff_role ? (
                    <div className="metric-grid two">
                        <div className="metric"><span>{translate('portal', 'linked_staff_role')}</span><strong>{account.linked_staff_role}</strong></div>
                        <form onSubmit={event=>{event.preventDefault(); router.post(`/commands/accounts/${account.id}/unlink-staff`);}}>
                            <button className="button" type="submit">{translate('portal', 'unlink_staff_access')}</button>
                        </form>
                    </div>
                ) : (
                    <form className="action-form" onSubmit={event=>{
                        event.preventDefault();
                        const fd=new FormData(event.currentTarget);
                        router.post(`/commands/accounts/${account.id}/link-staff`, { linked_staff_role: String(fd.get('linked_staff_role')||'employee') });
                    }}>
                        <label>{translate('portal', 'linked_staff_role')}
                            <select name="linked_staff_role" defaultValue="employee">
                                <option value="employee">employee</option>
                                <option value="sr">sr</option>
                            </select>
                        </label>
                        <button className="button dark" type="submit">{translate('portal', 'link_staff_access')}</button>
                    </form>
                )}
            </section>
        )}

        <PartnerCommissionOverride account={account} />
        <PartnerBenefitControls account={account} />
    </>;
}