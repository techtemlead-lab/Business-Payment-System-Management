import React from 'react';
import { Field } from '../formFields';
import { WebsiteGroup, type WebsiteSectionProps } from '../WebsiteLayout';
import { useT } from '../../../shared/i18n/t';
import { CompanyNameField, SharedFactNote, WebsiteNote } from './SharedFields';

/** The dealer and sub-dealer side menu shares one help box. */
function PartnerHelpBox({ form, defaults, updateField, shared }: Pick<WebsiteSectionProps, 'form' | 'defaults' | 'updateField'> & { shared: 'dealer' | 'subdealer' }) {
    const translate = useT();
    return (
        <WebsiteGroup title={translate('website_admin', 'group_help_box')} defaultOpen>
            <p className="ed-desc is-sm">{translate('website_admin', shared === 'dealer' ? 'help_box_shared_with_subdealer' : 'help_box_shared_with_dealer')}</p>
            <div className="form-grid ed-grid">
                <Field label={translate('portal', 'sce_fld_partner_help_title')} fieldKey="partner_help_title" value={form.data.customization.partner_help_title} placeholder={defaults.partner_help_title} onChange={updateField} />
                <Field label={translate('portal', 'sce_fld_partner_help_body')} fieldKey="partner_help_body" value={form.data.customization.partner_help_body} placeholder={defaults.partner_help_body} onChange={updateField} multiline rows={2} />
            </div>
        </WebsiteGroup>
    );
}

export function AdminPortalModule(props: WebsiteSectionProps) {
    const { form, defaults, updateField, goToModule } = props;
    const translate = useT();
    return (
        <>
            <WebsiteNote>{translate('website_admin', 'portal_admin_note')}</WebsiteNote>
            <WebsiteGroup title={translate('website_admin', 'group_portal_brand')} defaultOpen>
                <SharedFactNote goToModule={goToModule} />
                <div className="form-grid ed-grid">
                    <CompanyNameField {...props} />
                </div>
            </WebsiteGroup>
            <WebsiteGroup title={translate('portal', 'sce_invoice_letterhead_heading')} defaultOpen>
                <p className="ed-desc is-sm">{translate('website_admin', 'invoice_hint')}</p>
                <div className="form-grid ed-grid ed-mb-lg">
                    <Field label={translate('portal', 'sce_fld_invoice_manufacturer')} fieldKey="invoice_manufacturer" value={form.data.customization.invoice_manufacturer} placeholder={defaults.invoice_manufacturer} onChange={updateField} />
                    <Field label={translate('portal', 'sce_fld_invoice_marketed_by')} fieldKey="invoice_marketed_by" value={form.data.customization.invoice_marketed_by} placeholder={defaults.invoice_marketed_by} onChange={updateField} />
                    <Field label={translate('portal', 'sce_fld_invoice_marketed_phones')} fieldKey="invoice_marketed_phones" value={form.data.customization.invoice_marketed_phones} placeholder={defaults.invoice_marketed_phones} onChange={updateField} span2 />
                </div>
            </WebsiteGroup>
        </>
    );
}

export function StaffPortalModule(props: WebsiteSectionProps) {
    const { goToModule } = props;
    const translate = useT();
    return (
        <>
            <WebsiteNote>{translate('website_admin', 'portal_staff_note')}</WebsiteNote>
            <WebsiteGroup title={translate('website_admin', 'group_portal_brand')} defaultOpen>
                <SharedFactNote goToModule={goToModule} />
                <div className="form-grid ed-grid">
                    <CompanyNameField {...props} />
                </div>
            </WebsiteGroup>
        </>
    );
}

export function DealerPortalModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
            <WebsiteGroup title={translate('website_admin', 'group_login_words')} defaultOpen>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_partner_login_heading')} fieldKey="auth_login_title" value={form.data.customization.auth_login_title} placeholder={defaults.auth_login_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_partner_login_subtitle')} fieldKey="auth_login_subtitle" value={form.data.customization.auth_login_subtitle} placeholder={defaults.auth_login_subtitle} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_dealer_inquiry_text')} fieldKey="auth_dealer_help_text" value={form.data.customization.auth_dealer_help_text} placeholder={defaults.auth_dealer_help_text} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_dealer_inquiry_link')} fieldKey="auth_dealer_contact_text" value={form.data.customization.auth_dealer_contact_text} placeholder={defaults.auth_dealer_contact_text} onChange={updateField} />
                </div>
            </WebsiteGroup>
            <PartnerHelpBox form={form} defaults={defaults} updateField={updateField} shared="dealer" />
        </>
    );
}

export function SubdealerPortalModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
            <WebsiteNote>{translate('website_admin', 'portal_subdealer_note')}</WebsiteNote>
            <WebsiteGroup title={translate('website_admin', 'group_login_words')} defaultOpen>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_partner_login_subtitle')} fieldKey="auth_login_subtitle" value={form.data.customization.auth_login_subtitle} placeholder={defaults.auth_login_subtitle} onChange={updateField} />
                </div>
            </WebsiteGroup>
            <PartnerHelpBox form={form} defaults={defaults} updateField={updateField} shared="subdealer" />
        </>
    );
}
