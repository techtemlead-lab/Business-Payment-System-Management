import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import { label, type Row, DateInput, FormErrors } from '../components/UI';
import { ArrowLeft, Search, AlertTriangle, CheckCircle2, Check } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import SearchSelect from '../components/SearchSelect';
import { toLatin } from '../shared/countUp';
import { localizeDigits } from '../business-date';

type PlaceOption = { id: number; code: string; name: string; level: string; parent_id: number | null };
type CommissionPlace = { level: string; code: string | null; name: string | null; rate?: string | null; pool?: string | null };
type Consumer = { id: number | null; name: string; refer_code: string | null; rate: string; fund?: string } | null;
type PlacePreview = {
    label: string;
    available: boolean;
    commission_places: CommissionPlace[];
    area_pool_rate?: string;
    management_rate?: string;
    next_sub_dealer_code?: string | null;
};
type Section = { key: string; title: string; hint?: string; required: string[]; boxes: string[] };

const availablePermissionKeys = [
    'field_sales', 'sr_orders', 'sr_onboarding', 'staff_admin', 'payroll',
    'orders', 'order_review', 'inventory', 'catalog', 'accounts', 'finance', 'reports',
    'content', 'audit', 'pricing', 'cost_read', 'identity_write', 'partner_chat', 'support_desk', 'prescriber_verify',
] as const;

const kycBoxes = ['kyc_photo', 'kyc_nid_front', 'kyc_nid_back', 'kyc_birth_certificate', 'kyc_agreement', 'kyc_other'] as const;

/** The latest date of birth for someone who is 18 today (Dhaka calendar), as YYYY-MM-DD. */
function latestAdultBirthDate(): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const [year, month, day] = today.split('-');
    const candidate = `${Number(year) - 18}-${month}-${day}`;
    return month === '02' && day === '29' ? `${Number(year) - 18}-02-28` : candidate;
}

async function fetchJson<T>(url: string, failedMessage: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body?.message || body?.errors?.place_code?.[0] || failedMessage);
    }
    return response.json();
}

/** One box with its label, and its own error directly underneath. */
function Box({ text, error, hint, wide, children }: { text: string; error?: string | false | null; hint?: React.ReactNode; wide?: boolean; children: React.ReactNode }) {
    const control = React.isValidElement(children) && children.type !== 'div'
        ? React.cloneElement(children as React.ReactElement<{ 'aria-invalid'?: boolean }>, { 'aria-invalid': Boolean(error) })
        : children;

    return (
        <label className={`acf-box${wide ? ' is-wide' : ''}${error ? ' has-error' : ''}`}>
            <span className="acf-label">{text}</span>
            {control}
            {hint && !error && <small className="field-hint">{hint}</small>}
            {error && <span className="field-error" role="alert">{error}</span>}
        </label>
    );
}

export default function AccountCreate({
    intent = 'dealer',
    roles = ['dealer'],
    sponsors = [],
    place_divisions = [],
    default_identity_type = 'birth_certificate',
    bdris_enabled = false,
    management_referral = null,
}: {
    intent?: 'dealer' | 'sub_dealer' | 'staff';
    roles: string[];
    sponsors: Row[];
    place_divisions?: PlaceOption[];
    default_identity_type?: 'birth_certificate' | 'nid';
    bdris_enabled?: boolean;
    management_referral?: { place_code: string | null; enabled: boolean; auto_blank_reference: boolean } | null;
}) {
    const translate = useT();
    // Only owners may let an account share a mobile number another account already uses.
    const isOwner = ['super_admin', 'md', 'chairman'].includes(String((usePage().props as any).auth?.user?.role ?? ''));
    // The server refuses an NID number from anyone without this permission, so the choice is not offered to them.
    const canEnterNid = ((usePage().props as any).auth?.capabilities ?? []).includes('identity_write');
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content || '';
    const defaultRole = roles[0] || (intent === 'sub_dealer' ? 'sub_dealer' : intent === 'staff' ? 'employee' : 'dealer');
    const shellSection = intent === 'sub_dealer' ? 'sub-dealers' : intent === 'staff' ? 'employees' : 'accounts';
    const singlePartnerRole = roles.length === 1 && ['dealer', 'sub_dealer'].includes(roles[0]);

    const [dealerCodeTouched, setDealerCodeTouched] = useState(false);
    const form = useForm({
        role: defaultRole,
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
        phone: '',
        phone_share_reason: '',
        email: '',
        extra_contact: '',
        pharmacy: '',
        shop_name: '',
        directory_visible: false,
        status: 'active',
        reason: translate('portal', 'acct_default_reason'),
        permissions: ['orders', 'inventory'] as string[],
        sponsor_id: '',
        latitude: '',
        longitude: '',
        kyc_photo: null as File | null,
        kyc_nid_front: null as File | null,
        kyc_nid_back: null as File | null,
        kyc_birth_certificate: null as File | null,
        kyc_agreement: null as File | null,
        kyc_other: null as File | null,
    });
    type FormField = keyof typeof form.data & string;
    const errorOf = (field: string): string | undefined => (form.errors as Record<string, string | undefined>)[field];
    /** Sets one box and takes away its error at once, so a corrected box stops being red. */
    const setBox = <K extends FormField>(field: K, value: (typeof form.data)[K]) => {
        form.setData(field, value as never);
        if (errorOf(field)) {
            form.clearErrors(field as never);
        }
    };

    const isPartner = ['dealer', 'sub_dealer'].includes(form.data.role);
    const isSubDealer = form.data.role === 'sub_dealer';
    const hasPermissionChoice = ['employee', 'sr'].includes(form.data.role);

    const [bdrisMessage, setBdrisMessage] = useState('');
    const [bdrisLoading, setBdrisLoading] = useState(false);
    const [placePreview, setPlacePreview] = useState<PlacePreview | null>(null);
    const [placeError, setPlaceError] = useState('');
    const [placeChecking, setPlaceChecking] = useState(false);
    const placeLastChecked = useRef('');
    const placeLatestTyped = useRef('');
    const [ownCodeWarning, setOwnCodeWarning] = useState('');
    const [referenceWarnings, setReferenceWarnings] = useState<string[]>([]);
    const [referPreviewError, setReferPreviewError] = useState<string | null>(null);
    const [directReferrer, setDirectReferrer] = useState<Consumer>(null);
    const referRequest = useRef(0);

    const [divisionId, setDivisionId] = useState('');
    const [districtId, setDistrictId] = useState('');
    const [upazilaId, setUpazilaId] = useState('');
    const [unionId, setUnionId] = useState('');
    const [districts, setDistricts] = useState<PlaceOption[]>([]);
    const [upazilas, setUpazilas] = useState<PlaceOption[]>([]);
    const [unions, setUnions] = useState<PlaceOption[]>([]);
    const [addressSearch, setAddressSearch] = useState('');
    const [papersOpen, setPapersOpen] = useState(false);

    const formRef = useRef<HTMLFormElement>(null);
    const pageRef = useRef<HTMLDivElement>(null);
    const focusFirstErrorNext = useRef(false);

    const loadChildren = async (level: string, parentId: string, q = '') => {
        const params = new URLSearchParams({ level });
        if (parentId) params.set('parent_id', parentId);
        if (q) params.set('q', q);
        const json = await fetchJson<{ data: PlaceOption[] }>(
            `/commands/place-codes/children?${params}`,
            translate('portal', 'acct_request_failed'),
        );
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

    /** The progress stays in view just under the top bar, whose height changes with the screen. */
    useEffect(() => {
        const topBar = document.querySelector<HTMLElement>('.workspace-header');
        if (!topBar) {
            return undefined;
        }
        const observer = new ResizeObserver(() => pageRef.current?.style.setProperty('--acf-sticky-top', `${topBar.offsetHeight + 8}px`));
        observer.observe(topBar);
        return () => observer.disconnect();
    }, []);

    /** Staff start typing as soon as the page opens. On phones this would throw up the keyboard, so it stays off there. */
    useEffect(() => {
        if (window.matchMedia('(min-width: 981px)').matches) {
            formRef.current?.querySelector<HTMLElement>('.acf-section input:not([type="radio"]):not([readonly]), .acf-section select')?.focus();
        }
    }, []);

    /** After a refusal, the first wrong box comes into view and takes the cursor. */
    useEffect(() => {
        if (!focusFirstErrorNext.current || Object.keys(form.errors).length === 0) {
            return;
        }
        focusFirstErrorNext.current = false;
        const firstWrong = formRef.current?.querySelector<HTMLElement>('.has-error input:not([type="file"]):not([readonly]), .has-error select, .has-error textarea')
            ?? formRef.current?.querySelector<HTMLElement>('.has-error input');
        firstWrong?.closest('details')?.setAttribute('open', '');
        firstWrong?.scrollIntoView({ block: 'center', behavior: 'auto' });
        firstWrong?.focus({ preventScroll: true });
    }, [form.errors]);

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
            setBdrisMessage(result?.message || translate('portal', 'acct_lookup_finished'));
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
            setBdrisMessage(translate('portal', 'acct_bdris_unreachable'));
        } finally {
            setBdrisLoading(false);
        }
    };

    /** Checks the place code once it is typed: the code the person will get comes from this. */
    const previewPlace = async (force = false) => {
        const code = form.data.place_code.trim();
        if (!code) {
            if (force) {
                setPlaceError(translate('portal', isSubDealer ? 'acct_val_place_sub' : 'acct_val_place_dealer'));
            }
            return;
        }
        if (!force && code === placeLastChecked.current) {
            return;
        }
        placeLastChecked.current = code;
        setPlaceError('');
        setPlacePreview(null);
        setPlaceChecking(true);
        const role = form.data.role;
        try {
            const params = new URLSearchParams({ place_code: code, role });
            const json = await fetchJson<{ data: any }>(`/commands/identity/place-preview?${params}`, translate('portal', 'acct_request_failed'));
            if (placeLatestTyped.current.trim() !== code) {
                return;
            }
            setPlacePreview(json.data);
            const path = json.data.path;
            form.setData((prev) => ({
                ...prev,
                dealer_code: role === 'sub_dealer'
                    ? String(json.data.next_sub_dealer_code ?? '').toUpperCase()
                    : (dealerCodeTouched && prev.dealer_code.trim() ? prev.dealer_code.trim().toUpperCase() : String(path?.origin_place_code || code).trim().toUpperCase()),
                division: path?.division || prev.division,
                district: path?.district || prev.district,
                upazila: path?.upazila || prev.upazila,
                union: path?.union || prev.union,
            }));
        } catch (error: any) {
            setPlaceError(error.message || translate('portal', 'acct_place_not_found'));
        } finally {
            setPlaceChecking(false);
        }
    };

    const changePlaceCode = (typed: string) => {
        const placeCode = toLatin(typed);
        placeLatestTyped.current = placeCode;
        placeLastChecked.current = '';
        setPlacePreview(null);
        setPlaceError('');
        form.setData((prev) => ({ ...prev, place_code: placeCode, dealer_code: !isSubDealer && dealerCodeTouched ? prev.dealer_code : '' }));
        form.clearErrors('place_code');
    };

    useEffect(() => {
        if (!isPartner) {
            return undefined;
        }
        const handle = window.setTimeout(() => {
            const request = ++referRequest.current;
            const params = new URLSearchParams({
                reference_code: form.data.reference_code,
                refer_code: form.data.dealer_code,
            });
            fetchJson<{ data: { warnings: string[]; reference_warnings?: string[]; consumers: { direct_referral?: Consumer } | null } }>(
                `/commands/identity/refer-preview?${params}`,
                translate('portal', 'acct_request_failed'),
            )
                .then((json) => {
                    if (request !== referRequest.current) {
                        return;
                    }
                    const aboutTheBox = json.data.reference_warnings || [];
                    setReferenceWarnings(aboutTheBox);
                    setOwnCodeWarning((json.data.warnings || []).find((warning) => !aboutTheBox.includes(warning)) ?? '');
                    setDirectReferrer(json.data.consumers?.direct_referral ?? null);
                    setReferPreviewError(null);
                })
                .catch(() => {
                    if (request !== referRequest.current) {
                        return;
                    }
                    setOwnCodeWarning('');
                    setReferenceWarnings([]);
                    setDirectReferrer(null);
                    setReferPreviewError(translate('portal', 'acct_refer_preview_failed'));
                });
        }, 350);
        return () => window.clearTimeout(handle);
    }, [form.data.reference_code, form.data.dealer_code, isPartner]);

    const sections: Section[] = useMemo(() => {
        const finishKyc = isPartner ? [...kycBoxes] : [];
        const typeSection: Section[] = singlePartnerRole ? [] : [{ key: 'type', title: translate('portal', 'acct_step_type'), hint: translate('portal', 'acct_step_type_hint'), required: [], boxes: ['role'] }];
        if (!isPartner) {
            return [
                ...typeSection,
                ...(hasPermissionChoice ? [{ key: 'privileges', title: translate('portal', 'acct_employee_privileges'), required: [], boxes: ['permissions'] }] : []),
                { key: 'person', title: translate('portal', 'acct_step_contact'), required: ['name', 'email', 'phone'], boxes: ['name', 'email', 'phone', 'phone_share_reason'] },
                { key: 'last', title: translate('portal', 'acct_section_last'), required: ['reason'], boxes: ['reason', 'sponsor_id'] },
            ];
        }
        const identityNumber = form.data.identity_type === 'nid' ? 'nid' : 'birth_certificate_number';
        return [
            ...typeSection,
            { key: 'identity', title: translate('portal', 'acct_step_identity'), hint: translate('portal', 'acct_step_identity_hint'), required: [identityNumber, 'date_of_birth', 'name', 'name_bn', 'father_name', 'mother_name'], boxes: ['identity_type', identityNumber, 'date_of_birth', 'name', 'name_bn', 'father_name', 'mother_name', 'occupation'] },
            { key: 'address', title: translate('portal', 'acct_step_address'), hint: translate('portal', 'acct_step_address_hint'), required: [], boxes: ['village', 'post_office', 'latitude', 'longitude'] },
            { key: 'place', title: translate('portal', 'acct_step_area'), hint: translate('portal', isSubDealer ? 'acct_area_hint_sub' : 'acct_area_hint_dealer'), required: ['place_code'], boxes: ['place_code', 'dealer_code'] },
            { key: 'refer', title: translate('portal', 'acct_step_refer'), required: [], boxes: ['reference_code'] },
            { key: 'contact', title: translate('portal', 'acct_step_contact'), hint: translate('portal', 'acct_step_contact_hint'), required: ['phone', 'email', ...(isSubDealer ? ['shop_name'] : [])], boxes: ['phone', 'phone_share_reason', 'email', 'extra_contact', 'shop_name', 'pharmacy'] },
            { key: 'last', title: translate('portal', 'acct_section_last'), required: ['reason'], boxes: ['reason', 'directory_visible', ...finishKyc] },
        ];
    }, [isPartner, isSubDealer, hasPermissionChoice, singlePartnerRole, form.data.identity_type, translate]);

    const isFilled = (field: string): boolean => String((form.data as Record<string, unknown>)[field] ?? '').trim() !== '';
    const requiredBoxes = sections.flatMap((section) => section.required);
    const requiredFilled = requiredBoxes.filter(isFilled).length;
    const progressPercent = requiredBoxes.length === 0 ? 100 : Math.round((requiredFilled / requiredBoxes.length) * 100);
    const allBoxes = sections.flatMap((section) => section.boxes);
    const errorCount = Object.entries(form.errors).filter(([field, message]) => message && allBoxes.includes(field)).length;
    const progressText = translate('portal', 'acct_progress_count', { done: requiredFilled, total: requiredBoxes.length });

    const stateOf = (section: Section) => {
        const errors = section.boxes.filter((box) => errorOf(box)).length;
        const filled = section.required.filter(isFilled).length;
        const isOptional = section.required.length === 0;
        return { errors, filled, isOptional, isDone: errors === 0 && (isOptional ? section.boxes.some(isFilled) : filled === section.required.length) };
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.clearErrors();
        const refusals: Record<string, string> = {};
        requiredBoxes.filter((field) => !isFilled(field)).forEach((field) => { refusals[field] = translate('portal', 'acct_box_required'); });
        if (isPartner && referenceWarnings.length > 0) {
            refusals.reference_code = referenceWarnings[0];
        }
        if (isPartner && ownCodeWarning && !isSubDealer) {
            refusals.dealer_code = ownCodeWarning;
        }
        if (Object.keys(refusals).length > 0) {
            focusFirstErrorNext.current = true;
            form.setError(refusals as never);
            return;
        }
        const afterRefusal = { onError: () => { focusFirstErrorNext.current = true; } };
        if (!isPartner) {
            form.transform((data) => {
                const { permissions, ...rest } = data;
                return hasPermissionChoice ? { ...rest, permissions } : rest;
            });
            form.post('/commands/accounts', { preserveScroll: true, ...afterRefusal });
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
            phone: data.phone,
            phone_share_reason: data.phone_share_reason,
            email: data.email,
            extra_contact: data.extra_contact,
            pharmacy: data.role === 'sub_dealer' ? (data.shop_name || data.pharmacy) : data.pharmacy,
            shop_name: data.role === 'sub_dealer' ? data.shop_name : '',
            directory_visible: data.directory_visible,
            status: data.status,
            reason: data.reason,
            kyc_photo: data.kyc_photo,
            kyc_nid_front: data.kyc_nid_front,
            kyc_nid_back: data.kyc_nid_back,
            kyc_birth_certificate: data.kyc_birth_certificate,
            kyc_agreement: data.kyc_agreement,
            kyc_other: data.kyc_other,
        }));
        form.post('/commands/accounts', { preserveScroll: true, forceFormData: true, ...afterRefusal });
    };

    /** Enter never sends a half-filled form. It moves to the next box, as staff expect when copying from paper. */
    const goToNextBoxOnEnter = (e: React.KeyboardEvent<HTMLFormElement>) => {
        const box = e.target as HTMLElement;
        if (e.key !== 'Enter' || !(box instanceof HTMLInputElement) || box.type === 'submit' || box.type === 'file') {
            return;
        }
        e.preventDefault();
        if (box.dataset.placeCode !== undefined) {
            void previewPlace(true);
        }
        const boxes = Array.from(formRef.current?.querySelectorAll<HTMLElement>('input:not([type="hidden"]):not([type="date"]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button[type="submit"]') ?? [])
            .filter((candidate) => candidate.tabIndex >= 0 && candidate.offsetParent !== null);
        boxes[boxes.indexOf(box) + 1]?.focus();
    };

    const jumpTo = (e: React.MouseEvent<HTMLAnchorElement>, key: string) => {
        e.preventDefault();
        const section = document.getElementById(`acf-${key}`);
        section?.scrollIntoView({ block: 'start' });
        section?.querySelector<HTMLElement>('input:not([readonly]), select, textarea')?.focus({ preventScroll: true });
    };

    const commissionLines = placePreview?.commission_places ?? [];
    const placeLevelTitle = (level: string) => translate('portal', ({ management: 'acct_level_management', division: 'acct_level_div', district: 'acct_level_dist', upazila: 'acct_level_upazila' } as Record<string, string>)[level] ?? 'acct_level_union');
    // Only needed once the server says the number is in use, so it stays out of the way until then.
    const phoneRefused = Boolean(errorOf('phone')) && errorOf('phone') !== translate('portal', 'acct_box_required');
    const [phoneWasRefused, setPhoneWasRefused] = useState(false);
    useEffect(() => {
        if (phoneRefused) {
            setPhoneWasRefused(true);
        }
    }, [phoneRefused]);
    const showsPhoneShareReason = isOwner && (phoneWasRefused || form.data.phone_share_reason !== '');
    const phoneShareReason = showsPhoneShareReason && (
        <Box text={translate('portal', 'acct_phone_share_reason')} error={errorOf('phone_share_reason')} hint={translate('portal', 'acct_phone_share_reason_hint')} wide>
            <input maxLength={500} autoComplete="off" value={form.data.phone_share_reason} onChange={(e) => setBox('phone_share_reason', e.target.value)} />
        </Box>
    );
    const typedReference = form.data.reference_code.trim() !== '';
    const referenceHint = !typedReference && management_referral?.enabled && management_referral.auto_blank_reference && management_referral.place_code
        ? translate('portal', 'acct_reference_blank_hint', { code: management_referral.place_code })
        : undefined;
    const referrerName = typedReference && directReferrer && referenceWarnings.length === 0
        ? `${directReferrer.name}${directReferrer.refer_code ? ` (${directReferrer.refer_code})` : ''}`
        : '';
    const summaryRows = isPartner ? [
        { term: translate('portal', 'acct_summary_person'), value: form.data.name_bn || form.data.name },
        { term: translate('portal', 'acct_summary_code'), value: form.data.dealer_code },
        { term: translate('portal', 'acct_summary_place'), value: placePreview?.label ?? '' },
        { term: translate('portal', 'acct_summary_referrer'), value: referrerName || (typedReference ? '' : (referenceHint ? translate('portal', 'acct_management_referrer') : translate('portal', 'acct_summary_blank_referrer'))) },
    ] : [];
    const renderNumber = (position: number) => localizeDigits(String(position));
    const sectionNumber = (key: string) => renderNumber(sections.findIndex((section) => section.key === key) + 1);
    const heading = (key: string, section: Section | undefined) => {
        if (!section) {
            return null;
        }
        const state = stateOf(section);

        return (
            <header className="acf-section-head">
                <span className={`acf-number${state.isDone ? ' is-done' : ''}`} aria-hidden="true">{state.isDone ? <Check size={14} strokeWidth={3} /> : sectionNumber(key)}</span>
                <div>
                    <h3>{section.title}</h3>
                    {section.hint && <p>{section.hint}</p>}
                </div>
            </header>
        );
    };
    const sectionFor = (key: string) => sections.find((section) => section.key === key);

    return (
        <Shell section={shellSection}>
            <Head title={intent === 'sub_dealer' ? translate('portal', 'acct_head_title_sub') : intent === 'staff' ? translate('portal', 'acct_head_title') : translate('portal', 'acct_head_title_dealer')} />
            <div className="section-header-row">
                <Link href={intent === 'sub_dealer' ? '/sub-dealers' : intent === 'staff' ? '/employees' : '/accounts'} className="text-link flex-center">
                    <ArrowLeft size={16} /> {intent === 'sub_dealer' ? translate('portal', 'acct_back_subdealers') : intent === 'staff' ? translate('portal', 'acct_back') : translate('portal', 'acct_back_dealers')}
                </Link>
            </div>

            <div className="registry-heading">
                <div>
                    <h2>{intent === 'sub_dealer' ? translate('portal', 'acct_heading_sub') : translate('portal', 'acct_heading')}</h2>
                    <p>{intent === 'sub_dealer' ? translate('portal', 'acct_intro_sub') : translate('portal', 'acct_intro')}</p>
                </div>
            </div>

            <div ref={pageRef} className="acf-page">
            <div className="acf-phone-progress" aria-hidden="true">
                <div className="acf-bar"><span style={{ width: `${progressPercent}%` }} /></div>
                <small>{errorCount > 0 ? translate('portal', 'acct_issues_count', { count: errorCount }) : progressText}</small>
            </div>

            <form ref={formRef} onSubmit={submit} onKeyDown={goToNextBoxOnEnter} className="acf-layout" noValidate>
                <div className="acf-main">
                    {!singlePartnerRole && (
                        <section className="panel acf-section" id="acf-type">
                            {heading('type', sectionFor('type'))}
                            <div className="acf-grid">
                                <Box text={translate('portal', 'acct_role')} error={errorOf('role')}>
                                    <select
                                        value={form.data.role}
                                        onChange={(e) => {
                                            const newRole = e.target.value;
                                            setDealerCodeTouched(false);
                                            placeLastChecked.current = '';
                                            form.setData((prev) => ({
                                                ...prev,
                                                role: newRole,
                                                place_code: '',
                                                dealer_code: '',
                                                permissions: newRole === 'sr' ? ['field_sales', 'sr_orders', 'sr_onboarding'] : ['orders', 'inventory'],
                                            }));
                                            setPlacePreview(null);
                                        }}
                                    >
                                        {roles.map((r) => (
                                            <option key={r} value={r}>
                                                {r === 'dealer' ? translate('portal', 'acct_role_dealer') : r === 'sub_dealer' ? translate('portal', 'acct_role_sub_dealer') : label(r, translate)}
                                            </option>
                                        ))}
                                    </select>
                                </Box>
                            </div>
                        </section>
                    )}

                    {hasPermissionChoice && (
                        <section className="panel acf-section" id="acf-privileges">
                            {heading('privileges', sectionFor('privileges'))}
                            <div className="hub-grid is-wide acr-option-grid">
                                {availablePermissionKeys.filter((key) => form.data.role !== 'sr' || ['field_sales', 'sr_orders', 'sr_onboarding'].includes(key)).map((key) => {
                                    const checked = form.data.permissions.includes(key);
                                    return (
                                        <label key={key} className={`acr-option${checked ? ' is-checked' : ''}`}>
                                            <input type="checkbox" checked={checked} onChange={() => form.setData('permissions', checked ? form.data.permissions.filter((k) => k !== key) : [...form.data.permissions, key])} />
                                            <div>
                                                <b className="ops-fs-xs">{translate('portal', `perm_${key}`)}</b>
                                                <small className="ops-block ops-muted">{translate('portal', `perm_${key}_hint`)}</small>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    {!isPartner && (
                        <section className="panel acf-section" id="acf-person">
                            {heading('person', sectionFor('person'))}
                            <div className="acf-grid">
                                <Box text={translate('portal', 'acct_full_name')} error={errorOf('name')}><input autoComplete="off" value={form.data.name} onChange={(e) => setBox('name', e.target.value)} /></Box>
                                <Box text={translate('portal', 'acct_email')} error={errorOf('email')}><input type="email" autoComplete="off" value={form.data.email} onChange={(e) => setBox('email', e.target.value)} /></Box>
                                <Box text={translate('portal', 'acct_phone')} error={errorOf('phone')}><input type="tel" inputMode="tel" autoComplete="off" value={form.data.phone} onChange={(e) => setBox('phone', e.target.value)} placeholder={translate('portal', 'acct_phone_ph')} /></Box>
                                {phoneShareReason}
                            </div>
                        </section>
                    )}

                    {isPartner && (
                        <>
                            <section className="panel acf-section" id="acf-identity">
                                {heading('identity', sectionFor('identity'))}
                                <div className="acf-segment" role="radiogroup" aria-label={translate('portal', 'acct_step_identity')}>
                                    <label className={form.data.identity_type === 'birth_certificate' ? 'is-on' : ''}>
                                        <input type="radio" name="identity_type" checked={form.data.identity_type === 'birth_certificate'} onChange={() => { setBox('identity_type', 'birth_certificate'); form.clearErrors('nid'); }} />
                                        <span>{translate('portal', 'acct_birth_certificate')}</span>
                                    </label>
                                    {canEnterNid && (
                                        <label className={form.data.identity_type === 'nid' ? 'is-on' : ''}>
                                            <input type="radio" name="identity_type" checked={form.data.identity_type === 'nid'} onChange={() => { setBox('identity_type', 'nid'); form.clearErrors('birth_certificate_number'); }} />
                                            <span>{translate('portal', 'acct_nid')}</span>
                                        </label>
                                    )}
                                </div>
                                {!canEnterNid && <p className="acf-note">{translate('portal', 'acct_nid_needs_permission')}</p>}
                                <div className="acf-grid">
                                    {form.data.identity_type === 'birth_certificate' ? (
                                        <Box text={translate('portal', 'acct_birth_cert_number')} error={errorOf('birth_certificate_number')}>
                                            <input inputMode="numeric" autoComplete="off" value={form.data.birth_certificate_number} onChange={(e) => setBox('birth_certificate_number', e.target.value)} />
                                        </Box>
                                    ) : (
                                        <Box text={translate('portal', 'acct_nid_number')} error={errorOf('nid')}>
                                            <input inputMode="numeric" autoComplete="off" value={form.data.nid} onChange={(e) => setBox('nid', e.target.value)} />
                                        </Box>
                                    )}
                                    <Box text={translate('portal', 'acct_dob')} error={errorOf('date_of_birth')} hint={translate('portal', 'acct_dob_age_hint')}>
                                        <DateInput max={latestAdultBirthDate()} value={form.data.date_of_birth} onChange={(e) => setBox('date_of_birth', e.target.value)} />
                                    </Box>
                                    {form.data.identity_type === 'birth_certificate' && bdris_enabled && (
                                        <div className="acf-box is-wide acf-inline">
                                            <button type="button" className="button dark" onClick={lookupBdris} disabled={bdrisLoading}>
                                                <Search size={16} /> {bdrisLoading ? translate('portal', 'acct_searching_bdris') : translate('portal', 'acct_search_bdris')}
                                            </button>
                                            {bdrisMessage && <span className="muted ops-fs-sm">{bdrisMessage}</span>}
                                        </div>
                                    )}
                                    <Box text={translate('portal', 'acct_name_en')} error={errorOf('name')}><input autoComplete="off" value={form.data.name} onChange={(e) => setBox('name', e.target.value)} /></Box>
                                    <Box text={translate('portal', 'acct_name_bn')} error={errorOf('name_bn')}><input autoComplete="off" value={form.data.name_bn} onChange={(e) => setBox('name_bn', e.target.value)} /></Box>
                                    <Box text={translate('portal', 'acct_father_name')} error={errorOf('father_name')}><input autoComplete="off" value={form.data.father_name} onChange={(e) => setBox('father_name', e.target.value)} /></Box>
                                    <Box text={translate('portal', 'acct_mother_name')} error={errorOf('mother_name')}><input autoComplete="off" value={form.data.mother_name} onChange={(e) => setBox('mother_name', e.target.value)} /></Box>
                                    <Box text={translate('portal', 'acct_occupation')} error={errorOf('occupation')}><input autoComplete="off" value={form.data.occupation} onChange={(e) => setBox('occupation', e.target.value)} /></Box>
                                </div>
                            </section>

                            <section className="panel acf-section" id="acf-address">
                                {heading('address', sectionFor('address'))}
                                <div className="acf-grid">
                                    <Box text={translate('portal', 'acct_division')}>
                                        <SearchSelect
                                            value={divisionId}
                                            onChange={(value) => { setDivisionId(value); setDistrictId(''); setUpazilaId(''); setUnionId(''); }}
                                            emptyLabel={translate('portal', 'acct_select_division')}
                                            options={place_divisions.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
                                        />
                                    </Box>
                                    <Box text={translate('portal', 'acct_district')}>
                                        <SearchSelect
                                            value={districtId}
                                            onChange={(value) => { setDistrictId(value); setUpazilaId(''); setUnionId(''); }}
                                            disabled={!divisionId}
                                            emptyLabel={translate('portal', 'acct_select_district')}
                                            options={districts.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
                                        />
                                    </Box>
                                    <Box text={translate('portal', 'acct_upazila')}>
                                        <SearchSelect
                                            value={upazilaId}
                                            onChange={(value) => { setUpazilaId(value); setUnionId(''); }}
                                            disabled={!districtId}
                                            emptyLabel={translate('portal', 'acct_select_upazila')}
                                            options={upazilas.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
                                        />
                                    </Box>
                                    <Box text={translate('portal', 'acct_union')}>
                                        <SearchSelect
                                            value={unionId}
                                            onChange={(value) => setUnionId(value)}
                                            disabled={!upazilaId}
                                            emptyLabel={translate('portal', 'acct_select_union')}
                                            options={unions.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
                                        />
                                    </Box>
                                    <Box text={translate('portal', 'acct_village')} error={errorOf('village')}><input autoComplete="off" value={form.data.village} onChange={(e) => setBox('village', e.target.value)} /></Box>
                                    <Box text={translate('portal', 'acct_post_office')} error={errorOf('post_office')}><input autoComplete="off" value={form.data.post_office} onChange={(e) => setBox('post_office', e.target.value)} /></Box>
                                </div>
                                <details className="acf-more" open={Boolean(errorOf('latitude') || errorOf('longitude')) || undefined}>
                                    <summary>{translate('portal', 'acct_more_address')}</summary>
                                    <div className="acf-grid">
                                        <Box text={translate('portal', 'acct_search_places')} wide>
                                            <input autoComplete="off" value={addressSearch} onChange={(e) => setAddressSearch(e.target.value)} placeholder={translate('portal', 'acct_filter_places')} />
                                        </Box>
                                        <Box text={translate('portal', 'acct_latitude')} error={errorOf('latitude')}>
                                            <input type="number" step="any" min="-90" max="90" value={form.data.latitude} onChange={(e) => setBox('latitude', e.target.value)} placeholder={translate('portal', 'acct_latitude_ph')} />
                                        </Box>
                                        <Box text={translate('portal', 'acct_longitude')} error={errorOf('longitude')}>
                                            <input type="number" step="any" min="-180" max="180" value={form.data.longitude} onChange={(e) => setBox('longitude', e.target.value)} placeholder={translate('portal', 'acct_longitude_ph')} />
                                        </Box>
                                    </div>
                                    <p className="acf-note">{translate('portal', 'acct_gps_hint')}</p>
                                </details>
                            </section>

                            <section className="panel acf-section" id="acf-place">
                                {heading('place', sectionFor('place'))}
                                <div className="acf-grid">
                                    <Box text={translate('portal', isSubDealer ? 'acct_union_code' : 'acct_place_code')} error={errorOf('place_code') || placeError}>
                                        <div className="acf-with-button">
                                            <input
                                                data-place-code=""
                                                autoComplete="off"
                                                aria-invalid={Boolean(errorOf('place_code') || placeError)}
                                                value={form.data.place_code}
                                                onChange={(e) => changePlaceCode(e.target.value)}
                                                onBlur={() => void previewPlace()}
                                                placeholder={translate('portal', isSubDealer ? 'acct_union_code_ph' : 'acct_place_code_ph')}
                                            />
                                            <button type="button" className="button" tabIndex={-1} disabled={placeChecking} onClick={() => void previewPlace(true)}>{translate('portal', 'acct_resolve')}</button>
                                        </div>
                                    </Box>
                                    {isSubDealer ? (
                                        <Box text={translate('portal', 'acct_auto_sub_code')} hint={translate('portal', 'acct_code_is_refer')}>
                                            <input value={form.data.dealer_code} readOnly tabIndex={-1} />
                                        </Box>
                                    ) : (
                                        <Box text={translate('portal', 'acct_dealer_code_override')} error={errorOf('dealer_code') || ownCodeWarning} hint={translate('portal', 'acct_code_is_refer')}>
                                            <input
                                                autoComplete="off"
                                                value={form.data.dealer_code}
                                                onChange={(e) => {
                                                    const next = toLatin(e.target.value).toUpperCase();
                                                    setDealerCodeTouched(next.trim() !== '');
                                                    setBox('dealer_code', next);
                                                }}
                                                placeholder={translate('portal', 'acct_dealer_code_default')}
                                            />
                                        </Box>
                                    )}
                                </div>
                                {placePreview && (
                                    <div className={`acf-place${placePreview.available ? '' : ' is-taken'}`}>
                                        <div className="acf-place-head">
                                            {placePreview.available ? <CheckCircle2 size={16} className="icon-ok" /> : <AlertTriangle size={16} className="icon-warn" />}
                                            <b>{placePreview.label}</b>
                                        </div>
                                        {!placePreview.available && <p className="acf-note">{translate('portal', 'acct_area_taken')}</p>}
                                        <details className="acf-more is-quiet">
                                            <summary tabIndex={-1}>{translate('portal', 'acct_place_shares')}</summary>
                                            <p className="acf-note">{translate('portal', 'acct_area_pool', { pool: String(placePreview.area_pool_rate || '10.00'), mgmt: String(placePreview.management_rate || '2.00') })}</p>
                                            <ul className="acf-shares">
                                                {commissionLines.map((row) => (
                                                    <li key={row.level}>
                                                        <b>{placeLevelTitle(row.level)}{row.rate ? ` (${row.rate}%)` : ''}:</b>{' '}
                                                        {row.code ? `${row.code} — ${row.name || '—'}` : (row.level === 'management' ? translate('portal', 'acct_mgmt_reserve') : translate('portal', 'acct_not_on_path'))}
                                                    </li>
                                                ))}
                                            </ul>
                                        </details>
                                    </div>
                                )}
                            </section>

                            <section className="panel acf-section" id="acf-refer">
                                {heading('refer', sectionFor('refer'))}
                                <div className="acf-grid">
                                    <Box
                                        text={translate('portal', 'acct_your_reference')}
                                        error={errorOf('reference_code') || referenceWarnings[0]}
                                        hint={referrerName ? <span className="acf-ok"><CheckCircle2 size={14} /> {translate('portal', 'acct_refer_belongs_to', { name: referrerName })}</span> : (referPreviewError || referenceHint)}
                                        wide
                                    >
                                        <input
                                            autoComplete="off"
                                            value={form.data.reference_code}
                                            onChange={(e) => {
                                                setDirectReferrer(null);
                                                setReferenceWarnings([]);
                                                setBox('reference_code', toLatin(e.target.value).toUpperCase());
                                            }}
                                            placeholder={translate('portal', 'acct_reference_placeholder')}
                                        />
                                    </Box>
                                </div>
                            </section>

                            <section className="panel acf-section" id="acf-contact">
                                {heading('contact', sectionFor('contact'))}
                                <div className="acf-grid">
                                    <Box text={translate('portal', 'acct_mobile')} error={errorOf('phone')}>
                                        <input type="tel" inputMode="tel" autoComplete="off" value={form.data.phone} onChange={(e) => setBox('phone', e.target.value)} placeholder={translate('portal', 'acct_phone_ph')} />
                                    </Box>
                                    {phoneShareReason}
                                    <Box text={translate('portal', 'acct_email')} error={errorOf('email')}>
                                        <input type="email" inputMode="email" autoComplete="off" value={form.data.email} onChange={(e) => setBox('email', e.target.value)} />
                                    </Box>
                                    <Box text={translate('portal', 'acct_extra_contact')} error={errorOf('extra_contact')}>
                                        <input autoComplete="off" value={form.data.extra_contact} onChange={(e) => setBox('extra_contact', e.target.value)} />
                                    </Box>
                                    {isSubDealer ? (
                                        <Box text={translate('portal', 'acct_shop_name')} error={errorOf('shop_name')}>
                                            <input autoComplete="off" value={form.data.shop_name} onChange={(e) => setBox('shop_name', e.target.value)} placeholder={translate('portal', 'acct_shop_placeholder')} />
                                        </Box>
                                    ) : (
                                        <Box text={translate('portal', 'acct_pharmacy')} error={errorOf('pharmacy')}>
                                            <input autoComplete="off" value={form.data.pharmacy} onChange={(e) => setBox('pharmacy', e.target.value)} />
                                        </Box>
                                    )}
                                </div>
                            </section>
                        </>
                    )}

                    <section className="panel acf-section" id="acf-last">
                        {heading('last', sectionFor('last'))}
                        <div className="acf-grid">
                            <Box text={translate('portal', 'acct_verification_note')} error={errorOf('reason')} wide>
                                <textarea rows={2} value={form.data.reason} onChange={(e) => setBox('reason', e.target.value)} />
                            </Box>
                            {form.data.role === 'dealer' && (
                                <label className="checkbox-label acf-box is-wide acf-check">
                                    <input type="checkbox" checked={!!form.data.directory_visible} onChange={(e) => form.setData('directory_visible', e.target.checked)} />
                                    <span>{translate('portal', 'acct_directory_visible')}</span>
                                </label>
                            )}
                            {!isPartner && (
                                <Box text={translate('portal', 'acct_sponsoring_dealer')} error={errorOf('sponsor_id')} wide>
                                    <SearchSelect
                                        value={form.data.sponsor_id}
                                        onChange={(value) => setBox('sponsor_id', value)}
                                        emptyLabel={translate('portal', 'acct_no_sponsor')}
                                        options={sponsors.map((s) => ({ value: String(s.id), label: `${s.name} (${s.dealer_code})` }))}
                                    />
                                </Box>
                            )}
                        </div>
                        {isPartner && (
                            <details className="acf-more" open={papersOpen || kycBoxes.some((box) => errorOf(box)) || undefined} onToggle={(e) => setPapersOpen(e.currentTarget.open)}>
                                <summary>{translate('portal', 'optional_media_title')}{kycBoxes.some((box) => form.data[box]) ? ` · ${localizeDigits(String(kycBoxes.filter((box) => form.data[box]).length))}` : ''}</summary>
                                <p className="acf-note">{translate('portal', 'optional_media_hint')}</p>
                                <div className="acf-grid">
                                    {kycBoxes.map((box) => (
                                        <Box key={box} text={translate('portal', box)} error={errorOf(box)}>
                                            <input type="file" accept={box === 'kyc_photo' ? 'image/*' : 'image/*,.pdf'} onChange={(e) => { form.setData(box, e.target.files?.[0] || null); form.clearErrors(box); }} />
                                        </Box>
                                    ))}
                                </div>
                            </details>
                        )}
                    </section>
                </div>

                <aside className="acf-rail">
                    <section className="panel acf-progress">
                        <div className="acf-progress-head">
                            <h3>{translate('portal', 'acct_progress_title')}</h3>
                            <b>{localizeDigits(String(progressPercent))}%</b>
                        </div>
                        <div className="acf-bar"><span style={{ width: `${progressPercent}%` }} /></div>
                        <p className="acf-note">{progressText}</p>
                        <ol>
                            {sections.map((section, index) => {
                                const state = stateOf(section);
                                return (
                                    <li key={section.key} className={state.errors > 0 ? 'is-wrong' : state.isDone ? 'is-done' : ''}>
                                        <a href={`#acf-${section.key}`} tabIndex={-1} onClick={(e) => jumpTo(e, section.key)}>
                                            <span className="acf-number" aria-hidden="true">{state.isDone ? <Check size={12} strokeWidth={3} /> : renderNumber(index + 1)}</span>
                                            <span className="acf-rail-title">{section.title}</span>
                                            <small>
                                                {state.errors > 0
                                                    ? localizeDigits(String(state.errors))
                                                    : state.isOptional
                                                        ? (state.isDone ? '' : translate('portal', 'acct_progress_optional'))
                                                        : `${localizeDigits(String(state.filled))}/${localizeDigits(String(section.required.length))}`}
                                            </small>
                                        </a>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>

                    {isPartner && (
                        <section className="panel acf-summary">
                            <h3>{translate('portal', 'acct_summary_title')}</h3>
                            <dl>
                                {summaryRows.map((row) => (
                                    <div key={row.term}>
                                        <dt>{row.term}</dt>
                                        <dd className={row.value ? '' : 'is-empty'}>{row.value || translate('portal', 'acct_summary_empty')}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="acf-note">{translate('portal', 'acct_sms_hint')}</p>
                        </section>
                    )}

                    <div className="acf-submit">
                        <FormErrors errors={form.errors} besideFields={allBoxes} />
                        {errorCount > 0 && <p className="acf-issues" role="status">{translate('portal', 'acct_issues_count', { count: errorCount })}</p>}
                        <button type="submit" className="button dark full-width" disabled={form.processing}>
                            {form.processing ? translate('portal', 'acct_creating') : isPartner ? translate('portal', 'acct_submit_partner') : translate('portal', 'acct_submit_staff')}
                        </button>
                        <small className="acf-note">{translate('portal', 'acct_enter_moves')}</small>
                    </div>
                </aside>
            </form>
            </div>
        </Shell>
    );
}
