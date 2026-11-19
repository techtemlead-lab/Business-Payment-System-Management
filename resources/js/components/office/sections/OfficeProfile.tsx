import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, LockKeyhole, LogOut } from 'lucide-react';
import { ProfilePhoto } from '../../ProfilePhoto';
import { ActionForm, Badge, label } from '../../UI';
import { ownerConfirmFields, OwnerConfirmationNote } from '../OwnerConfirmation';
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

/**
 * The office profile (owner and staff, CR-19): photo, name and role on the left, the details on the right.
 * On a phone it is one column, photo first.
 */
export default function OfficeProfile({ user, profile, security, base }: Props) {
    const translate = useT();
    const canEdit = ['super_admin', 'md', 'chairman'].includes(user.role);
    const showIdentity = user.role !== 'super_admin';
    const rows: Array<[string, unknown]> = [
        [translate('portal', 'profile_name'), user.name],
        [translate('portal', 'profile_email'), user.email],
        [translate('portal', 'profile_phone'), profile?.phone],
        [translate('portal', 'profile_shop'), profile?.shop_name],
        [translate('portal', 'profile_area_code'), profile?.place_code],
        [translate('portal', 'profile_division'), user.division],
        [translate('portal', 'profile_district'), user.district],
        [translate('portal', 'profile_upazila'), profile?.upazila],
        [translate('portal', 'profile_union'), profile?.union],
        [translate('portal', 'profile_village'), profile?.village],
        ...(showIdentity ? [
            [translate('portal', 'profile_identity_type'), profile?.kyc?.identity_label],
            [translate('portal', 'profile_identity_status'), profile?.kyc?.identity_status_label],
            [translate('portal', 'profile_dob'), profile?.kyc?.date_of_birth_on_file ? translate('portal', 'profile_dob_on_file') : translate('portal', 'profile_dob_missing')],
        ] as Array<[string, unknown]> : []),
    ];

    return (
        <section className="panel profile-panel office-profile" data-tour="profile-panel">
            <aside className="office-profile-who">
                <ProfilePhoto src={user.photo_url} initial={user.name?.[0] || ''} variant="office" upload />
                <h2>{user.name}</h2>
                {profile?.name_bn && <p className="office-profile-name-bn">{profile.name_bn}</p>}
                <p className="office-profile-role">{roleTitle(user.role, translate)}</p>
                <Badge status={user.status} />
            </aside>
            <div className="office-profile-details">
                {canEdit ? (
                    <section className="ptl-flat-panel" data-tour="owner-profile-edit">
                        <h2>{translate('portal', 'owner_profile_edit_title')}</h2>
                        <p className="muted">{translate('portal', 'owner_contact_body')}</p>
                        <OwnerConfirmationNote control={security?.owner_confirmation} />
                        <ActionForm
                            action="/commands/owner/contact"
                            fields={[
                                { name: 'name', label: translate('portal', 'profile_name'), required: true, value: user.name },
                                { name: 'email', label: translate('portal', 'profile_email'), type: 'email', required: true, value: user.email },
                                { name: 'phone', label: translate('portal', 'profile_phone'), value: profile?.phone || '' },
                                ...ownerConfirmFields(security?.owner_confirmation, translate),
                            ]}
                            submit={translate('portal', 'owner_profile_edit_submit')}
                        />
                    </section>
                ) : (
                    <div className="notice">
                        <LockKeyhole size={19} />
                        <div>
                            <p>{translate('portal', 'profile_locked_notice')}</p>
                            <Link className="text-link ops-row is-inline is-snug ops-mt-2" href={`${base}/help`}>
                                {translate('portal', 'profile_locked_support_cta')} <ArrowUpRight size={14} />
                            </Link>
                        </div>
                    </div>
                )}
                <dl className="profile-grid">
                    {rows.filter(([, value]) => value != null && value !== '').map(([key, value]) => (
                        <div key={key}><dt>{key}</dt><dd>{String(value)}</dd></div>
                    ))}
                </dl>
                <div className="ptl-foot ops-row is-end is-wrap is-loose">
                    <button type="button" className="button ptl-logout" onClick={() => router.post('/logout')}><LogOut size={16} />{translate('portal', 'log_out_session')}</button>
                </div>
            </div>
        </section>
    );
}
