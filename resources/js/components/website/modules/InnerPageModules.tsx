import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Field, MdPhotoField } from '../formFields';
import { WebsiteGroup, WebsiteToolLink, type WebsiteSectionProps } from '../WebsiteLayout';
import type { CustomizationData } from '../types';
import { useT } from '../../../shared/i18n/t';
import { AddressFields, PhoneAndEmailFields, SharedFactNote, SocialLinksFields } from './SharedFields';

export function AboutModule({ form, defaults, bnCopy, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_about_page_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_about_hero_title')} fieldKey="about_hero_title" value={form.data.customization.about_hero_title} placeholder={defaults.about_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_about_hero_lead')} fieldKey="about_hero_lead" value={form.data.customization.about_hero_lead} placeholder={defaults.about_hero_lead} onChange={updateField} multiline rows={3} span2 />
                                <Field label={translate('portal', 'sce_f_history_title')} fieldKey="about_history_title" value={form.data.customization.about_history_title} placeholder={defaults.about_history_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_history_text')} fieldKey="about_history" value={form.data.customization.about_history} placeholder={defaults.about_history} onChange={updateField} multiline rows={4} span2 />
                                <Field label={translate('portal', 'sce_f_mission_title')} fieldKey="about_mission_title" value={form.data.customization.about_mission_title} placeholder={defaults.about_mission_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_mission_text')} fieldKey="about_mission" value={form.data.customization.about_mission} placeholder={defaults.about_mission} onChange={updateField} multiline rows={3} />
                                <Field label={translate('portal', 'sce_f_vision_title')} fieldKey="about_vision_title" value={form.data.customization.about_vision_title} placeholder={defaults.about_vision_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_vision_text')} fieldKey="about_vision" value={form.data.customization.about_vision} placeholder={defaults.about_vision} onChange={updateField} multiline rows={3} />

                                <Field label={translate('portal', 'sce_f_objectives_title')} fieldKey="about_objectives_title" value={form.data.customization.about_objectives_title} placeholder={defaults.about_objectives_title} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_objective1_title')} fieldKey="about_obj1_title" value={form.data.customization.about_obj1_title} placeholder={defaults.about_obj1_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_objective1_desc')} fieldKey="about_obj1_desc" value={form.data.customization.about_obj1_desc} placeholder={defaults.about_obj1_desc} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_objective2_title')} fieldKey="about_obj2_title" value={form.data.customization.about_obj2_title} placeholder={defaults.about_obj2_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_objective2_desc')} fieldKey="about_obj2_desc" value={form.data.customization.about_obj2_desc} placeholder={defaults.about_obj2_desc} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_objective3_title')} fieldKey="about_obj3_title" value={form.data.customization.about_obj3_title} placeholder={defaults.about_obj3_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_objective3_desc')} fieldKey="about_obj3_desc" value={form.data.customization.about_obj3_desc} placeholder={defaults.about_obj3_desc} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_objective4_title')} fieldKey="about_obj4_title" value={form.data.customization.about_obj4_title} placeholder={defaults.about_obj4_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_objective4_desc')} fieldKey="about_obj4_desc" value={form.data.customization.about_obj4_desc} placeholder={defaults.about_obj4_desc} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_network_title')} fieldKey="about_network_title" value={form.data.customization.about_network_title} placeholder={defaults.about_network_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_network_text')} fieldKey="about_network" value={form.data.customization.about_network} placeholder={defaults.about_network} onChange={updateField} multiline rows={4} span2 />

                                <Field label={translate('portal', 'sce_f_button_1')} fieldKey="about_btn_products" value={form.data.customization.about_btn_products} placeholder={defaults.about_btn_products} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_button_2')} fieldKey="about_btn_dealers" value={form.data.customization.about_btn_dealers} placeholder={defaults.about_btn_dealers} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_button_3')} fieldKey="about_btn_contact" value={form.data.customization.about_btn_contact} placeholder={defaults.about_btn_contact} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_md_message_heading')}>
                            <div className="ed-card ed-mb-lg" id="md-message-editor">
                                <div className="ed-head">
                                    <div>
                                        <p className="ed-desc is-sm">{translate('portal', 'sce_md_message_hint')}</p>
                                    </div>
                                    <button
                                        type="button"
                                        className={`button small ${form.data.customization.md_message_enabled !== '0' ? 'dark' : ''}`}
                                        aria-pressed={form.data.customization.md_message_enabled !== '0'}
                                        onClick={() => updateField('md_message_enabled', form.data.customization.md_message_enabled !== '0' ? '0' : '1')}
                                    >
                                        {form.data.customization.md_message_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                                    </button>
                                </div>
                                <div className="form-grid ed-grid is-md">
                                    <MdPhotoField value={form.data.customization.md_message_image} placeholder={defaults.md_message_image} onChange={val => updateField('md_message_image', val)} />
                                    <Field label={translate('portal', 'sce_fld_md_message_heading')} fieldKey="md_message_heading" value={form.data.customization.md_message_heading} placeholder={defaults.md_message_heading} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_heading_bn')} fieldKey="md_message_heading_bn" value={form.data.customization.md_message_heading_bn} placeholder={bnCopy.md_message_heading} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_name')} fieldKey="md_message_name" value={form.data.customization.md_message_name} placeholder={defaults.md_message_name} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_name_bn')} fieldKey="md_message_name_bn" value={form.data.customization.md_message_name_bn} placeholder={bnCopy.md_message_name} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_title')} fieldKey="md_message_title" value={form.data.customization.md_message_title} placeholder={defaults.md_message_title} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_title_bn')} fieldKey="md_message_title_bn" value={form.data.customization.md_message_title_bn} placeholder={bnCopy.md_message_title} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_company')} fieldKey="md_message_company" value={form.data.customization.md_message_company} placeholder={defaults.md_message_company} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_company_bn')} fieldKey="md_message_company_bn" value={form.data.customization.md_message_company_bn} placeholder={bnCopy.md_message_company} onChange={updateField} />
                                    <Field label={translate('portal', 'sce_fld_md_message_body')} fieldKey="md_message_body" value={form.data.customization.md_message_body} placeholder={defaults.md_message_body} onChange={updateField} multiline rows={10} span2 />
                                    <Field label={translate('portal', 'sce_fld_md_message_body_bn')} fieldKey="md_message_body_bn" value={form.data.customization.md_message_body_bn} placeholder={bnCopy.md_message_body} onChange={updateField} multiline rows={10} span2 />
                                </div>
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function ContactModule(props: WebsiteSectionProps) {
    const { form, defaults, updateField, goToModule } = props;
    const translate = useT();
    return (
        <>
            <WebsiteGroup title={translate('website_admin', 'group_shared_facts')} defaultOpen>
                <SharedFactNote goToModule={goToModule} />
                <div className="form-grid ed-grid">
                    <PhoneAndEmailFields {...props} />
                    <AddressFields {...props} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_contact_words')} defaultOpen>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_contact_page_heading')} fieldKey="contact_title" value={form.data.customization.contact_title} placeholder={defaults.contact_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_contact_intro')} fieldKey="contact_company_desc" value={form.data.customization.contact_company_desc} placeholder={defaults.contact_company_desc} onChange={updateField} multiline rows={3} span2 />
                    <Field label={translate('portal', 'sce_f_hotline_title')} fieldKey="contact_phone_title" value={form.data.customization.contact_phone_title} placeholder={defaults.contact_phone_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_hotline_hours')} fieldKey="contact_phone_hours" value={form.data.customization.contact_phone_hours} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_email_title')} fieldKey="contact_email_title" value={form.data.customization.contact_email_title} placeholder={defaults.contact_email_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_topic1_title')} fieldKey="contact_topic1_title" value={form.data.customization.contact_topic1_title} placeholder={defaults.contact_topic1_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_topic1_desc')} fieldKey="contact_topic1_desc" value={form.data.customization.contact_topic1_desc} placeholder={defaults.contact_topic1_desc} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_topic2_title')} fieldKey="contact_topic2_title" value={form.data.customization.contact_topic2_title} placeholder={defaults.contact_topic2_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_topic2_desc')} fieldKey="contact_topic2_desc" value={form.data.customization.contact_topic2_desc} placeholder={defaults.contact_topic2_desc} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_head_office_title')} fieldKey="contact_topic3_title" value={form.data.customization.contact_topic3_title} placeholder={defaults.contact_topic3_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_contact_form_header')} fieldKey="contact_form_title" value={form.data.customization.contact_form_title} placeholder={defaults.contact_form_title} onChange={updateField} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_contact_map')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_map_title')} fieldKey="map_title" value={form.data.customization.map_title} placeholder={defaults.map_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_map_subtitle')} fieldKey="map_subtitle" value={form.data.customization.map_subtitle} placeholder={defaults.map_subtitle} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_map_place_name')} fieldKey="map_facility" value={form.data.customization.map_facility} placeholder={defaults.map_facility} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_map_directions_btn')} fieldKey="map_directions_text" value={form.data.customization.map_directions_text} placeholder={defaults.map_directions_text} onChange={updateField} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_social')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_social_title')} fieldKey="contact_social_title" value={form.data.customization.contact_social_title} placeholder={defaults.contact_social_title} onChange={updateField} span2 />
                    <SocialLinksFields {...props} />
                </div>
            </WebsiteGroup>
        </>
    );
}

export function DealersModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_dealer_directory_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_dir_title')} fieldKey="directory_title" value={form.data.customization.directory_title} placeholder={defaults.directory_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_subtitle')} fieldKey="directory_subtitle" value={form.data.customization.directory_subtitle} placeholder={defaults.directory_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_gps_button')} fieldKey="directory_gps_btn" value={form.data.customization.directory_gps_btn} placeholder={defaults.directory_gps_btn} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_dir_empty_title')} fieldKey="directory_empty_title" value={form.data.customization.directory_empty_title} placeholder={defaults.directory_empty_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_empty_desc')} fieldKey="directory_empty_desc" value={form.data.customization.directory_empty_desc} placeholder={defaults.directory_empty_desc} onChange={updateField} />
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function ProductsModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_catalog_page_labels_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_categories_filter_title')} fieldKey="catalog_categories_title" value={form.data.customization.catalog_categories_title} placeholder={defaults.catalog_categories_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_all_products_label')} fieldKey="catalog_all_products_label" value={form.data.customization.catalog_all_products_label} placeholder={defaults.catalog_all_products_label} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_price_filter_title')} fieldKey="catalog_price_title" value={form.data.customization.catalog_price_title} placeholder={defaults.catalog_price_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_in_stock_filter')} fieldKey="catalog_instock_label" value={form.data.customization.catalog_instock_label} placeholder={defaults.catalog_instock_label} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_apply_filters_btn')} fieldKey="catalog_apply_filters_btn" value={form.data.customization.catalog_apply_filters_btn} placeholder={defaults.catalog_apply_filters_btn} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_clear_filters_btn')} fieldKey="catalog_clear_filters_btn" value={form.data.customization.catalog_clear_filters_btn} placeholder={defaults.catalog_clear_filters_btn} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_sort_label')} fieldKey="catalog_sort_by_label" value={form.data.customization.catalog_sort_by_label} placeholder={defaults.catalog_sort_by_label} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_catalog_empty_title')} fieldKey="catalog_empty_title" value={form.data.customization.catalog_empty_title} placeholder={defaults.catalog_empty_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_catalog_empty_desc')} fieldKey="catalog_empty_desc" value={form.data.customization.catalog_empty_desc} placeholder={defaults.catalog_empty_desc} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_catalog_empty_btn')} fieldKey="catalog_empty_btn" value={form.data.customization.catalog_empty_btn} placeholder={defaults.catalog_empty_btn} onChange={updateField} />
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function ProductDetailsModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_product_card_details_heading')}>
                            <div className="form-grid ed-grid">
                                <Field label={translate('portal', 'sce_f_in_stock_badge')} fieldKey="product_in_stock_label" value={form.data.customization.product_in_stock_label} placeholder={defaults.product_in_stock_label} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_out_of_stock_badge')} fieldKey="product_out_of_stock_label" value={form.data.customization.product_out_of_stock_label} placeholder={defaults.product_out_of_stock_label} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_share_label')} fieldKey="product_share_label" value={form.data.customization.product_share_label} placeholder={defaults.product_share_label} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_tab_description')} fieldKey="product_tab_description" value={form.data.customization.product_tab_description} placeholder={defaults.product_tab_description} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_tab_specification')} fieldKey="product_tab_specification" value={form.data.customization.product_tab_specification} placeholder={defaults.product_tab_specification} onChange={updateField} />
                                <Field label={translate('product_facts', 'cms_medical_note')} fieldKey="product_medical_note" value={form.data.customization.product_medical_note} placeholder={defaults.product_medical_note} onChange={updateField} multiline rows={2} span2 />
                                <Field label={translate('product_facts', 'cms_concern_title')} fieldKey="catalog_concern_title" value={form.data.customization.catalog_concern_title} placeholder={defaults.catalog_concern_title} onChange={updateField} />
                            </div>
        </WebsiteGroup>
            <WebsiteGroup title={translate('website_admin', 'group_product_video')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_product_video_title')} fieldKey="product_video_title" value={form.data.customization.product_video_title} placeholder={defaults.product_video_title} onChange={updateField} />
                </div>
            </WebsiteGroup>
            <WebsiteToolLink href="/catalog" title={translate('website_admin', 'tool_product_videos_title')} body={translate('website_admin', 'tool_product_videos_body')} />
        </>
    );
}

export function ServicesModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_services_page_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_hero_title')} fieldKey="services_hero_title" value={form.data.customization.services_hero_title} placeholder={defaults.services_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_hero_lead')} fieldKey="services_hero_lead" value={form.data.customization.services_hero_lead} placeholder={defaults.services_hero_lead} onChange={updateField} multiline rows={2} />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`svc-${i}`}>
                                        <Field label={translate('portal', 'sce_service_title', { number: String(i) })} fieldKey={`services_card${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`services_card${i}_title`]} placeholder={(defaults as any)[`services_card${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_service_description', { number: String(i) })} fieldKey={`services_card${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`services_card${i}_desc`]} placeholder={(defaults as any)[`services_card${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label={translate('portal', 'sce_f_cta_button')} fieldKey="services_cta_btn" value={form.data.customization.services_cta_btn} placeholder={defaults.services_cta_btn} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_cta_link')} fieldKey="services_cta_link" value={form.data.customization.services_cta_link} placeholder={defaults.services_cta_link} onChange={updateField} />
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function PartnershipModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_partnership_page_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_hero_title')} fieldKey="partnership_hero_title" value={form.data.customization.partnership_hero_title} placeholder={defaults.partnership_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_hero_lead')} fieldKey="partnership_hero_lead" value={form.data.customization.partnership_hero_lead} placeholder={defaults.partnership_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_why_partner_heading')} fieldKey="partnership_why_title" value={form.data.customization.partnership_why_title} placeholder={defaults.partnership_why_title} onChange={updateField} span2 />
                                {[1, 2, 3, 4].map((i) => (
                                    <React.Fragment key={`pb-${i}`}>
                                        <Field label={translate('portal', 'sce_benefit_title', { number: String(i) })} fieldKey={`partnership_benefit${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_benefit${i}_title`]} placeholder={(defaults as any)[`partnership_benefit${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_benefit_description', { number: String(i) })} fieldKey={`partnership_benefit${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_benefit${i}_desc`]} placeholder={(defaults as any)[`partnership_benefit${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label={translate('portal', 'sce_f_steps_heading')} fieldKey="partnership_steps_title" value={form.data.customization.partnership_steps_title} placeholder={defaults.partnership_steps_title} onChange={updateField} span2 />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`ps-${i}`}>
                                        <Field label={translate('portal', 'sce_step_title', { number: String(i) })} fieldKey={`partnership_step${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_step${i}_title`]} placeholder={(defaults as any)[`partnership_step${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_step_description', { number: String(i) })} fieldKey={`partnership_step${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_step${i}_desc`]} placeholder={(defaults as any)[`partnership_step${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label={translate('portal', 'sce_f_cta_title')} fieldKey="partnership_cta_title" value={form.data.customization.partnership_cta_title} placeholder={defaults.partnership_cta_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_cta_button_text')} fieldKey="partnership_cta_btn" value={form.data.customization.partnership_cta_btn} placeholder={defaults.partnership_cta_btn} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_cta_body')} fieldKey="partnership_cta_body" value={form.data.customization.partnership_cta_body} placeholder={defaults.partnership_cta_body} onChange={updateField} multiline rows={2} span2 />
                                <Field label={translate('portal', 'sce_f_cta_link')} fieldKey="partnership_cta_link" value={form.data.customization.partnership_cta_link} placeholder={defaults.partnership_cta_link} onChange={updateField} span2 />
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function QualityModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_quality_page_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_hero_title')} fieldKey="quality_hero_title" value={form.data.customization.quality_hero_title} placeholder={defaults.quality_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_hero_lead')} fieldKey="quality_hero_lead" value={form.data.customization.quality_hero_lead} placeholder={defaults.quality_hero_lead} onChange={updateField} multiline rows={2} />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`q-${i}`}>
                                        <Field label={translate('portal', 'sce_pillar_title', { number: String(i) })} fieldKey={`quality_pillar${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`quality_pillar${i}_title`]} placeholder={(defaults as any)[`quality_pillar${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_pillar_description', { number: String(i) })} fieldKey={`quality_pillar${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`quality_pillar${i}_desc`]} placeholder={(defaults as any)[`quality_pillar${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label={translate('portal', 'sce_f_cta_button')} fieldKey="quality_cta_btn" value={form.data.customization.quality_cta_btn} placeholder={defaults.quality_cta_btn} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_cta_link')} fieldKey="quality_cta_link" value={form.data.customization.quality_cta_link} placeholder={defaults.quality_cta_link} onChange={updateField} />
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function PrescribersModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_prescribers_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_prescribers_title')} fieldKey="prescribers_title" value={form.data.customization.prescribers_title} placeholder={defaults.prescribers_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_prescribers_lead')} fieldKey="prescribers_lead" value={form.data.customization.prescribers_lead} placeholder={defaults.prescribers_lead} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_prescribers_emergency')} fieldKey="prescribers_emergency_note" value={form.data.customization.prescribers_emergency_note} placeholder={defaults.prescribers_emergency_note} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_prescribers_consent')} fieldKey="prescribers_consent_line" value={form.data.customization.prescribers_consent_line} placeholder={defaults.prescribers_consent_line} onChange={updateField} span2 />
                            </div>
        </WebsiteGroup>
        </>
    );
}

const FAQ_BOXES = [
    { box: 'question_bn', english: 'question', label: 'sce_question', labelInLanguage: 'sce_question_in_language', language: 'sce_language_bangla' },
    { box: 'question', english: 'question', label: 'sce_question', labelInLanguage: 'sce_question_in_language', language: 'sce_language_english' },
    { box: 'answer_bn', english: 'answer', label: 'sce_answer', labelInLanguage: 'sce_answer_in_language', language: 'sce_language_bangla' },
    { box: 'answer', english: 'answer', label: 'sce_answer', labelInLanguage: 'sce_answer_in_language', language: 'sce_language_english' },
] as const;

export function FaqModule({ form, defaults, bnCopy, updateField }: WebsiteSectionProps) {
    const translate = useT();
    const faqRows = form.data.customization.faq_items || [];
    const updateFaqRow = (index: number, box: (typeof FAQ_BOXES)[number]['box'], value: string) => form.setData('customization', {
        ...form.data.customization,
        faq_items: faqRows.map((row, at) => (at === index ? { ...row, [box]: value } : row)),
    });
    /** A Bangla box on a built-in row the owner has not reworded shows its built-in Bangla as the hint. */
    const faqHint = (index: number, box: (typeof FAQ_BOXES)[number], label: string): string => {
        const builtIn = defaults.faq_items?.[index]?.[box.english];
        if (box.box === box.english) {
            return builtIn || label;
        }
        const untouched = builtIn !== undefined && faqRows[index]?.[box.english] === builtIn;
        return (untouched ? bnCopy.faq_items?.[index]?.[box.english] : undefined) || label;
    };
    return (
        <>
        <WebsiteGroup title={translate('portal', 'sce_faq_page_heading')}>
                            <div className="form-grid ed-grid ed-mb">
                                <Field label={translate('portal', 'sce_f_faq_hero_title')} fieldKey="faq_hero_title" value={form.data.customization.faq_hero_title} placeholder={defaults.faq_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_faq_hero_lead')} fieldKey="faq_hero_lead" value={form.data.customization.faq_hero_lead} placeholder={defaults.faq_hero_lead} onChange={updateField} multiline rows={2} />
                            </div>
                            <div className="ed-stack ed-mb-lg">
                                <p className="ed-desc is-sm is-flush">{translate('portal', 'sce_faq_languages_hint')}</p>
                                {faqRows.map((item, idx) => (
                                    <div key={`faq-${idx}`} className="ed-card is-soft">
                                        <div className="ed-head is-sm">
                                            <strong className="ed-strong">{translate('portal', 'sce_question_number', { number: String(idx + 1) })}</strong>
                                            <button
                                                type="button"
                                                className="button small danger"
                                                onClick={() => {
                                                    const next = [...(form.data.customization.faq_items || [])];
                                                    next.splice(idx, 1);
                                                    form.setData('customization', { ...form.data.customization, faq_items: next });
                                                }}
                                            >
                                                <Trash2 size={14} /> {translate('portal', 'sce_remove')}
                                            </button>
                                        </div>
                                        <div className="form-grid ed-grid is-md">
                                            {FAQ_BOXES.map((box) => {
                                                const boxLabel = translate('portal', box.labelInLanguage, { language: translate('portal', box.language) });
                                                const Control = box.english === 'question' ? 'input' : 'textarea';
                                                return (
                                                    <label key={box.box} className="ed-label is-inline">
                                                        {boxLabel}
                                                        <Control
                                                            {...(Control === 'textarea' ? { rows: 3 } : {})}
                                                            value={item[box.box] || ''}
                                                            placeholder={faqHint(idx, box, translate('portal', box.label))}
                                                            onChange={(e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => updateFaqRow(idx, box.box, e.target.value)}
                                                        />
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className="button small dark"
                                    onClick={() => form.setData('customization', {
                                        ...form.data.customization,
                                        faq_items: [...faqRows, { question: '', answer: '', question_bn: '', answer_bn: '' }],
                                    })}
                                >
                                    <Plus size={14} /> {translate('portal', 'sce_add_faq_item')}
                                </button>
                            </div>
        </WebsiteGroup>
        </>
    );
}

export function FeedbackModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <WebsiteGroup title={translate('website_admin', 'group_feedback_words')} defaultOpen>
            <div className="form-grid ed-grid">
                <Field label={translate('portal', 'sce_fld_feedback_page_title')} fieldKey="feedback_page_title" value={form.data.customization.feedback_page_title} placeholder={defaults.feedback_page_title} onChange={updateField} />
                <Field label={translate('portal', 'sce_fld_feedback_page_lead')} fieldKey="feedback_page_lead" value={form.data.customization.feedback_page_lead} placeholder={defaults.feedback_page_lead} onChange={updateField} multiline rows={2} />
            </div>
        </WebsiteGroup>
    );
}

export function NoticeModule({ form, defaults, bnCopy, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
            <WebsiteToolLink href="/content" title={translate('website_admin', 'tool_content_title')} body={translate('website_admin', 'tool_content_body')} />
            <WebsiteGroup title={translate('website_admin', 'group_notice_empty')} defaultOpen>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_fld_notice_empty')} fieldKey="notice_empty" value={form.data.customization.notice_empty} placeholder={defaults.notice_empty} onChange={updateField} span2 />
                    <Field label={translate('portal', 'sce_fld_notice_empty_bn')} fieldKey="notice_empty_bn" value={form.data.customization.notice_empty_bn} placeholder={bnCopy.notice_empty} onChange={updateField} span2 />
                </div>
            </WebsiteGroup>
        </>
    );
}

function LegalDateFields({ form, defaults, updateField }: Pick<WebsiteSectionProps, 'form' | 'defaults' | 'updateField'>) {
    const translate = useT();
    return (
        <>
            <Field label={translate('portal', 'sce_f_legal_effective')} fieldKey="legal_meta_effective" value={form.data.customization.legal_meta_effective} placeholder={defaults.legal_meta_effective} onChange={updateField} />
            <Field label={translate('portal', 'sce_f_legal_governance')} fieldKey="legal_meta_governance" value={form.data.customization.legal_meta_governance} placeholder={defaults.legal_meta_governance} onChange={updateField} />
        </>
    );
}

export function PrivacyModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <WebsiteGroup title={translate('website_admin', 'group_policy_words')} defaultOpen>
            <div className="form-grid ed-grid">
                <Field label={translate('portal', 'sce_f_privacy_title')} fieldKey="privacy_hero_title" value={form.data.customization.privacy_hero_title} placeholder={defaults.privacy_hero_title} onChange={updateField} />
                <Field label={translate('portal', 'sce_f_privacy_lead')} fieldKey="privacy_hero_lead" value={form.data.customization.privacy_hero_lead} placeholder={defaults.privacy_hero_lead} onChange={updateField} multiline rows={2} />
                <LegalDateFields form={form} defaults={defaults} updateField={updateField} />
                <Field label={translate('portal', 'sce_f_privacy_body')} fieldKey="privacy_body" value={form.data.customization.privacy_body} placeholder={defaults.privacy_body} onChange={updateField} multiline rows={12} span2 />
            </div>
        </WebsiteGroup>
    );
}

export function TermsModule({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <WebsiteGroup title={translate('website_admin', 'group_policy_words')} defaultOpen>
            <div className="form-grid ed-grid">
                <Field label={translate('portal', 'sce_f_terms_title')} fieldKey="terms_hero_title" value={form.data.customization.terms_hero_title} placeholder={defaults.terms_hero_title} onChange={updateField} />
                <Field label={translate('portal', 'sce_f_terms_lead')} fieldKey="terms_hero_lead" value={form.data.customization.terms_hero_lead} placeholder={defaults.terms_hero_lead} onChange={updateField} multiline rows={2} />
                <LegalDateFields form={form} defaults={defaults} updateField={updateField} />
                <Field label={translate('portal', 'sce_f_terms_body')} fieldKey="terms_body" value={form.data.customization.terms_body} placeholder={defaults.terms_body} onChange={updateField} multiline rows={12} span2 />
            </div>
        </WebsiteGroup>
    );
}
