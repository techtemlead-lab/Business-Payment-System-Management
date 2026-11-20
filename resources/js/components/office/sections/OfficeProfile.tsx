import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowUpRight, LogOut } from 'lucide-react';
import type { ReactNode } from 'react';
import { ProfilePhoto } from '../../ProfilePhoto';
import { Badge, DateInput, FormErrors, label } from '../../UI';
import { ownerConfirmFields, OwnerConfirmationNote } from '../OwnerConfirmation';
import { bilingualBox } from '../../../shared/banglaFill/pair';
import { useT } from '../../../shared/i18n/t';

type Props = {
    user: any;
    profile: any;
    security: any;
    base: string;
};

/** The word under the name: the owner is the company owner, never "staff"; MD, Chairman and staff show their own title. */
export function roleTitle(role: string, translate: ReturnType<typeof useT>): string {
    return role === 'super_admin' ? translate('cr15', 'profile_role_owner') : label(role, translate);
}

/** One titled box of the page. Each box saves on its own. */
function Card({ title, hint, tour, children }: { title: string; hint?: string; tour?: string; children: ReactNode }) {
    return (
        <section className="office-card" data-tour={tour}>
            <h2>{title}</h2>
            {hint && <p className="muted office-card-hint">{hint}</p>}
            {children}
        </section>
    );
}

function Row({ name, text, errors, children, hint, wide }: { name: string; text: string; errors: Record<string, unknown>; children: ReactNode; hint?: string; wide?: boolean }) {
    return (
        <label className={wide ? 'office-card-wide' : undefined}>
            {text}
            {children}
            {hint && <small className="field-hint">{hint}</small>}
            {errors[name] ? <span className="field-error" role="alert">{String(errors[name])}</span> : null}
        </label>
    );
}

function SaveButton({ busy, text }: { busy: boolean; text: string }) {
    const translate = useT();
    return <button className="button dark" disabled={busy}>{busy ? translate('portal', 'saving') : text}<ArrowUpRight size={17} /></button>;
}

function Fact({ name, value }: { name: string; value: ReactNode }) {
    return <div><dt>{name}</dt><dd>{value}</dd></div>;
}

/** Card 1: name, Bangla name, email and mobile numbers. */
function ContactCard({ office, isOwner, security }: { office: any; isOwner: boolean; security: any }) {
    const translate = useT();
    const c = office.contact;
    const form = useForm({ name: c.name || '', name_bn: c.name_bn || '', email: c.email || '', phone: c.phone || '', phone2: c.phone2 || '', current_password: '', confirm_code: '' });
    const loginChanged = form.data.email !== (c.email || '') || form.data.phone !== (c.phone || '');
    // The owner is asked only what their own switch says (never the Super Admin); staff only when signing in changes.
    const ownerBox = isOwner ? ownerConfirmFields(security?.owner_confirmation, translate as any) : [];
    const staffBox = !isOwner && loginChanged;
    const submit = (event: { preventDefault: () => void }) => {
        event.preventDefault();
        form.clearErrors();
        form.post('/commands/office-profile/contact', { preserveScroll: true, onSuccess: () => form.reset('current_password', 'confirm_code') });
    };
    return (
        <Card title={translate('office_profile', 'card_contact_title')} hint={translate('office_profile', 'card_contact_hint')} tour={isOwner ? 'owner-profile-edit' : undefined}>
            {isOwner && security?.owner_confirmation?.method !== 'none' && <OwnerConfirmationNote control={security?.owner_confirmation} />}
            <form className="action-form" onSubmit={submit}>
                <div className="office-card-grid">
                    <Row name="name" text={translate('office_profile', 'f_name')} errors={form.errors}>
                        <input {...bilingualBox('name', 'op-')} value={form.data.name} required maxLength={150} onChange={(e) => form.setData('name', e.target.value)} />
                    </Row>
                    <Row name="name_bn" text={translate('office_profile', 'f_name_bn')} errors={form.errors} hint={translate('office_profile', 'f_name_bn_hint')}>
                        <input {...bilingualBox('name_bn', 'op-')} value={form.data.name_bn} maxLength={150} onChange={(e) => form.setData('name_bn', e.target.value)} />
                    </Row>
                    <Row name="email" text={translate('office_profile', 'f_email')} errors={form.errors} hint={translate('office_profile', 'login_change_note')}>
                        <input type="email" value={form.data.email} required maxLength={254} onChange={(e) => form.setData('email', e.target.value)} />
                    </Row>
                    <Row name="phone" text={translate('office_profile', 'f_phone')} errors={form.errors}>
                        <input type="tel" inputMode="tel" value={form.data.phone} required={isOwner} maxLength={30} onChange={(e) => form.setData('phone', e.target.value)} />
                    </Row>
                    <Row name="phone2" text={translate('office_profile', 'f_phone2')} errors={form.errors} hint={translate('office_profile', 'f_phone2_hint')}>
                        <input type="tel" inputMode="tel" value={form.data.phone2} maxLength={30} onChange={(e) => form.setData('phone2', e.target.value)} />
                    </Row>
                    {ownerBox.map((field) => (
                        <Row key={field.name} name={field.name} text={field.label} errors={form.errors}>
                            <input type={field.type ?? 'text'} autoComplete={field.type === 'password' ? 'current-password' : 'off'} value={(form.data as any)[field.name]} required onChange={(e) => form.setData(field.name as any, e.target.value)} />
                        </Row>
                    ))}
                    {staffBox && (
                        <Row name="current_password" text={translate('office_profile', 'f_current_password')} errors={form.errors} hint={translate('office_profile', 'f_current_password_hint')}>
                            <input type="password" autoComplete="current-password" value={form.data.current_password} required onChange={(e) => form.setData('current_password', e.target.value)} />
                        </Row>
                    )}
                </div>
                <FormErrors errors={form.errors} besideFields={['name', 'name_bn', 'email', 'phone', 'phone2', 'current_password', 'confirm_code']} />
                <SaveButton busy={form.processing} text={translate('office_profile', 'save_contact')} />
            </form>
        </Card>
    );
}

/** Card 2: date of birth, blood group, gender, addresses and the emergency contact. */
function PersonalCard({ office }: { office: any }) {
    const translate = useT();
    const p = office.personal;
    const form = useForm({
        date_of_birth: p.date_of_birth || '', blood_group: p.blood_group || '', gender: p.gender || '',
        present_address: p.present_address || '', permanent_address: p.permanent_address || '', same_as_present: Boolean(p.same_as_present),
        emergency_contact_name: p.emergency_contact_name || '', emergency_contact_relation: p.emergency_contact_relation || '', emergency_contact_phone: p.emergency_contact_phone || '',
    });
    const same = form.data.same_as_present;
    return (
        <Card title={translate('office_profile', 'card_personal_title')} hint={translate('office_profile', 'card_personal_hint')}>
            <form className="action-form" onSubmit={(e) => { e.preventDefault(); form.clearErrors(); form.post('/commands/office-profile/personal', { preserveScroll: true }); }}>
                <div className="office-card-grid">
                    <Row name="date_of_birth" text={translate('office_profile', 'f_dob')} errors={form.errors}>
                        <DateInput value={form.data.date_of_birth} onChange={(e: any) => form.setData('date_of_birth', e.target.value)} />
                    </Row>
                    <Row name="blood_group" text={translate('office_profile', 'f_blood')} errors={form.errors}>
                        <select value={form.data.blood_group} onChange={(e) => form.setData('blood_group', e.target.value)}>
                            <option value="">{translate('portal', 'select_placeholder')}</option>
                            {(office.options.blood_groups as string[]).map((group) => <option key={group} value={group}>{group}</option>)}
                        </select>
                    </Row>
                    <Row name="gender" text={translate('office_profile', 'f_gender')} errors={form.errors}>
                        <select value={form.data.gender} onChange={(e) => form.setData('gender', e.target.value)}>
                            <option value="">{translate('portal', 'select_placeholder')}</option>
                            {(office.options.genders as string[]).map((gender) => <option key={gender} value={gender}>{translate('office_profile', `gender_${gender}`)}</option>)}
                        </select>
                    </Row>
                    <Row name="present_address" text={translate('office_profile', 'f_present')} errors={form.errors} wide>
                        <textarea rows={2} maxLength={300} value={form.data.present_address} onChange={(e) => form.setData('present_address', e.target.value)} />
                    </Row>
                    <label className="office-card-wide office-card-check">
                        <input type="checkbox" checked={same} onChange={(e) => form.setData('same_as_present', e.target.checked)} />
                        <span>{translate('office_profile', 'f_same_address')}</span>
                    </label>
                    <Row name="permanent_address" text={translate('office_profile', 'f_permanent')} errors={form.errors} wide>
                        <textarea rows={2} maxLength={300} disabled={same} value={same ? form.data.present_address : form.data.permanent_address} onChange={(e) => form.setData('permanent_address', e.target.value)} />
                    </Row>
                </div>
                <h3 className="office-card-sub">{translate('office_profile', 'emergency_title')}</h3>
                <p className="muted office-card-hint">{translate('office_profile', 'emergency_hint')}</p>
                <div className="office-card-grid">
                    <Row name="emergency_contact_name" text={translate('office_profile', 'f_emg_name')} errors={form.errors}>
                        <input value={form.data.emergency_contact_name} maxLength={150} onChange={(e) => form.setData('emergency_contact_name', e.target.value)} />
                    </Row>
                    <Row name="emergency_contact_relation" text={translate('office_profile', 'f_emg_relation')} errors={form.errors}>
                        <input value={form.data.emergency_contact_relation} maxLength={60} onChange={(e) => form.setData('emergency_contact_relation', e.target.value)} />
                    </Row>
                    <Row name="emergency_contact_phone" text={translate('office_profile', 'f_emg_phone')} errors={form.errors}>
                        <input type="tel" inputMode="tel" value={form.data.emergency_contact_phone} maxLength={30} onChange={(e) => form.setData('emergency_contact_phone', e.target.value)} />
                    </Row>
                </div>
                <FormErrors errors={form.errors} besideFields={Object.keys(form.data)} />
                <SaveButton busy={form.processing} text={translate('office_profile', 'save_personal')} />
            </form>
        </Card>
    );
}

/** Card 3: National ID number (shown masked after saving) and photo. Locked for staff once the office has verified it. */
function IdentityCard({ office }: { office: any }) {
    const translate = useT();
    const id = office.identity;
    const form = useForm<{ nid: string; nid_photo: File | null }>({ nid: '', nid_photo: null });
    const status = id.nid_masked
        ? <p>{translate('office_profile', 'nid_on_file', { masked: id.nid_masked } as any)}</p>
        : <p className="muted">{translate('office_profile', 'nid_none')}</p>;
    return (
        <Card title={translate('office_profile', 'card_identity_title')} hint={translate('office_profile', 'card_identity_hint')}>
            {status}
            {id.photo_sent && <p className="muted">{translate('office_profile', 'nid_photo_sent')}</p>}
            {id.locked ? (
                <p className="notice">{translate('office_profile', 'nid_locked_note')}</p>
            ) : (
                <form className="action-form" onSubmit={(e) => { e.preventDefault(); form.clearErrors(); form.post('/commands/office-profile/identity', { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() }); }}>
                    {id.nid_masked && !office.is_owner && <p className="muted">{translate('office_profile', 'nid_waiting')}</p>}
                    <div className="office-card-grid">
                        <Row name="nid" text={translate('office_profile', 'f_nid')} errors={form.errors} hint={translate('office_profile', 'f_nid_hint')}>
                            <input inputMode="numeric" autoComplete="off" maxLength={30} value={form.data.nid} onChange={(e) => form.setData('nid', e.target.value)} />
                        </Row>
                        <Row name="nid_photo" text={translate('office_profile', 'f_nid_photo')} errors={form.errors}>
                            <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => form.setData('nid_photo', e.target.files?.[0] ?? null)} />
                        </Row>
                    </div>
                    <FormErrors errors={form.errors} besideFields={['nid', 'nid_photo']} />
                    <SaveButton busy={form.processing} text={translate('office_profile', 'save_identity')} />
                </form>
            )}
        </Card>
    );
}

/** Staff see their work details and cannot change them; the owner, MD and Chairman set the title shown under their name. */
function WorkCard({ office, user }: { office: any; user: any }) {
    const translate = useT();
    const work = office.work;
    const form = useForm({ display_title: work.display_title || '' });
    const none = translate('office_profile', 'w_none');
    if (office.is_owner) {
        return (
            <Card title={translate('office_profile', 'card_title_title')} hint={translate('office_profile', 'card_title_hint')}>
                <form className="action-form" onSubmit={(e) => { e.preventDefault(); form.clearErrors(); form.post('/commands/office-profile/title', { preserveScroll: true }); }}>
                    <Row name="display_title" text={translate('office_profile', 'f_display_title')} errors={form.errors}>
                        <input value={form.data.display_title} maxLength={100} placeholder={translate('office_profile', 'f_display_title_example')} onChange={(e) => form.setData('display_title', e.target.value)} />
                    </Row>
                    <FormErrors errors={form.errors} besideFields={['display_title']} />
                    <SaveButton busy={form.processing} text={translate('office_profile', 'save_title')} />
                </form>
            </Card>
        );
    }
    return (
        <Card title={translate('office_profile', 'card_work_title')} hint={translate('office_profile', 'card_work_hint')}>
            <dl className="profile-grid office-card-facts">
                <Fact name={translate('office_profile', 'w_role')} value={roleTitle(user.role, translate)} />
                <Fact name={translate('office_profile', 'w_branches')} value={work.branches?.length ? work.branches.join(', ') : none} />
                <Fact name={translate('office_profile', 'w_code')} value={work.employee_code || none} />
                <Fact name={translate('office_profile', 'w_designation')} value={work.designation || none} />
                <Fact name={translate('office_profile', 'w_joined')} value={work.joined_on || none} />
            </dl>
        </Card>
    );
}

/** Password link, authenticator status, last sign-in and sign-out. */
function SafetyCard({ office, security, base }: { office: any; security: any; base: string }) {
    const translate = useT();
    const safety = office.safety;
    const unknown = translate('office_profile', 's_unknown');
    const totpOn = Boolean(security?.totp?.confirmed);
    return (
        <Card title={translate('office_profile', 'card_safety_title')}>
            <dl className="profile-grid office-card-facts">
                <Fact name={translate('office_profile', 's_password')} value={<Link className="text-link" href={`${base}/security`}>{translate('office_profile', 's_change_password')} <ArrowUpRight size={14} /></Link>} />
                <Fact name={translate('office_profile', 's_totp')} value={totpOn ? translate('office_profile', 's_totp_on') : <>{translate('office_profile', 's_totp_off')} · <Link className="text-link" href={`${base}/security`}>{translate('office_profile', 's_setup_totp')}</Link></>} />
                <Fact name={translate('office_profile', 's_last_sign_in')} value={safety.last_sign_in_at ?? unknown} />
                <Fact name={translate('office_profile', 's_device')} value={safety.last_sign_in_device ?? unknown} />
            </dl>
            <div className="ptl-foot ops-row is-end is-wrap is-loose">
                <button type="button" className="button ptl-logout" onClick={() => router.post('/logout')}><LogOut size={16} />{translate('portal', 'log_out_session')}</button>
            </div>
        </Card>
    );
}

/**
 * The office profile (owner and staff): photo, name and role on the left, the cards on the right.
 * On a phone it is one column, photo first.
 */
export default function OfficeProfile({ user, profile, security, base }: Props) {
    const translate = useT();
    const office = profile?.office;
    const { flash } = usePage<any>().props;
    const isOwner = ['super_admin', 'md', 'chairman'].includes(user.role);
    const shownTitle = office?.work?.display_title || roleTitle(user.role, translate);
    if (!office) {
        return null;
    }

    return (
        <section className="panel profile-panel office-profile" data-tour="profile-panel">
            <aside className="office-profile-who">
                <ProfilePhoto src={user.photo_url} initial={user.name?.[0] || ''} variant="office" upload />
                <h2>{user.name}</h2>
                {office.contact.name_bn && <p className="office-profile-name-bn">{office.contact.name_bn}</p>}
                <p className="office-profile-role">{shownTitle}</p>
                <Badge status={user.status} />
            </aside>
            <div className="office-profile-details">
                <p className="muted">{translate('office_profile', 'page_hint')}</p>
                {flash?.error && <p className="error" role="alert">{String(flash.error)}</p>}
                <ContactCard office={office} isOwner={isOwner} security={security} />
                <PersonalCard office={office} />
                <IdentityCard office={office} />
                <WorkCard office={office} user={user} />
                <SafetyCard office={office} security={security} base={base} />
            </div>
        </section>
    );
}
