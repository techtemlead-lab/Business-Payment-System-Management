import React from 'react';
import { Field } from '../formFields';
import { WebsiteGroup, WebsiteSection, type WebsiteSectionProps } from '../WebsiteLayout';
import { useT } from '../../../shared/i18n/t';

export default function FooterContactSection({ form, defaults, bnCopy, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <WebsiteSection id="footer" title={translate('website_admin', 'section_footer')} hint={translate('website_admin', 'section_footer_hint')}>
        <WebsiteGroup title={translate('portal', 'sce_contact_page_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_contact_page_heading')} fieldKey="contact_title" value={form.data.customization.contact_title} placeholder={defaults.contact_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_contact_company_title')} fieldKey="contact_company_title" value={form.data.customization.contact_company_title} placeholder={defaults.contact_company_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_contact_intro')} fieldKey="contact_company_desc" value={form.data.customization.contact_company_desc} placeholder={defaults.contact_company_desc} onChange={updateField} span2 />

                                <Field label={translate('portal', 'sce_f_hotline_title')} fieldKey="contact_phone_title" value={form.data.customization.contact_phone_title} placeholder={defaults.contact_phone_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_hotline_phone')} fieldKey="contact_phone" value={form.data.customization.contact_phone} placeholder={defaults.contact_phone} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_hotline_hours')} fieldKey="contact_phone_hours" value={form.data.customization.contact_phone_hours} placeholder={defaults.contact_phone_hours} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_email_title')} fieldKey="contact_email_title" value={form.data.customization.contact_email_title} placeholder={defaults.contact_email_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_email_address')} fieldKey="contact_email" value={form.data.customization.contact_email} placeholder={defaults.contact_email} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_social_title')} fieldKey="contact_social_title" value={form.data.customization.contact_social_title} placeholder={defaults.contact_social_title} onChange={updateField} />
                                
                                <Field label={translate('portal', 'sce_f_topic1_title')} fieldKey="contact_topic1_title" value={form.data.customization.contact_topic1_title} placeholder={defaults.contact_topic1_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_topic1_desc')} fieldKey="contact_topic1_desc" value={form.data.customization.contact_topic1_desc} placeholder={defaults.contact_topic1_desc} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_topic2_title')} fieldKey="contact_topic2_title" value={form.data.customization.contact_topic2_title} placeholder={defaults.contact_topic2_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_topic2_desc')} fieldKey="contact_topic2_desc" value={form.data.customization.contact_topic2_desc} placeholder={defaults.contact_topic2_desc} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_head_office_title')} fieldKey="contact_topic3_title" value={form.data.customization.contact_topic3_title} placeholder={defaults.contact_topic3_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_head_office_address')} fieldKey="office_address" value={form.data.customization.office_address} placeholder={defaults.office_address} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_map_lat')} fieldKey="map_latitude" value={form.data.customization.map_latitude} placeholder={defaults.map_latitude || 'e.g. 25.7701'} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_map_lng')} fieldKey="map_longitude" value={form.data.customization.map_longitude} placeholder={defaults.map_longitude || 'e.g. 88.8240'} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_contact_form_header')} fieldKey="contact_form_title" value={form.data.customization.contact_form_title} placeholder={defaults.contact_form_title} onChange={updateField} />
                                
                                <Field label={translate('portal', 'sce_f_map_title')} fieldKey="map_title" value={form.data.customization.map_title} placeholder={defaults.map_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_map_subtitle')} fieldKey="map_subtitle" value={form.data.customization.map_subtitle} placeholder={defaults.map_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_map_place_name')} fieldKey="map_facility" value={form.data.customization.map_facility} placeholder={defaults.map_facility} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_map_directions_btn')} fieldKey="map_directions_text" value={form.data.customization.map_directions_text} placeholder={defaults.map_directions_text} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_footer_column_1_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_column_1_heading')} fieldKey="footer_support_heading" value={form.data.customization.footer_support_heading} placeholder={defaults.footer_support_heading} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_support_box1_title')} fieldKey="footer_support_title" value={form.data.customization.footer_support_title} placeholder={defaults.footer_support_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_support_box1_subtitle')} fieldKey="footer_support_subtitle" value={form.data.customization.footer_support_subtitle} placeholder={defaults.footer_support_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_support_box1_link')} fieldKey="footer_support_link" value={form.data.customization.footer_support_link} placeholder={defaults.footer_support_link} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_support_box1_icon')} fieldKey="footer_support_icon" value={form.data.customization.footer_support_icon} placeholder={defaults.footer_support_icon} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_dir_box2_title')} fieldKey="footer_directory_title" value={form.data.customization.footer_directory_title} placeholder={defaults.footer_directory_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_box2_subtitle')} fieldKey="footer_directory_subtitle" value={form.data.customization.footer_directory_subtitle} placeholder={defaults.footer_directory_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_box2_link')} fieldKey="footer_directory_link" value={form.data.customization.footer_directory_link} placeholder={defaults.footer_directory_link} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_box2_icon')} fieldKey="footer_directory_icon" value={form.data.customization.footer_directory_icon} placeholder={defaults.footer_directory_icon} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_footer_phone')} fieldKey="footer_phone" value={form.data.customization.footer_phone} placeholder={defaults.footer_phone} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_email')} fieldKey="footer_email" value={form.data.customization.footer_email} placeholder={defaults.footer_email} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_address')} fieldKey="footer_address" value={form.data.customization.footer_address} placeholder={defaults.footer_address} onChange={updateField} span2 />
                                <Field label={translate('website_admin', 'field_footer_location')} fieldKey="footer_location" value={form.data.customization.footer_location} placeholder={defaults.footer_location} onChange={updateField} span2 />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_footer_column_2_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_column_2_heading')} fieldKey="footer_about_heading" value={form.data.customization.footer_about_heading} placeholder={defaults.footer_about_heading} onChange={updateField} span2 />

                                <Field label={translate('portal', 'sce_f_footer_link1_text')} fieldKey="footer_link_about" value={form.data.customization.footer_link_about} placeholder={defaults.footer_link_about} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_link1_url')} fieldKey="footer_link_about_url" value={form.data.customization.footer_link_about_url} placeholder={defaults.footer_link_about_url} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_footer_link5_text')} fieldKey="footer_link_contact" value={form.data.customization.footer_link_contact} placeholder={defaults.footer_link_contact} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_link5_url')} fieldKey="footer_link_contact_url" value={form.data.customization.footer_link_contact_url} placeholder={defaults.footer_link_contact_url} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_footer_link6_text')} fieldKey="footer_link_dealer_apply" value={form.data.customization.footer_link_dealer_apply} placeholder={defaults.footer_link_dealer_apply} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_link6_url')} fieldKey="footer_link_dealer_apply_url" value={form.data.customization.footer_link_dealer_apply_url} placeholder={defaults.footer_link_dealer_apply_url} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_footer_column_3_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_column_3_heading')} fieldKey="footer_stay_connected_heading" value={form.data.customization.footer_stay_connected_heading} placeholder={defaults.footer_stay_connected_heading} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_logo_url')} fieldKey="footer_logo_url" value={form.data.customization.footer_logo_url} placeholder={defaults.footer_logo_url} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_brand_title')} fieldKey="footer_about_title" value={form.data.customization.footer_about_title} placeholder={defaults.footer_about_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_footer_brand_desc')} fieldKey="footer_about_desc" value={form.data.customization.footer_about_desc} placeholder={defaults.footer_about_desc} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_social_media_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_facebook_url')} fieldKey="social_facebook" value={form.data.customization.social_facebook} placeholder={defaults.social_facebook} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_youtube_url')} fieldKey="social_youtube" value={form.data.customization.social_youtube} placeholder={defaults.social_youtube} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_linkedin_url')} fieldKey="social_linkedin" value={form.data.customization.social_linkedin} placeholder={defaults.social_linkedin} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_instagram_url')} fieldKey="social_instagram" value={form.data.customization.social_instagram} placeholder={defaults.social_instagram} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_whatsapp_link')} fieldKey="social_whatsapp" value={form.data.customization.social_whatsapp} placeholder={defaults.social_whatsapp} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_whatsapp_button_link')} fieldKey="whatsapp_link" value={form.data.customization.whatsapp_link} placeholder={defaults.whatsapp_link} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_x_url')} fieldKey="social_twitter" value={form.data.customization.social_twitter} placeholder={defaults.social_twitter || 'https://x.com/...'} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_tiktok_url')} fieldKey="social_tiktok" value={form.data.customization.social_tiktok} placeholder={defaults.social_tiktok || 'https://tiktok.com/@...'} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_telegram_url')} fieldKey="social_telegram" value={form.data.customization.social_telegram} placeholder={defaults.social_telegram || 'https://t.me/...'} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('website_admin', 'group_apps_copyright')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_play_store_link')} fieldKey="app_google_play_link" value={form.data.customization.app_google_play_link} placeholder={defaults.app_google_play_link} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_app_store_link')} fieldKey="app_apple_store_link" value={form.data.customization.app_apple_store_link} placeholder={defaults.app_apple_store_link} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_copyright')} fieldKey="copyright_text" value={form.data.customization.copyright_text} placeholder={defaults.copyright_text} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_fld_copyright_text_bn')} fieldKey="copyright_text_bn" value={form.data.customization.copyright_text_bn} placeholder={bnCopy.copyright} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_fld_notice_empty')} fieldKey="notice_empty" value={form.data.customization.notice_empty} placeholder={defaults.notice_empty} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_fld_notice_empty_bn')} fieldKey="notice_empty_bn" value={form.data.customization.notice_empty_bn} placeholder={bnCopy.notice_empty} onChange={updateField} span2 />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_support_partner_access_heading')}>
                            <div className="form-grid ed-grid">
                                <Field label={translate('portal', 'sce_f_chat_button_label')} fieldKey="floating_chat_label" value={form.data.customization.floating_chat_label} placeholder={defaults.floating_chat_label} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_partner_login_heading')} fieldKey="auth_login_title" value={form.data.customization.auth_login_title} placeholder={defaults.auth_login_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_partner_login_subtitle')} fieldKey="auth_login_subtitle" value={form.data.customization.auth_login_subtitle} placeholder={defaults.auth_login_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dealer_inquiry_text')} fieldKey="auth_dealer_help_text" value={form.data.customization.auth_dealer_help_text} placeholder={defaults.auth_dealer_help_text} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dealer_inquiry_link')} fieldKey="auth_dealer_contact_text" value={form.data.customization.auth_dealer_contact_text} placeholder={defaults.auth_dealer_contact_text} onChange={updateField} />
                                <Field label={translate('portal', 'sce_fld_partner_help_title')} fieldKey="partner_help_title" value={form.data.customization.partner_help_title} placeholder={defaults.partner_help_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_fld_partner_help_body')} fieldKey="partner_help_body" value={form.data.customization.partner_help_body} placeholder={defaults.partner_help_body} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_invoice_letterhead_heading')}>
                            <p className="ed-desc is-sm">{translate('portal', 'sce_invoice_letterhead_hint')}</p>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_fld_invoice_manufacturer')} fieldKey="invoice_manufacturer" value={form.data.customization.invoice_manufacturer} placeholder={defaults.invoice_manufacturer} onChange={updateField} />
                                <Field label={translate('portal', 'sce_fld_invoice_marketed_by')} fieldKey="invoice_marketed_by" value={form.data.customization.invoice_marketed_by} placeholder={defaults.invoice_marketed_by} onChange={updateField} />
                                <Field label={translate('portal', 'sce_fld_invoice_marketed_phones')} fieldKey="invoice_marketed_phones" value={form.data.customization.invoice_marketed_phones} placeholder={defaults.invoice_marketed_phones} onChange={updateField} span2 />
                            </div>
        </WebsiteGroup>
        </WebsiteSection>
    );
}
