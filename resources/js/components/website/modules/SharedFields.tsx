import React from 'react';
import { Info } from 'lucide-react';
import { useT } from '../../../shared/i18n/t';
import { Field, MdPhotoField } from '../formFields';
import type { WebsiteSectionProps } from '../WebsiteLayout';
import type { CustomizationData } from '../types';

/** Shows that a box is shared, with a way to the page that lists every shared fact. */
export function SharedFactNote({ goToModule }: Pick<WebsiteSectionProps, 'goToModule'>) {
    const translate = useT();
    return (
        <p className="ws-note">
            <Info size={16} aria-hidden="true" />
            <span>
                {translate('website_admin', 'fact_note')}{' '}
                <button type="button" className="ws-note-link" onClick={() => goToModule('company')}>{translate('website_admin', 'fact_note_link')}</button>
            </span>
        </p>
    );
}

/** A plain explanation at the top of a sub-module. */
export function WebsiteNote({ children }: { children: React.ReactNode }) {
    return (
        <p className="ws-note">
            <Info size={16} aria-hidden="true" />
            <span>{children}</span>
        </p>
    );
}

type BoxProps = Pick<WebsiteSectionProps, 'form' | 'defaults' | 'updateField'>;

const boxOf = ({ form, defaults, updateField }: BoxProps) => (key: keyof CustomizationData) => ({
    value: form.data.customization[key] as string | undefined,
    placeholder: defaults[key] as string | undefined,
    onChange: updateField,
});

export function MainLogoField({ form, defaults, updateField }: BoxProps) {
    const translate = useT();
    return <MdPhotoField fieldName="site_logo_url" label={translate('website_admin', 'field_site_logo')} hint={translate('website_admin', 'field_site_logo_hint')} clearLabel={translate('website_admin', 'use_built_in_picture')} value={form.data.customization.site_logo_url} placeholder={defaults.site_logo_url} onChange={val => updateField('site_logo_url', val)} />;
}

export function WhiteLogoField({ form, defaults, updateField }: BoxProps) {
    const translate = useT();
    return <MdPhotoField fieldName="footer_logo_url" darkPreview label={translate('website_admin', 'field_white_logo')} hint={translate('website_admin', 'field_white_logo_hint')} clearLabel={translate('website_admin', 'use_built_in_picture')} value={form.data.customization.footer_logo_url} placeholder={defaults.footer_logo_url} onChange={val => updateField('footer_logo_url', val)} />;
}

export function FaviconField({ form, defaults, updateField }: BoxProps) {
    const translate = useT();
    return <MdPhotoField fieldName="site_favicon_image" label={translate('website_admin', 'field_favicon')} hint={translate('website_admin', 'field_favicon_hint')} clearLabel={translate('website_admin', 'use_built_in_picture')} value={form.data.customization.site_favicon_image} placeholder={defaults.site_favicon_image} onChange={val => updateField('site_favicon_image', val)} />;
}

export function CompanyNameField(props: BoxProps) {
    const translate = useT();
    const box = boxOf(props);
    return <Field label={translate('portal', 'sce_f_contact_company_title')} fieldKey="contact_company_title" {...box('contact_company_title')} />;
}

export function PhoneAndEmailFields(props: BoxProps) {
    const translate = useT();
    const box = boxOf(props);
    return (
        <>
            <Field label={translate('portal', 'sce_f_hotline_phone')} fieldKey="contact_phone" {...box('contact_phone')} />
            <Field label={translate('portal', 'sce_f_footer_phone')} fieldKey="footer_phone" {...box('footer_phone')} />
            <Field label={translate('portal', 'sce_f_email_address')} fieldKey="contact_email" {...box('contact_email')} />
        </>
    );
}

export function AddressFields(props: BoxProps) {
    const translate = useT();
    const box = boxOf(props);
    return (
        <>
            <Field label={translate('portal', 'sce_f_head_office_address')} fieldKey="office_address" {...box('office_address')} span2 />
            <Field label={translate('website_admin', 'field_footer_location')} fieldKey="footer_location" {...box('footer_location')} span2 />
        </>
    );
}

export function SocialLinksFields(props: BoxProps) {
    const translate = useT();
    const box = boxOf(props);
    return (
        <>
            <p className="ed-desc is-sm hub-span-all">{translate('website_admin', 'social_hint')}</p>
            <Field label={translate('portal', 'sce_f_facebook_url')} fieldKey="social_facebook" {...box('social_facebook')} />
            <Field label={translate('portal', 'sce_f_youtube_url')} fieldKey="social_youtube" {...box('social_youtube')} />
            <Field label={translate('portal', 'sce_f_instagram_url')} fieldKey="social_instagram" {...box('social_instagram')} />
            <Field label={translate('portal', 'sce_f_whatsapp_link')} fieldKey="social_whatsapp" {...box('social_whatsapp')} />
            <Field label={translate('portal', 'sce_f_linkedin_url')} fieldKey="social_linkedin" {...box('social_linkedin')} />
            <Field label={translate('portal', 'sce_f_x_url')} fieldKey="social_twitter" value={props.form.data.customization.social_twitter} placeholder={props.defaults.social_twitter || 'https://x.com/...'} onChange={props.updateField} />
            <Field label={translate('portal', 'sce_f_tiktok_url')} fieldKey="social_tiktok" value={props.form.data.customization.social_tiktok} placeholder={props.defaults.social_tiktok || 'https://tiktok.com/@...'} onChange={props.updateField} />
            <Field label={translate('portal', 'sce_f_telegram_url')} fieldKey="social_telegram" value={props.form.data.customization.social_telegram} placeholder={props.defaults.social_telegram || 'https://t.me/...'} onChange={props.updateField} />
        </>
    );
}
