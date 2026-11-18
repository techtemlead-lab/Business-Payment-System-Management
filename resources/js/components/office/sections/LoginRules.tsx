import OfficePage from '../OfficePage';
import { ActionForm, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { settingsBack } from './SettingsForm';
import { OwnerConfirmationNote, ownerConfirmFields, type OwnerConfirmationControl } from '../OwnerConfirmation';

/** Owners' sign-in switches. The look is new; the two forms post the same fields to the same addresses as before. */
type LeadershipAccessControl = { md: string[]; chairman: string[]; version: number };

const LEADERSHIP_AREAS = ['accounts', 'treasury', 'owner_tools'] as const;

export default function LoginRules({ otp, passwordResetNotice, oneAccountPerIdentity, ownerConfirmation, teamPhones, leadershipAccess }: { otp: Row; passwordResetNotice?: Row | null; oneAccountPerIdentity?: Row | null; ownerConfirmation?: OwnerConfirmationControl | null; teamPhones?: Row | null; leadershipAccess?: LeadershipAccessControl | null }) {
    const translate = useT();
    const confirmFields = ownerConfirmFields(ownerConfirmation, translate);
    const smsDown = ownerConfirmation ? !ownerConfirmation.sms_works : true;
    const noticeEnabled = (passwordResetNotice?.enabled ?? true) ? '1' : '0';
    const oneAccountEnabled = (oneAccountPerIdentity?.enabled ?? false) ? '1' : '0';
    const teamPhonesEnabled = (teamPhones?.enabled ?? true) ? '1' : '0';
    const leadershipDefaults: Record<string, string> = { version: String(leadershipAccess?.version ?? 0) };
    const leadershipFields = (['md', 'chairman'] as const).flatMap((role) => LEADERSHIP_AREAS.map((area) => {
        const value = (leadershipAccess?.[role] ?? []).includes(area) ? '1' : '0';
        leadershipDefaults[`${role}_${area}`] = value;
        return {
            name: `${role}_${area}`,
            label: `${translate('owner_security', `leadership_${role}`)}: ${translate('owner_security', `leadership_area_${area}`)}`,
            options: [{ value: '1', label: translate('owner_security', 'leadership_yes') }, { value: '0', label: translate('owner_security', 'leadership_no') }],
            required: true,
            value,
        };
    }));

    return (
        <OfficePage title={translate('portal', 'login_rules')} back={settingsBack(translate)}>
            <div className="set-sheet">
                <section className="set-block" data-tour="owner-confirmation">
                    <h2>{translate('owner_security', 'confirm_title')}</h2>
                    <p className="set-line">{translate('owner_security', 'confirm_intro')}</p>
                    {smsDown && <p className="set-line">{translate('owner_security', ownerConfirmation?.has_phone === false ? 'sms_no_phone' : 'sms_not_working')}</p>}
                    <OwnerConfirmationNote control={ownerConfirmation} />
                    <ActionForm
                        action="/commands/owner/confirmation"
                        defaults={{ mode: ownerConfirmation?.mode ?? 'password', version: String(ownerConfirmation?.version ?? 0), accept_no_sms: '0' }}
                        fields={[
                            { name: 'mode', label: translate('owner_security', 'confirm_mode_label'), options: [{ value: 'off', label: translate('owner_security', 'mode_off') }, { value: 'password', label: translate('owner_security', 'mode_password') }, { value: 'sms', label: translate('owner_security', 'mode_sms') }], required: true, value: ownerConfirmation?.mode ?? 'password' },
                            ...(smsDown ? [{ name: 'accept_no_sms', label: translate('owner_security', 'accept_no_sms'), options: [{ value: '0', label: translate('owner_security', 'accept_no') }, { value: '1', label: translate('owner_security', 'accept_yes') }], required: true, value: '0' }] : []),
                            ...confirmFields,
                        ]}
                        submit={translate('owner_security', 'confirm_save')}
                    />
                </section>
                <section className="set-block" data-tour="partner-login-otp">
                    <h2>{translate('portal', 'partner_login_otp_title')}</h2>
                    <p className="set-line">{otp.preview_forced_off ? translate('portal', 'partner_login_otp_preview_off') : translate('office_settings', 'login_otp_warning')}</p>
                    <ActionForm
                        action="/commands/owner/partner-login-otp"
                        defaults={{ enabled: otp.stored_enabled ? '1' : '0', version: String(otp.version ?? 0) }}
                        fields={[
                            { name: 'enabled', label: translate('portal', 'partner_login_otp_enabled'), options: [{ value: '1', label: translate('portal', 'partner_login_otp_on') }, { value: '0', label: translate('portal', 'partner_login_otp_off') }], required: true, value: otp.stored_enabled ? '1' : '0' },
                            ...confirmFields,
                        ]}
                        submit={translate('portal', 'partner_login_otp_save')}
                    />
                </section>
                <section className="set-block" data-tour="password-reset-notice">
                    <h2>{translate('portal', 'password_reset_notice_title')}</h2>
                    <ActionForm
                        action="/commands/owner/password-reset-notice"
                        defaults={{ enabled: noticeEnabled, version: String(passwordResetNotice?.version ?? 0) }}
                        fields={[
                            { name: 'enabled', label: translate('portal', 'password_reset_notice_enabled'), options: [{ value: '1', label: translate('portal', 'password_reset_notice_on') }, { value: '0', label: translate('portal', 'password_reset_notice_off') }], required: true, value: noticeEnabled },
                            ...confirmFields,
                        ]}
                        submit={translate('portal', 'password_reset_notice_save')}
                    />
                </section>
                <section className="set-block" data-tour="one-account-per-identity">
                    <h2>{translate('portal', 'one_account_per_identity_title')}</h2>
                    <p className="set-line">{translate('portal', 'one_account_per_identity_intro')}</p>
                    <ActionForm
                        action="/commands/owner/one-account-per-identity"
                        defaults={{ enabled: oneAccountEnabled, version: String(oneAccountPerIdentity?.version ?? 0) }}
                        fields={[
                            { name: 'enabled', label: translate('portal', 'one_account_per_identity_enabled'), options: [{ value: '1', label: translate('portal', 'one_account_per_identity_on') }, { value: '0', label: translate('portal', 'one_account_per_identity_off') }], required: true, value: oneAccountEnabled },
                            ...confirmFields,
                        ]}
                        submit={translate('portal', 'one_account_per_identity_save')}
                    />
                </section>
                <section className="set-block" data-tour="team-phones">
                    <h2>{translate('owner_security', 'team_phones_title')}</h2>
                    <p className="set-line">{translate('owner_security', 'team_phones_intro')}</p>
                    <ActionForm
                        action="/commands/owner/team-phones"
                        defaults={{ enabled: teamPhonesEnabled, version: String(teamPhones?.version ?? 0) }}
                        fields={[
                            { name: 'enabled', label: translate('owner_security', 'team_phones_label'), options: [{ value: '1', label: translate('owner_security', 'team_phones_on') }, { value: '0', label: translate('owner_security', 'team_phones_off') }], required: true, value: teamPhonesEnabled },
                            ...confirmFields,
                        ]}
                        submit={translate('owner_security', 'team_phones_save')}
                    />
                </section>
                {leadershipAccess && (
                    <section className="set-block" data-tour="leadership-access">
                        <h2>{translate('owner_security', 'leadership_title')}</h2>
                        <p className="set-line">{translate('owner_security', 'leadership_intro')}</p>
                        <ActionForm
                            action="/commands/owner/leadership-access"
                            defaults={leadershipDefaults}
                            fields={[...leadershipFields, ...confirmFields]}
                            submit={translate('owner_security', 'leadership_save')}
                        />
                    </section>
                )}
            </div>
        </OfficePage>
    );
}
