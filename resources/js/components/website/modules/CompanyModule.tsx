import React from 'react';
import { useT } from '../../../shared/i18n/t';
import { Field, MdPhotoField } from '../formFields';
import { WebsiteGroup, type WebsiteSectionProps } from '../WebsiteLayout';
import type { CustomizationData } from '../types';
import { AddressFields, CompanyNameField, FaviconField, MainLogoField, PhoneAndEmailFields, WebsiteNote, WhiteLogoField } from './SharedFields';

/**
 * The one place for facts the website shows in several places. Sentences on other
 * pages use words like {phone}, which fill in from these boxes.
 */
export default function CompanyModule(props: WebsiteSectionProps) {
    const { form, defaults, updateField, trustEditor } = props;
    const translate = useT();
    const trustLabel = (key: string): string => trustEditor?.labels[key] ?? key;
    const trustValue = (key: string): string | undefined => (form.data.customization as Record<string, unknown>)[key] as string | undefined;

    return (
        <>
            <WebsiteNote>{translate('website_admin', 'words_note')}</WebsiteNote>

            <WebsiteGroup title={translate('website_admin', 'group_company_name')} defaultOpen>
                <div className="form-grid ed-grid">
                    <CompanyNameField {...props} />
                    <PhoneAndEmailFields {...props} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_logos')} defaultOpen>
                <div className="form-grid ed-grid">
                    <MainLogoField {...props} />
                    <WhiteLogoField {...props} />
                    <FaviconField {...props} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_company_address')} defaultOpen>
                <div className="form-grid ed-grid">
                    <AddressFields {...props} />
                    <Field label={translate('portal', 'sce_f_map_lat')} fieldKey="map_latitude" value={form.data.customization.map_latitude} placeholder={defaults.map_latitude || 'e.g. 25.7701'} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_map_lng')} fieldKey="map_longitude" value={form.data.customization.map_longitude} placeholder={defaults.map_longitude || 'e.g. 88.8240'} onChange={updateField} />
                </div>
            </WebsiteGroup>

            {trustEditor && (
                <WebsiteGroup title={translate('website_admin', 'group_company_legal')} defaultOpen>
                    <p className="ed-desc is-sm">{trustLabel('admin_identity_hint')}</p>
                    <div className="form-grid ed-grid">
                        <Field label={trustLabel('identity_trade_licence')} fieldKey={'trust_trade_licence' as keyof CustomizationData} value={trustValue('trust_trade_licence')} placeholder="2026-00563" onChange={updateField} />
                        <Field label={trustLabel('identity_tin')} fieldKey={'trust_tin' as keyof CustomizationData} value={trustValue('trust_tin')} onChange={updateField} />
                        <Field label={trustLabel('identity_digital_business_id')} fieldKey={'trust_digital_business_id' as keyof CustomizationData} value={trustValue('trust_digital_business_id')} onChange={updateField} />
                        <Field label={trustLabel('identity_joint_stock_registration')} fieldKey={'trust_joint_stock_registration' as keyof CustomizationData} value={trustValue('trust_joint_stock_registration')} onChange={updateField} />
                    </div>
                </WebsiteGroup>
            )}

            <WebsiteGroup title={translate('website_admin', 'group_share_search')}>
                <p className="ed-desc is-sm">{translate('website_admin', 'share_search_hint')}</p>
                <div className="form-grid ed-grid">
                    <Field label={translate('website_admin', 'field_meta_title')} fieldKey="meta_title" value={form.data.customization.meta_title} placeholder={defaults.meta_title} onChange={updateField} />
                    <Field label={translate('website_admin', 'field_meta_description')} fieldKey="meta_description" value={form.data.customization.meta_description} placeholder={defaults.meta_description} onChange={updateField} multiline rows={2} />
                    <MdPhotoField fieldName="og_image" label={translate('website_admin', 'field_og_image')} hint={translate('website_admin', 'field_og_image_hint')} clearLabel={translate('website_admin', 'use_built_in_picture')} value={form.data.customization.og_image} onChange={val => updateField('og_image', val)} />
                </div>
            </WebsiteGroup>
        </>
    );
}
