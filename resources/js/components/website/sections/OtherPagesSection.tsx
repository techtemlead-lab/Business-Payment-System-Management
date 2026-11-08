import React from 'react';
import { PAGE_FLAGS } from '../pageFlags';
import { Field, MdPhotoField } from '../formFields';
import { WebsiteGroup, WebsiteSection, type WebsiteSectionProps } from '../WebsiteLayout';
import type { CustomizationData } from '../types';
import { useT } from '../../../shared/i18n/t';

export default function OtherPagesSection({ form, defaults, websitePages, bnCopy, updateField }: WebsiteSectionProps) {
    const translate = useT();
    const pageFlags = PAGE_FLAGS;
    return (
        <WebsiteSection id="pages" title={translate('website_admin', 'section_pages')} hint={translate('website_admin', 'section_pages_hint')}>
        <WebsiteGroup title={translate('portal', 'cms_visibility_title')}>
            <p className="ed-desc is-sm">{translate('portal', 'cms_visibility_hint')}</p>
                    <div className="ed-grid is-sm">
                        {(websitePages.length > 0 ? websitePages : [
                            { key: 'home', slug: '/', label: translate('portal', 'website_page_home'), toggleable: false, enabled: true, flag: null },
                            ...pageFlags.map((flag) => ({
                                key: flag.replace('page_enabled_', ''),
                                slug: '/' + flag.replace('page_enabled_', ''),
                                label: translate('portal', `website_page_${flag.replace('page_enabled_', '')}`),
                                toggleable: true,
                                enabled: form.data.customization[flag] !== '0',
                                flag,
                            })),
                        ]).map((page) => {
                            const flag = page.flag as typeof pageFlags[number] | null | undefined;
                            const on = page.toggleable === false ? true : (flag ? form.data.customization[flag] !== '0' : page.enabled !== false);
                            return (
                                <div key={page.key} className={`ed-toggle-row${on ? ' is-on' : ''}`}>
                                    <div className="ed-shrink">
                                        <div className="ed-strong is-sm hub-capital">{page.label}</div>
                                        <div className="ed-hint">{page.slug}</div>
                                    </div>
                                    {page.toggleable === false || !flag ? (
                                        <span className="ed-label is-inline tone-ok">{translate('portal', 'cms_always_on')}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => updateField(flag, on ? '0' : '1')}
                                            className={`button small ed-toggle-btn ${on ? 'dark' : ''}`}
                                        >
                                            {on ? translate('portal', 'cms_on') : translate('portal', 'cms_off')}
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <div className="ed-row is-wrap ed-mt">
                        {websitePages.filter((page) => page.toggleable === false || (page.flag ? form.data.customization[page.flag as typeof pageFlags[number]] !== '0' : page.enabled)).map((page) => (
                            <a
                                key={`preview-${page.slug}`}
                                href={page.slug}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="button small"
                            >
                                {translate('portal', 'cms_preview_page', { label: page.label })}
                            </a>
                        ))}
                    </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_prescribers_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_prescribers_title')} fieldKey="prescribers_title" value={form.data.customization.prescribers_title} placeholder={defaults.prescribers_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_prescribers_lead')} fieldKey="prescribers_lead" value={form.data.customization.prescribers_lead} placeholder={defaults.prescribers_lead} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_prescribers_emergency')} fieldKey="prescribers_emergency_note" value={form.data.customization.prescribers_emergency_note} placeholder={defaults.prescribers_emergency_note} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_prescribers_consent')} fieldKey="prescribers_consent_line" value={form.data.customization.prescribers_consent_line} placeholder={defaults.prescribers_consent_line} onChange={updateField} span2 />
                            </div>
        </WebsiteGroup>

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

        <WebsiteGroup title={translate('portal', 'sce_dealer_directory_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_dir_title')} fieldKey="directory_title" value={form.data.customization.directory_title} placeholder={defaults.directory_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_subtitle')} fieldKey="directory_subtitle" value={form.data.customization.directory_subtitle} placeholder={defaults.directory_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_gps_button')} fieldKey="directory_gps_btn" value={form.data.customization.directory_gps_btn} placeholder={defaults.directory_gps_btn} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_dir_empty_title')} fieldKey="directory_empty_title" value={form.data.customization.directory_empty_title} placeholder={defaults.directory_empty_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dir_empty_desc')} fieldKey="directory_empty_desc" value={form.data.customization.directory_empty_desc} placeholder={defaults.directory_empty_desc} onChange={updateField} />
                            </div>
        </WebsiteGroup>

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
        </WebsiteSection>
    );
}
