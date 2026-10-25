import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import Shell from '../components/Shell';
import { label, type Row } from '../components/UI';
import {
    ArrowLeft, UserPlus, ShieldAlert, MapPin, Store, Building2, BadgeCheck,
    Search, IdCard, Phone, Hash, AlertTriangle, CheckCircle2,
} from 'lucide-react';
import { useT } from '../shared/i18n/t';

type PlaceOption = { id: number; code: string; name: string; level: string; parent_id: number | null };
type CommissionPlace = { level: string; code: string | null; name: string | null; rate?: string | null; pool?: string | null };
type Consumer = { id: number; name: string; refer_code: string | null; rate: string } | null;

const availablePermissionKeys = [
    'field_sales', 'sr_orders', 'sr_dues', 'sr_onboarding', 'staff_admin', 'payroll',
    'orders', 'order_review', 'inventory', 'catalog', 'accounts', 'finance', 'reports',
    'content', 'audit', 'pricing', 'cost_read', 'identity_write',
] as const;

async function fetchJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body?.message || body?.errors?.place_code?.[0] || 'Request failed');
    }
    return response.json();
}

export default function AccountCreate({
    roles = ['dealer', 'sub_dealer'],
    sponsors = [],
    place_divisions = [],
    default_identity_type = 'birth_certificate',
    bdris_enabled = false,
}: {
    roles: string[];
    sponsors: Row[];
    place_divisions?: PlaceOption[];
    default_identity_type?: 'birth_certificate' | 'nid';
    bdris_enabled?: boolean;
}) {
    const translate = useT();
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content || '';

    const form = useForm({
        role: 'dealer',
        identity_type: default_identity_type as 'birth_certificate' | 'nid',
        birth_certificate_number: '',
        nid: '',
        date_of_birth: '',
        name: '',
        name_bn: '',
        father_name: '',
        mother_name: '',
        occupation: '',
        division: '',
        district: '',
        upazila: '',
        union: '',
        village: '',
        post_office: '',
        place_code: '',
        dealer_code: '',
        reference_code: '',
        refer_code: '',
        phone: '',
        email: '',
        extra_contact: '',
        pharmacy: '',
        shop_name: '',
        directory_visible: false,
        status: 'active',
        reason: 'Physical agreement verified and onboarding authorized by company staff.',
        permissions: ['orders', 'inventory'] as string[],
        sponsor_id: '',
        latitude: '',
        longitude: '',
        profile: {
            name_bn: '',
            father_name: '',
            mother_name: '',
            village: '',
            post_office: '',
            upazila: '',
            union: '',
            pharmacy: '',
            shop_name: '',
            area_code: '',
        },
    });

    const isPartner = ['dealer', 'sub_dealer'].includes(form.data.role);
    const [bdrisMessage, setBdrisMessage] = useState('');
    const [bdrisLoading, setBdrisLoading] = useState(false);
    const [placePreview, setPlacePreview] = useState<{
        label: string;
        available: boolean;
        commission_places: CommissionPlace[];
        area_pool_rate?: string;
        management_rate?: string;
        next_sub_dealer_code?: string | null;
    } | null>(null);
    const [placeError, setPlaceError] = useState('');
    const [referWarnings, setReferWarnings] = useState<string[]>([]);
    const [referPreviewError, setReferPreviewError] = useState<string | null>(null);
    const [consumers, setConsumers] = useState<{
        direct_referral: Consumer;
        d2d_gen2: Consumer;
        d2d_gen3: Consumer;
        unassigned: Record<string, string>;
    } | null>(null);

    const [divisionId, setDivisionId] = useState('');
    const [districtId, setDistrictId] = useState('');
    const [upazilaId, setUpazilaId] = useState('');
    const [unionId, setUnionId] = useState('');
    const [districts, setDistricts] = useState<PlaceOption[]>([]);
    const [upazilas, setUpazilas] = useState<PlaceOption[]>([]);
    const [unions, setUnions] = useState<PlaceOption[]>([]);
    const [addressSearch, setAddressSearch] = useState('');

    const loadChildren = async (level: string, parentId: string, q = '') => {
        const params = new URLSearchParams({ level });
        if (parentId) params.set('parent_id', parentId);
        if (q) params.set('q', q);
        const json = await fetchJson<{ data: PlaceOption[] }>(`/commands/place-codes/children?${params}`);
        return json.data;
    };

    useEffect(() => {
        if (!divisionId) {
            setDistricts([]);
            setDistrictId('');
            return;
        }
        loadChildren('district', divisionId, addressSearch).then(setDistricts).catch(() => setDistricts([]));
    }, [divisionId, addressSearch]);

    useEffect(() => {
        if (!districtId) {
            setUpazilas([]);
            setUpazilaId('');
            return;
        }
        loadChildren('upazila', districtId, addressSearch).then(setUpazilas).catch(() => setUpazilas([]));
    }, [districtId, addressSearch]);

    useEffect(() => {
        if (!upazilaId) {
            setUnions([]);
            setUnionId('');
            return;
        }
        loadChildren('union', upazilaId, addressSearch).then(setUnions).catch(() => setUnions([]));
    }, [upazilaId, addressSearch]);

    useEffect(() => {
        const division = place_divisions.find((item) => String(item.id) === divisionId);
        const district = districts.find((item) => String(item.id) === districtId);
        const upazila = upazilas.find((item) => String(item.id) === upazilaId);
        const union = unions.find((item) => String(item.id) === unionId);
        form.setData((prev) => ({
            ...prev,
            division: division?.name || prev.division,
            district: district?.name || prev.district,
            upazila: upazila?.name || prev.upazila,
            union: union?.name || prev.union,
        }));
    }, [divisionId, districtId, upazilaId, unionId, districts, upazilas, unions, place_divisions]);

    const lookupBdris = async () => {
        setBdrisLoading(true);
        setBdrisMessage('');
        try {
            const response = await fetch('/commands/identity/bdris-lookup', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    birth_certificate_number: form.data.birth_certificate_number,
                    date_of_birth: form.data.date_of_birth,
                }),
            });
            const json = await response.json();
            const result = json.data;
            setBdrisMessage(result?.message || translate('portal','acct_lookup_finished'));
            if (result?.success && result.details) {
                form.setData((prev) => ({
                    ...prev,
                    name: result.details.name || prev.name,
                    name_bn: result.details.name_bn || prev.name_bn,
                    father_name: result.details.father_name || prev.father_name,
                    mother_name: result.details.mother_name || prev.mother_name,
                    occupation: result.details.occupation || prev.occupation,
                    date_of_birth: result.details.date_of_birth || prev.date_of_birth,
                }));
            }
        } catch {
            setBdrisMessage(translate('portal','acct_bdris_unreachable'));
        } finally {
            setBdrisLoading(false);
        }
    };

    const previewPlace = async () => {
        setPlaceError('');
        setPlacePreview(null);
        if (!form.data.place_code.trim()) return;
        try {
            const params = new URLSearchParams({
                place_code: form.data.place_code.trim(),
                role: form.data.role,
            });
            const json = await fetchJson<{ data: any }>(`/commands/identity/place-preview?${params}`);
            setPlacePreview(json.data);
            if (form.data.role === 'sub_dealer' && json.data.next_sub_dealer_code) {
                form.setData('dealer_code', json.data.next_sub_dealer_code);
            } else if (form.data.role === 'dealer' && !form.data.dealer_code) {
                form.setData('dealer_code', form.data.place_code.trim());
            }
            if (json.data.path) {
                form.setData((prev) => ({
                    ...prev,
                    division: json.data.path.division || prev.division,
                    district: json.data.path.district || prev.district,
                    upazila: json.data.path.upazila || prev.upazila,
                    union: json.data.path.union || prev.union,
                }));
            }
        } catch (error: any) {
            setPlaceError(error.message || translate('portal','acct_place_not_found'));
        }
    };

    useEffect(() => {
        const handle = window.setTimeout(() => {
            const params = new URLSearchParams({
                reference_code: form.data.reference_code,
                refer_code: form.data.refer_code,
            });
            fetchJson<{ data: { warnings: string[]; consumers: any } }>(`/commands/identity/refer-preview?${params}`)
                .then((json) => {
                    setReferWarnings(json.data.warnings || []);
                    setConsumers(json.data.consumers || null);
                    setReferPreviewError(null);
                })
                .catch(() => {
                    setReferWarnings([]);
                    setConsumers(null);
                    setReferPreviewError(translate('portal','acct_refer_preview_failed'));
                });
        }, 350);
        return () => window.clearTimeout(handle);
    }, [form.data.reference_code, form.data.refer_code]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!isPartner) {
            form.transform((data) => {
                const { permissions, ...rest } = data;
                return ['employee', 'sr'].includes(data.role) ? { ...rest, permissions } : rest;
            });
            form.post('/commands/accounts', { preserveScroll: true });
            return;
        }
        form.transform((data) => ({
            role: data.role,
            identity_type: data.identity_type,
            birth_certificate_number: data.birth_certificate_number,
            nid: data.nid,
            date_of_birth: data.date_of_birth,
            name: data.name,
            name_bn: data.name_bn,
            father_name: data.father_name,
            mother_name: data.mother_name,
            occupation: data.occupation,
            division: data.division,
            district: data.district,
            upazila: data.upazila,
            union: data.union,
            village: data.village,
            post_office: data.post_office,
            latitude: data.latitude,
            longitude: data.longitude,
            place_code: data.place_code,
            dealer_code: data.dealer_code,
            reference_code: data.reference_code,
            refer_code: data.refer_code,
            phone: data.phone,
            email: data.email,
            extra_contact: data.extra_contact,
            pharmacy: data.role === 'sub_dealer' ? (data.shop_name || data.pharmacy) : data.pharmacy,
            shop_name: data.role === 'sub_dealer' ? data.shop_name : '',
            directory_visible: data.directory_visible,
            status: data.status,
            reason: data.reason,
        }));
        form.post('/commands/accounts', { preserveScroll: true });
    };

    const commissionLines = useMemo(() => placePreview?.commission_places || [], [placePreview]);

    return (
        <Shell section="accounts">
            <Head title={translate('portal','acct_head_title')} />
            <div className="section-header-row">
                <Link href="/accounts" className="text-link flex-center">
                    <ArrowLeft size={16} /> {translate('portal','acct_back')}
                </Link>
            </div>

            <div className="registry-heading">
                <div>
                    <h2>{translate('portal','acct_heading')}</h2>
                    <p>
                        {translate('portal','acct_intro')}
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="account-create-layout">
                <div className="account-create-main">
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>{translate('portal','acct_step_type')}</h3>
                                <p>{translate('portal','acct_step_type_hint')}</p>
                            </div>
                            <UserPlus size={22} className="text-muted" />
                        </div>
                        <div className="form-grid">
                            <label>
                                {translate('portal','acct_role')}
                                <select
                                    value={form.data.role}
                                    onChange={(e) => {
                                        const newRole = e.target.value;
                                        form.setData((prev) => ({
                                            ...prev,
                                            role: newRole,
                                            place_code: '',
                                            dealer_code: '',
                                            permissions: newRole === 'sr' ? ['field_sales', 'sr_orders', 'sr_dues', 'sr_onboarding'] : ['orders', 'inventory'],
                                        }));
                                        setPlacePreview(null);
                                    }}
                                    required
                                >
                                    {roles.map((r) => (
                                        <option key={r} value={r}>
                                            {r === 'dealer' ? translate('portal','acct_role_dealer') : r === 'sub_dealer' ? translate('portal','acct_role_sub_dealer') : label(r)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    </section>

                    {['employee', 'sr'].includes(form.data.role) && (
                        <section className="panel" style={{ borderLeft: '4px solid #059669' }}>
                            <div className="panel-heading">
                                <div>
                                    <h3>{translate('portal','acct_employee_privileges')}</h3>
                                </div>
                            </div>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 10 }}>
                                {availablePermissionKeys.filter((key) => form.data.role !== 'sr' || ['field_sales', 'sr_orders', 'sr_dues', 'sr_onboarding'].includes(key)).map((key) => {
                                    const checked = form.data.permissions.includes(key);
                                    return (
                                        <label
                                            key={key}
                                            onClick={() => {
                                                const cur = form.data.permissions;
                                                form.setData('permissions', cur.includes(key) ? cur.filter((k) => k !== key) : [...cur, key]);
                                            }}
                                            style={{ display: 'flex', gap: 10, padding: 10, border: checked ? '1px solid #10b981' : '1px solid #e2e8f0', borderRadius: 6, background: checked ? '#ecfdf5' : '#fff' }}
                                        >
                                            <input type="checkbox" checked={checked} onChange={() => {}} />
                                            <div>
                                                <b style={{ fontSize: 12 }}>{translate('portal',`perm_${key}`)}</b>
                                                <small style={{ display: 'block', color: '#64748b' }}>{translate('portal',`perm_${key}_hint`)}</small>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                            <div className="form-grid" style={{ marginTop: 16 }}>
                                <label>{translate('portal','acct_full_name')}<input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></label>
                                <label>{translate('portal','acct_email')}<input type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></label>
                                <label>{translate('portal','acct_phone')}<input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></label>
                            </div>
                        </section>
                    )}

                    {isPartner && (
                        <>
                            <section className="panel">
                                <div className="panel-heading">
                                    <div>
                                        <h3>{translate('portal','acct_step_identity')}</h3>
                                        <p>{translate('portal','acct_step_identity_hint')}</p>
                                    </div>
                                    <IdCard size={22} className="text-muted" />
                                </div>
                                <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                                    <button type="button" className={`button small ${form.data.identity_type === 'birth_certificate' ? 'dark' : ''}`} onClick={() => form.setData('identity_type', 'birth_certificate')}>{translate('portal','acct_birth_certificate')}</button>
                                    <button type="button" className={`button small ${form.data.identity_type === 'nid' ? 'dark' : ''}`} onClick={() => form.setData('identity_type', 'nid')}>{translate('portal','acct_nid')}</button>
                                </div>
                                <div className="form-grid">
                                    {form.data.identity_type === 'birth_certificate' ? (
                                        <label>
                                            {translate('portal','acct_birth_cert_number')}
                                            <input required value={form.data.birth_certificate_number} onChange={(e) => form.setData('birth_certificate_number', e.target.value)} />
                                            {form.errors.birth_certificate_number && <span className="field-error">{form.errors.birth_certificate_number}</span>}
                                        </label>
                                    ) : (
                                        <label>
                                            {translate('portal','acct_nid_number')}
                                            <input required value={form.data.nid} onChange={(e) => form.setData('nid', e.target.value)} />
                                            {form.errors.nid && <span className="field-error">{form.errors.nid}</span>}
                                        </label>
                                    )}
                                    <label>
                                        {translate('portal','acct_dob')}
                                        <input type="date" required value={form.data.date_of_birth} onChange={(e) => form.setData('date_of_birth', e.target.value)} />
                                        {form.errors.date_of_birth && <span className="field-error">{form.errors.date_of_birth}</span>}
                                    </label>
                                </div>
                                {form.data.identity_type === 'birth_certificate' && (
                                    <div style={{ marginTop: 12, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                                        {bdris_enabled ? (
                                            <button type="button" className="button dark" onClick={lookupBdris} disabled={bdrisLoading}>
                                                <Search size={16} /> {bdrisLoading ? translate('portal','acct_searching_bdris') : translate('portal','acct_search_bdris')}
                                            </button>
                                        ) : (
                                            <span className="muted" style={{ fontSize: 13 }}>{translate('portal','acct_bdris_disabled')}</span>
                                        )}
                                        {bdrisMessage && <span className="muted" style={{ fontSize: 13 }}>{bdrisMessage}</span>}
                                    </div>
                                )}
                                <div className="form-grid" style={{ marginTop: 16 }}>
                                    <label>{translate('portal','acct_name_en')}<input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{form.errors.name && <span className="field-error">{form.errors.name}</span>}</label>
                                    <label>{translate('portal','acct_name_bn')}<input required value={form.data.name_bn} onChange={(e) => form.setData('name_bn', e.target.value)} />{form.errors.name_bn && <span className="field-error">{form.errors.name_bn}</span>}</label>
                                    <label>{translate('portal','acct_father_name')}<input required value={form.data.father_name} onChange={(e) => form.setData('father_name', e.target.value)} />{form.errors.father_name && <span className="field-error">{form.errors.father_name}</span>}</label>
                                    <label>{translate('portal','acct_mother_name')}<input required value={form.data.mother_name} onChange={(e) => form.setData('mother_name', e.target.value)} />{form.errors.mother_name && <span className="field-error">{form.errors.mother_name}</span>}</label>
                                    <label>{translate('portal','acct_occupation')}<input value={form.data.occupation} onChange={(e) => form.setData('occupation', e.target.value)} /></label>
                                </div>
                            </section>

                            <section className="panel">
                                <div className="panel-heading">
                                    <div>
                                        <h3>{translate('portal','acct_step_address')}</h3>
                                        <p>{translate('portal','acct_step_address_hint')}</p>
                                    </div>
                                    <MapPin size={22} className="text-muted" />
                                </div>
                                <label style={{ display: 'block', marginBottom: 12 }}>
                                    {translate('portal','acct_search_places')}
                                    <input value={addressSearch} onChange={(e) => setAddressSearch(e.target.value)} placeholder={translate('portal','acct_filter_places')} />
                                </label>
                                <div className="form-grid">
                                    <label>
                                        {translate('portal','acct_division')}
                                        <select value={divisionId} onChange={(e) => { setDivisionId(e.target.value); setDistrictId(''); setUpazilaId(''); setUnionId(''); }}>
                                            <option value="">{translate('portal','acct_select_division')}</option>
                                            {place_divisions.map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}
                                        </select>
                                    </label>
                                    <label>
                                        {translate('portal','acct_district')}
                                        <select value={districtId} onChange={(e) => { setDistrictId(e.target.value); setUpazilaId(''); setUnionId(''); }} disabled={!divisionId}>
                                            <option value="">{translate('portal','acct_select_district')}</option>
                                            {districts.map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}
                                        </select>
                                    </label>
                                    <label>
                                        {translate('portal','acct_upazila')}
                                        <select value={upazilaId} onChange={(e) => { setUpazilaId(e.target.value); setUnionId(''); }} disabled={!districtId}>
                                            <option value="">{translate('portal','acct_select_upazila')}</option>
                                            {upazilas.map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}
                                        </select>
                                    </label>
                                    <label>
                                        {translate('portal','acct_union')}
                                        <select value={unionId} onChange={(e) => setUnionId(e.target.value)} disabled={!upazilaId}>
                                            <option value="">{translate('portal','acct_select_union')}</option>
                                            {unions.map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}
                                        </select>
                                    </label>
                                    <label>{translate('portal','acct_village')}<input value={form.data.village} onChange={(e) => form.setData('village', e.target.value)} /></label>
                                    <label>{translate('portal','acct_post_office')}<input value={form.data.post_office} onChange={(e) => form.setData('post_office', e.target.value)} /></label>
                                    <label>
                                        {translate('portal','acct_latitude')}
                                        <input type="number" step="any" min="-90" max="90" value={form.data.latitude} onChange={(e) => form.setData('latitude', e.target.value)} placeholder="e.g. 25.7701" />
                                        {form.errors.latitude && <span className="field-error">{form.errors.latitude}</span>}
                                    </label>
                                    <label>
                                        {translate('portal','acct_longitude')}
                                        <input type="number" step="any" min="-180" max="180" value={form.data.longitude} onChange={(e) => form.setData('longitude', e.target.value)} placeholder="e.g. 88.8240" />
                                        {form.errors.longitude && <span className="field-error">{form.errors.longitude}</span>}
                                    </label>
                                </div>
                                <p className="muted" style={{ marginTop: 10, fontSize: 13 }}>
                                    {translate('portal','acct_gps_hint')}
                                </p>
                            </section>

                            <section className="panel">
                                <div className="panel-heading">
                                    <div>
                                        <h3>{translate('portal','acct_step_area')}</h3>
                                        <p>
                                            {form.data.role === 'sub_dealer'
                                                ? translate('portal','acct_area_hint_sub')
                                                : translate('portal','acct_area_hint_dealer')}
                                        </p>
                                    </div>
                                    <Hash size={22} className="text-muted" />
                                </div>
                                <div className="form-grid">
                                    <label>
                                        {form.data.role === 'sub_dealer' ? translate('portal','acct_union_code') : translate('portal','acct_place_code')}
                                        <div style={{ display: 'flex', gap: 8 }}>
                                            <input required value={form.data.place_code} onChange={(e) => form.setData('place_code', e.target.value)} placeholder={form.data.role === 'sub_dealer' ? 'e.g. 457482' : 'e.g. 102'} />
                                            <button type="button" className="button dark" onClick={previewPlace}>{translate('portal','acct_resolve')}</button>
                                        </div>
                                        {form.errors.place_code && <span className="field-error">{form.errors.place_code}</span>}
                                        {placeError && <span className="field-error">{placeError}</span>}
                                    </label>
                                    {form.data.role === 'dealer' && (
                                        <label>
                                            {translate('portal','acct_dealer_code_override')}
                                            <input value={form.data.dealer_code} onChange={(e) => form.setData('dealer_code', e.target.value.toUpperCase())} placeholder={translate('portal','acct_dealer_code_default')} />
                                        </label>
                                    )}
                                    {form.data.role === 'sub_dealer' && (
                                        <label>
                                            {translate('portal','acct_auto_sub_code')}
                                            <input value={form.data.dealer_code} readOnly />
                                        </label>
                                    )}
                                </div>
                                {placePreview && (
                                    <div style={{ marginTop: 14, padding: 12, background: placePreview.available ? '#ecfdf5' : '#fff7ed', border: `1px solid ${placePreview.available ? '#a7f3d0' : '#fed7aa'}`, borderRadius: 8 }}>
                                        <div style={{ fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
                                            {placePreview.available ? <CheckCircle2 size={16} color="#047857" /> : <AlertTriangle size={16} color="#c2410c" />}
                                            {placePreview.label}
                                        </div>
                                        {!placePreview.available && <p className="muted" style={{ margin: '6px 0 0' }}>{translate('portal','acct_area_taken')}</p>}
                                        <div style={{ marginTop: 10, display: 'grid', gap: 4 }}>
                                            <div style={{ fontSize: 12, fontWeight: 700, color: '#0f766e' }}>
                                                {translate('portal','acct_area_pool',{pool:String(placePreview.area_pool_rate||'10.00'),mgmt:String(placePreview.management_rate||'2.00')})}
                                            </div>
                                            {commissionLines.map((row) => {
                                                const title = row.level === 'management' ? translate('portal','acct_level_management') : row.level === 'division' ? translate('portal','acct_level_div') : row.level === 'district' ? translate('portal','acct_level_dist') : row.level === 'upazila' ? translate('portal','acct_level_upazila') : translate('portal','acct_level_union');
                                                return (
                                                    <div key={row.level} style={{ fontSize: 13 }}>
                                                        <b>{title}{row.rate ? ` (${row.rate}%)` : ''}:</b>{' '}
                                                        {row.code ? `${row.code} — ${row.name || '—'}` : (row.level === 'management' ? translate('portal','acct_mgmt_reserve') : translate('portal','acct_not_on_path'))}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                            </section>

                            <section className="panel">
                                <div className="panel-heading">
                                    <div>
                                        <h3>{translate('portal','acct_step_refer')}</h3>
                                        <p>{translate('portal','acct_step_refer_hint')}</p>
                                    </div>
                                    <BadgeCheck size={22} className="text-muted" />
                                </div>
                                <div className="form-grid">
                                    <label>
                                        {translate('portal','acct_your_reference')}
                                        <input value={form.data.reference_code} onChange={(e) => form.setData('reference_code', e.target.value.toUpperCase())} placeholder={translate('portal','acct_reference_placeholder')} />
                                        {form.errors.reference_code && <span className="field-error">{form.errors.reference_code}</span>}
                                    </label>
                                    <label>
                                        {translate('portal','acct_your_refer_code')}
                                        <input required value={form.data.refer_code} onChange={(e) => form.setData('refer_code', e.target.value.toUpperCase())} placeholder={translate('portal','acct_refer_placeholder')} />
                                        {form.errors.refer_code && <span className="field-error">{form.errors.refer_code}</span>}
                                    </label>
                                </div>
                                {referPreviewError && (
                                    <p className="muted" style={{ marginTop: 10, fontSize: 13 }}>{referPreviewError}</p>
                                )}
                                {referWarnings.length > 0 && (
                                    <div className="error" style={{ marginTop: 10 }}>
                                        {referWarnings.map((warning) => <div key={warning}>{warning}</div>)}
                                    </div>
                                )}
                                {consumers && (
                                    <div style={{ marginTop: 12, fontSize: 13 }}>
                                        <b>{translate('portal','acct_commission_consumers')}</b>
                                        <div>{translate('portal','acct_direct_referral')}: {consumers.direct_referral ? `${consumers.direct_referral.name} (${consumers.direct_referral.refer_code})` : '→ unassigned_reserve'}</div>
                                        <div>{translate('portal','acct_d2d_gen2')}: {consumers.d2d_gen2 ? `${consumers.d2d_gen2.name} (${consumers.d2d_gen2.refer_code})` : '→ unassigned_reserve'}</div>
                                        <div>{translate('portal','acct_d2d_gen3')}: {consumers.d2d_gen3 ? `${consumers.d2d_gen3.name} (${consumers.d2d_gen3.refer_code})` : '→ unassigned_reserve'}</div>
                                    </div>
                                )}
                            </section>

                            <section className="panel">
                                <div className="panel-heading">
                                    <div>
                                        <h3>{translate('portal','acct_step_contact')}</h3>
                                        <p>{translate('portal','acct_step_contact_hint')}</p>
                                    </div>
                                    <Phone size={22} className="text-muted" />
                                </div>
                                <div className="form-grid">
                                    <label>
                                        {translate('portal','acct_mobile')}
                                        <input required value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" />
                                        {form.errors.phone && <span className="field-error">{form.errors.phone}</span>}
                                    </label>
                                    <label>
                                        {translate('portal','acct_email')}
                                        <input type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                        {form.errors.email && <span className="field-error">{form.errors.email}</span>}
                                    </label>
                                    <label>
                                        {translate('portal','acct_extra_contact')}
                                        <input value={form.data.extra_contact} onChange={(e) => form.setData('extra_contact', e.target.value)} />
                                    </label>
                                    {form.data.role === 'sub_dealer' ? (
                                        <label>
                                            {translate('portal','acct_shop_name')}
                                            <input required value={form.data.shop_name} onChange={(e) => form.setData('shop_name', e.target.value)} placeholder={translate('portal','acct_shop_placeholder')} />
                                            {form.errors.shop_name && <span className="field-error">{form.errors.shop_name}</span>}
                                        </label>
                                    ) : (
                                        <label>
                                            {translate('portal','acct_pharmacy')}
                                            <input value={form.data.pharmacy} onChange={(e) => form.setData('pharmacy', e.target.value)} />
                                        </label>
                                    )}
                                </div>
                            </section>
                        </>
                    )}
                </div>

                <div className="account-create-sidebar">
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>{translate('portal','acct_staff_audit')}</h3>
                            </div>
                            <ShieldAlert size={20} className="text-muted" />
                        </div>
                        <label>
                            {translate('portal','acct_verification_note')}
                            <textarea rows={3} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </label>
                        {form.data.role === 'dealer' && (
                            <label className="checkbox-label" style={{ marginTop: 12 }}>
                                <input type="checkbox" checked={!!form.data.directory_visible} onChange={(e) => form.setData('directory_visible', e.target.checked)} />
                                <span>{translate('portal','acct_directory_visible')}</span>
                            </label>
                        )}
                        {Object.keys(form.errors).length > 0 && (
                            <div className="error" role="alert" style={{ marginTop: 12 }}>{translate('portal','acct_fix_errors')}</div>
                        )}
                        <button type="submit" className="button dark full-width" disabled={form.processing || (isPartner && referWarnings.length > 0)} style={{ marginTop: 16, justifyContent: 'center' }}>
                            {form.processing ? translate('portal','acct_creating') : isPartner ? translate('portal','acct_submit_partner') : translate('portal','acct_submit_staff')}
                        </button>
                    </section>
                    {isPartner && (
                        <section className="panel">
                            <div className="panel-heading">
                                <div><h3>{translate('portal','acct_business_point')}</h3></div>
                                <Store size={20} className="text-muted" />
                            </div>
                            <p className="muted" style={{ margin: 0, fontSize: 13 }}>
                                {translate('portal','acct_sms_hint')}
                            </p>
                        </section>
                    )}
                    {!isPartner && (
                        <section className="panel">
                            <div className="panel-heading"><div><h3>{translate('portal','acct_sponsor_legacy')}</h3></div><Building2 size={20} className="text-muted" /></div>
                            <label>
                                {translate('portal','acct_sponsoring_dealer')}
                                <select value={form.data.sponsor_id} onChange={(e) => form.setData('sponsor_id', e.target.value)}>
                                    <option value="">{translate('portal','acct_no_sponsor')}</option>
                                    {sponsors.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.dealer_code})</option>)}
                                </select>
                            </label>
                        </section>
                    )}
                </div>
            </form>
        </Shell>
    );
}
