import OfficePage from '../OfficePage';
import { ActionForm, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { settingsBack } from './SettingsForm';

/** Owners' sign-in switches. The look is new; the two forms post the same fields to the same addresses as before. */
export default function LoginRules({ otp, passwordResetNotice }: { otp: Row; passwordResetNotice?: Row | null }) {
    const translate = useT();
    const noticeEnabled = (passwordResetNotice?.enabled ?? true) ? '1' : '0';

    return (
        <OfficePage title={translate('portal', 'login_rules')} back={settingsBack(translate)}>
            <div className="set-sheet">
                <section className="set-block" data-tour="partner-login-otp">
                    <h2>{translate('portal', 'partner_login_otp_title')}</h2>
                    <p className="set-line">{otp.preview_forced_off ? translate('portal', 'partner_login_otp_preview_off') : translate('office_settings', 'login_otp_warning')}</p>
                    <ActionForm
                        action="/commands/owner/partner-login-otp"
                        defaults={{ enabled: otp.stored_enabled ? '1' : '0', version: String(otp.version ?? 0) }}
                        fields={[
                            { name: 'enabled', label: translate('portal', 'partner_login_otp_enabled'), options: [{ value: '1', label: translate('portal', 'partner_login_otp_on') }, { value: '0', label: translate('portal', 'partner_login_otp_off') }], required: true, value: otp.stored_enabled ? '1' : '0' },
                            { name: 'current_password', label: translate('portal', 'owner_contact_password'), type: 'password', required: true },
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
                            { name: 'current_password', label: translate('portal', 'owner_contact_password'), type: 'password', required: true },
                        ]}
                        submit={translate('portal', 'password_reset_notice_save')}
                    />
                </section>
            </div>
        </OfficePage>
    );
}
