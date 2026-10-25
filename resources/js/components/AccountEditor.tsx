import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
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

const kycKinds = ['nid_front', 'nid_back', 'birth_certificate', 'agreement', 'photo', 'other'] as const;

export default function AccountEditor({ account, onDone }: { account: Row; onDone: () => void }) {
    const translate = useT();
    const caps = ((usePage().props as any).auth?.capabilities ?? []) as string[];
    const canKyc = caps.includes('identity_write');
    const isPartner = ['dealer', 'sub_dealer'].includes(account.role);
    const isSubDealer = account.role === 'sub_dealer';
    const form = useForm({
        name: account.name,
        dealer_code: account.dealer_code ?? '',
        phone: account.phone ?? '',
        division: account.division ?? '',
        district: account.district ?? '',
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
                <label>{translate('portal', 'acct_division')}
                    <input value={form.data.division} onChange={event => form.setData('division', event.target.value)} />
                </label>
                <label>{translate('portal', 'acct_district_plain')}
                    <input value={form.data.district} onChange={event => form.setData('district', event.target.value)} />
                </label>
                {isPartner && <>
                    <label>{translate('portal', 'acct_latitude')}
                        <input type="number" step="any" min="-90" max="90" value={form.data.latitude} onChange={event => form.setData('latitude', event.target.value)} placeholder="e.g. 25.7701" />
                        {form.errors.latitude && <span className="field-error">{form.errors.latitude}</span>}
                    </label>
                    <label>{translate('portal', 'acct_longitude')}
                        <input type="number" step="any" min="-180" max="180" value={form.data.longitude} onChange={event => form.setData('longitude', event.target.value)} placeholder="e.g. 88.8240" />
                        {form.errors.longitude && <span className="field-error">{form.errors.longitude}</span>}
                    </label>
                </>}
                {profileFields.map(field => {
                    if (field === 'shop_name' && !isSubDealer) {
                        return null;
                    }
                    if (field === 'pharmacy' && isSubDealer) {
                        return null;
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
        <PartnerCommissionOverride account={account} />
        <PartnerBenefitControls account={account} />
    </>;
}
