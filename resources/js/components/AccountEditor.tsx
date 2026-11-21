import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Row, label, FormErrors } from './UI';
import PartnerCommissionOverride from './PartnerCommissionOverride';
import PartnerBenefitControls from './PartnerBenefitControls';
import PartnerProfileLock from './PartnerProfileLock';
import PartnerPrescriberControls from './PartnerPrescriberControls';
import PartnerAccountDelete from './PartnerAccountDelete';
import NidReview from './NidReview';
import AccountPasswordManager from './AccountPasswordManager';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { toLatin } from '../shared/countUp';
import PlacePickers, { loadPlaceList, placeLevels, usePlaceChain, type PlaceField, type PlaceLevel, type PlaceOption } from './PlacePickers';

/** One list of the permanent address, in the editor's plain label style. */
const EditorField: PlaceField = ({ text, error, hint, children }) => (
    <label>{text}{children}{hint && !error && <small className="muted">{hint}</small>}{error && <span className="field-error">{error}</span>}</label>
);

/** Plain labels for the editable profile fields; the create form keys carry a required marker, so editing uses its own. */
const profileFieldLabelKeys: Record<string, string> = { name_bn: 'acct_edit_name_bn', father_name: 'acct_edit_father_name', mother_name: 'acct_edit_mother_name', village: 'acct_village', post_office: 'acct_post_office', upazila: 'acct_upazila', union: 'acct_union' };
const profileFields = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'shop_name', 'area_code'];

type KycDoc = {
    id: string;
    kind: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    note: string | null;
    created_at: string;
    review_status?: string;
    review_note?: string | null;
    claimed_nid?: string | null;
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
    const authProps = ((usePage().props as any).auth ?? {}) as any;
    const caps = (authProps.capabilities ?? []) as string[];
    const currentUser = authProps.user;
    const isSuperAdmin = currentUser?.role === 'super_admin';
    const canKyc = caps.includes('identity_write');
    const canAccounts = caps.includes('accounts');
    const isPartner = ['dealer', 'sub_dealer'].includes(account.role);
    const isSubDealer = account.role === 'sub_dealer';
    const isStaff = !isPartner && account.role !== 'super_admin';
    const changeReason = useReasonField(isPartner ? 'partner.account_edit' : 'staff.account_edit');
    const kycNote = useReasonField('partner.kyc_note');
    const isOwner = ['super_admin', 'md', 'chairman'].includes(currentUser?.role);
    const staffActions = ((usePage().props as any).staffActions ?? {}) as Record<string, Record<string, boolean> | undefined>;
    // Owners without the Accounts edit right open this only for the password box.
    const canEditDetails = canAccounts && (staffActions.accounts?.edit ?? true);
    // Mirrors PasswordAccess on the server: dealers and sub-dealers by owners or Accounts staff; MD and Chairman passwords only by the Super Admin; other staff by owners or staff admins.
    const canResetStaff = isStaff && account.id !== currentUser?.id && (isSuperAdmin || (!['md', 'chairman'].includes(account.role) && (isOwner || caps.includes('staff_admin'))));
    const canManagePassword = ((canAccounts || isOwner) && isPartner && account.id !== currentUser?.id) || canResetStaff;
    // Only a partner who already has a place must keep one. Someone with no place yet can still be edited or suspended.
    const hadPlace = Boolean(account.profile?.place_code ?? account.profile?.area_code);
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
        // A new permanent address, only when the office picks one; empty keeps the saved one.
        permanent_place_id: '',
        reason: '',
    });
    const [homeDivisions, setHomeDivisions] = useState<PlaceOption[]>([]);
    const placeListFailed = translate('portal', 'acct_request_failed');
    const home = usePlaceChain(homeDivisions, (level, parentId) => loadPlaceList(level, parentId, placeListFailed));
    const [homeError, setHomeError] = useState('');
    const homeNow = (['union', 'upazila', 'district', 'division'] as const).map(level => account.profile?.[`permanent_${level}`]).filter(Boolean).join(', ');
    useEffect(() => {
        if (!isPartner || !canEditDetails) {
            return;
        }
        let cancelled = false;
        loadPlaceList('division', '', placeListFailed)
            .then((list) => { if (!cancelled) setHomeDivisions(list); })
            .catch(() => { if (!cancelled) setHomeError(placeListFailed); });
        return () => { cancelled = true; };
    }, [isPartner, canEditDetails]);
    const pickHome = (level: PlaceLevel, value: string) => {
        const ids = home.choose(level, value);
        setHomeError('');
        form.clearErrors('permanent_place_id');
        form.setData('permanent_place_id', ids.union || ids.upazila || ids.district || ids.division);
    };
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
    }, [account.id, canKyc, isPartner, translate]);

    return <>
        {canEditDetails && <form className="action-form" onSubmit={event => {
            event.preventDefault();
            // A new permanent address goes at least down to the upazila, as on the add-partner form.
            if (home.ids.division && !home.ids.upazila) {
                setHomeError(translate('portal', 'acct_val_home_place_level'));
                return;
            }
            form.transform((data) => {
                const profile = { ...data.profile };
                // One shop name for dealers and sub-dealers; the older pharmacy field just follows it.
                if (isPartner) {
                    profile.pharmacy = profile.shop_name;
                }
                const { permanent_place_id: homePlaceId, ...rest } = data;
                return isPartner && homePlaceId ? { ...rest, profile, permanent_place_id: homePlaceId } : { ...rest, profile };
            });
            form.patch(`/commands/accounts/${account.id}`, { onSuccess: onDone });
        }}>
            <p className="muted">{translate('portal', 'acct_edit_notice')}</p>
            <div className="form-grid">
                <label>{translate('portal', 'acct_full_name')}
                    <input id="ace-name-en" required value={form.data.name} onChange={event => form.setData('name', event.target.value)} />
                </label>
                {isPartner && <label>{translate('portal', 'acct_dealer_code_unique')}
                    <input required value={form.data.dealer_code} onChange={event => form.setData('dealer_code', event.target.value.toUpperCase())} />
                </label>}
                <label>{translate('portal', 'acct_phone_number')}
                    <input required value={form.data.phone} onChange={event => form.setData('phone', event.target.value)} />
                    {form.errors.phone && <span className="field-error">{form.errors.phone}</span>}
                </label>
                {isPartner ? <>
                    <label>{isSubDealer ? translate('portal', 'acct_union_code') : translate('portal', 'acct_place_code')}
                        <input required={hadPlace} value={form.data.place_code} onChange={event => form.setData('place_code', toLatin(event.target.value).toUpperCase())} placeholder={isSubDealer ? translate('portal', 'acct_union_code_ph') : translate('portal', 'acct_place_code_ph')} />
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
                    <div className="full">
                        <b>{translate('portal', 'acct_step_home')}</b>
                        <p className="muted">{translate('portal', 'acct_home_now', { place: homeNow || translate('portal', 'acct_home_not_given') })} {translate('portal', 'acct_home_change_hint')}</p>
                    </div>
                    <PlacePickers
                        chain={home}
                        levels={placeLevels}
                        onPick={pickHome}
                        Field={EditorField}
                        errors={{ upazila: homeError || form.errors.permanent_place_id }}
                        hints={{ union: translate('portal', 'acct_home_union_hint') }}
                    />
                </> : <>
                    <label>{translate('portal', 'acct_division')}
                        <input value={form.data.division} onChange={event => form.setData('division', event.target.value)} />
                    </label>
                    <label>{translate('portal', 'acct_district_plain')}
                        <input value={form.data.district} onChange={event => form.setData('district', event.target.value)} />
                    </label>
                </>}
                {profileFields.map(field => {
                    if (field === 'pharmacy' && isPartner) {
                        return null;
                    }
                    if (isPartner && ['upazila', 'union', 'area_code'].includes(field)) {
                        const fieldLabel = profileFieldLabelKeys[field] ? translate('portal', profileFieldLabelKeys[field]) : label(field, translate);
                        return <label key={field}>{fieldLabel}<input readOnly maxLength={150} value={form.data.profile[field]} /></label>;
                    }
                    const fieldLabel = field === 'shop_name' ? translate('portal', 'acct_shop_name_plain') : field === 'pharmacy' ? translate('portal', 'acct_pharmacy') : profileFieldLabelKeys[field] ? translate('portal', profileFieldLabelKeys[field]) : label(field, translate);
                    return <label key={field}>{fieldLabel}<input maxLength={150} {...(field === 'name_bn' ? { 'data-bangla-from': 'ace-name-en', 'data-bangla-kind': 'name' } : {})} value={form.data.profile[field]} onChange={event => form.setData('profile', { ...form.data.profile, [field]: event.target.value })} /></label>;
                })}
                <label>{translate('portal', 'col_status')}<select value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="active">{translate('portal', 'status_active')}</option><option value="suspended">{translate('portal', 'status_suspended')}</option></select>{isPartner && form.data.status === 'suspended' && account.status !== 'suspended' && <small className="notice" role="status">{translate('payouts', 'suspend_hold_note')}</small>}</label>
                {(account.role === 'dealer' || account.role === 'sub_dealer') && <label>{translate('portal', 'acct_directory_visibility')}<select value={form.data.directory_visible} onChange={event => form.setData('directory_visible', event.target.value)}><option value="0">{translate('portal', 'acct_private')}</option><option value="1">{translate('portal', 'acct_publish_contact')}</option></select></label>}
            </div>
            {changeReason.shown && <label>{changeReason.required ? translate('portal', 'acct_correction_reason') : changeReason.label(translate('reason_fields', 'box_account_change_reason'))}<textarea required={changeReason.required} maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>}
            <FormErrors errors={form.errors} besideFields={['phone', 'place_code', 'latitude', 'longitude', 'permanent_place_id']}/>
            <button className="button dark" disabled={form.processing}>{translate('portal', 'acct_save_correction')}</button>
            {isPartner && <a className="button" href={`/exports/partner-registration-form/${account.id}`} title={translate('partner_form', 'download_hint')}>{translate('partner_form', 'download_form')}</a>}
        </form>}
        {canKyc && isPartner && (
            <section className="panel aed-kyc">
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
                        {kycNote.shown && <label>{kycNote.label(translate('reason_fields', 'box_staff_note'))}
                            <input required={kycNote.required} value={kyc.data.note} maxLength={500} onChange={event => kyc.setData('note', event.target.value)} />
                        </label>}
                    </div>
                    <button className="button dark" disabled={kyc.processing || !kyc.data.document}>{translate('portal', 'kyc_upload')}</button>
                </form>
                {docsError && <p className="muted ops-mt-3">{docsError}</p>}
                {docs.length > 0 ? (
                    <ul className="aed-docs">
                        {docs.map(doc => (
                            <li key={doc.id}>
                                <a className="text-link" href={`/commands/accounts/${account.id}/kyc-documents/${doc.id}`}>
                                    {translate('portal', `kyc_kind_${doc.kind}`)} · {doc.original_name}
                                </a>
                                {doc.note ? <span className="muted"> — {doc.note}</span> : null}
                                {doc.review_status === 'rejected' && <span className="muted"> — {translate('leftovers_partner', 'review_rejected_label')}{doc.review_note ? `: ${doc.review_note}` : ''}</span>}
                                {doc.review_status === 'pending' && <NidReview accountId={account.id} doc={doc} onDone={() => fetch(`/commands/accounts/${account.id}/kyc-documents`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(response => response.json()).then(json => setDocs(json.data || [])).catch(() => setDocsError(translate('portal', 'kyc_load_failed')))} />}
                            </li>
                        ))}
                    </ul>
                ) : !docsError ? (
                    <p className="muted ops-mt-3">{translate('portal', 'kyc_empty')}</p>
                ) : null}
            </section>
        )}

        {canManagePassword && (
            <AccountPasswordManager
                account={account}
                canManage={canManagePassword}
            />
        )}

        {canAccounts && isPartner && (
            <section className="panel">
                <div className="panel-heading"><div><h2>{translate('portal', 'link_staff_access')}</h2><p className="muted">{translate('portal', 'linked_staff_role')}</p></div></div>
                {account.linked_staff_role ? (
                    <div className="metric-grid two">
                        <div className="metric"><span>{translate('portal', 'linked_staff_role')}</span><strong>{label(String(account.linked_staff_role), translate)}</strong></div>
                        <form onSubmit={event=>{event.preventDefault(); router.post(`/commands/accounts/${account.id}/unlink-staff`, {}, { onSuccess: onDone });}}>
                            <button className="button" type="submit">{translate('portal', 'unlink_staff_access')}</button>
                        </form>
                    </div>
                ) : (
                    <form className="action-form" onSubmit={event=>{
                        event.preventDefault();
                        const fd=new FormData(event.currentTarget);
                        router.post(`/commands/accounts/${account.id}/link-staff`, { linked_staff_role: String(fd.get('linked_staff_role')||'employee') }, { onSuccess: onDone });
                    }}>
                        <label>{translate('portal', 'linked_staff_role')}
                            <select name="linked_staff_role" defaultValue="employee">
                                <option value="employee">{translate('portal', 'staff_role_employee')}</option>
                                <option value="sr">{translate('portal', 'staff_role_sr')}</option>
                            </select>
                        </label>
                        <button className="button dark" type="submit">{translate('portal', 'link_staff_access')}</button>
                    </form>
                )}
            </section>
        )}

        <PartnerCommissionOverride account={account} />
        <PartnerBenefitControls account={account} />
        <PartnerProfileLock account={account} />
        <PartnerPrescriberControls account={account} />
        <PartnerAccountDelete account={account} onDone={onDone} />
    </>;
}