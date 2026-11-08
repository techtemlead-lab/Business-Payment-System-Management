import React from 'react';
import { CategoryManager } from '../CategoryManager';
import { Field } from '../formFields';
import { HeaderNavCategoriesManager } from '../HeaderNavCategoriesManager';
import { MobileSearchCategoriesManager } from '../MobileSearchCategoriesManager';
import { WebsiteGroup, type WebsiteSectionProps } from '../WebsiteLayout';
import { useT } from '../../../shared/i18n/t';
import { CompanyNameField, PhoneAndEmailFields, SharedFactNote, SocialLinksFields } from './SharedFields';

/** The band at the very top of every page. */
export function HeaderModule(props: WebsiteSectionProps) {
    const { form, defaults, updateField, goToModule } = props;
    const translate = useT();
    return (
        <>
            <WebsiteGroup title={translate('website_admin', 'group_header_name')} defaultOpen>
                <SharedFactNote goToModule={goToModule} />
                <div className="form-grid ed-grid">
                    <CompanyNameField {...props} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_bismillah')} defaultOpen>
                <div className="form-grid ed-grid ed-mb-lg">
                    <Field label={translate('portal', 'sce_fld_home_bismillah')} fieldKey="home_bismillah_text" value={form.data.customization.home_bismillah_text} placeholder={defaults.home_bismillah_text} onChange={updateField} />
                    <div>
                        <p className="ed-desc is-sm">{translate('portal', 'sce_home_bismillah_hint')}</p>
                        <button
                            type="button"
                            className={`button small ${form.data.customization.home_bismillah_enabled !== '0' ? 'dark' : ''}`}
                            aria-pressed={form.data.customization.home_bismillah_enabled !== '0'}
                            onClick={() => updateField('home_bismillah_enabled', form.data.customization.home_bismillah_enabled !== '0' ? '0' : '1')}
                        >
                            {form.data.customization.home_bismillah_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                        </button>
                    </div>
                </div>
            </WebsiteGroup>
        </>
    );
}

/** The menu row, the phone search and the category names they use. */
export function NavbarModule({ form, defaults, products, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
            <WebsiteGroup title={translate('website_admin', 'group_nav_words')} defaultOpen>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_search_placeholder')} fieldKey="search_placeholder" value={form.data.customization.search_placeholder} placeholder={defaults.search_placeholder} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_catalog_link')} fieldKey="header_catalog_title" value={form.data.customization.header_catalog_title} placeholder={defaults.header_catalog_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_about_link_title')} fieldKey="header_about_title" value={form.data.customization.header_about_title} placeholder={defaults.header_about_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_contact_link_title')} fieldKey="header_contact_title" value={form.data.customization.header_contact_title} placeholder={defaults.header_contact_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_network_link_title')} fieldKey="header_network_title" value={form.data.customization.header_network_title} placeholder={defaults.header_network_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_account_link_title')} fieldKey="header_account_title" value={form.data.customization.header_account_title} placeholder={defaults.header_account_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_account_link_subtitle')} fieldKey="header_account_subtitle" value={form.data.customization.header_account_subtitle} placeholder={defaults.header_account_subtitle} onChange={updateField} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_nav_mobile')}>
                <div className="form-grid ed-grid">
                    <MobileSearchCategoriesManager
                        enabled={form.data.customization.mobile_search_categories_enabled || '1'}
                        heading={form.data.customization.mobile_search_categories_heading || ''}
                        items={form.data.customization.mobile_search_categories_items || ''}
                        limit={form.data.customization.mobile_search_categories_limit || '6'}
                        featuredCategoriesRaw={form.data.customization.featured_categories_items || defaults.featured_categories_items || ''}
                        products={products}
                        onChange={updateField}
                    />
                    <HeaderNavCategoriesManager
                        value={form.data.customization.nav_categories || ''}
                        defaultValue={defaults.nav_categories || ''}
                        onChange={newVal => updateField('nav_categories', newVal)}
                        featuredCategoriesRaw={form.data.customization.featured_categories_items || defaults.featured_categories_items || ''}
                        products={products}
                    />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_category_list')}>
                <div className="ed-divided">
                    <p className="ed-desc">{translate('website_admin', 'category_list_hint')}</p>
                    <CategoryManager
                        value={form.data.customization.featured_categories_items}
                        defaultValue={defaults.featured_categories_items}
                        onChange={val => updateField('featured_categories_items', val)}
                        products={products}
                    />
                </div>
            </WebsiteGroup>
        </>
    );
}

/** Everything at the bottom of every page, and the floating chat button. */
export function FooterModule(props: WebsiteSectionProps) {
    const { form, defaults, bnCopy, updateField, goToModule } = props;
    const translate = useT();
    return (
        <>
            <WebsiteGroup title={translate('website_admin', 'group_shared_facts')} defaultOpen>
                <SharedFactNote goToModule={goToModule} />
                <div className="form-grid ed-grid">
                    <PhoneAndEmailFields {...props} />
                    <Field label={translate('website_admin', 'field_footer_location')} fieldKey="footer_location" value={form.data.customization.footer_location} placeholder={defaults.footer_location} onChange={updateField} span2 />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_social')} defaultOpen>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_column_3_heading')} fieldKey="footer_stay_connected_heading" value={form.data.customization.footer_stay_connected_heading} placeholder={defaults.footer_stay_connected_heading} onChange={updateField} span2 />
                    <SocialLinksFields {...props} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('portal', 'sce_footer_column_1_heading')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_column_1_heading')} fieldKey="footer_support_heading" value={form.data.customization.footer_support_heading} placeholder={defaults.footer_support_heading} onChange={updateField} span2 />
                    <Field label={translate('portal', 'sce_f_support_box1_subtitle')} fieldKey="footer_support_subtitle" value={form.data.customization.footer_support_subtitle} placeholder={defaults.footer_support_subtitle} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_support_box1_icon')} fieldKey="footer_support_icon" value={form.data.customization.footer_support_icon} placeholder={defaults.footer_support_icon} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_dir_box2_title')} fieldKey="footer_directory_title" value={form.data.customization.footer_directory_title} placeholder={defaults.footer_directory_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_dir_box2_subtitle')} fieldKey="footer_directory_subtitle" value={form.data.customization.footer_directory_subtitle} placeholder={defaults.footer_directory_subtitle} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_dir_box2_link')} fieldKey="footer_directory_link" value={form.data.customization.footer_directory_link} placeholder={defaults.footer_directory_link} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_dir_box2_icon')} fieldKey="footer_directory_icon" value={form.data.customization.footer_directory_icon} placeholder={defaults.footer_directory_icon} onChange={updateField} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('portal', 'sce_footer_column_2_heading')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_column_2_heading')} fieldKey="footer_about_heading" value={form.data.customization.footer_about_heading} placeholder={defaults.footer_about_heading} onChange={updateField} span2 />
                    <Field label={translate('portal', 'sce_f_footer_link1_text')} fieldKey="footer_link_about" value={form.data.customization.footer_link_about} placeholder={defaults.footer_link_about} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_footer_link1_url')} fieldKey="footer_link_about_url" value={form.data.customization.footer_link_about_url} placeholder={defaults.footer_link_about_url} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_footer_link5_text')} fieldKey="footer_link_contact" value={form.data.customization.footer_link_contact} placeholder={defaults.footer_link_contact} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_footer_link5_url')} fieldKey="footer_link_contact_url" value={form.data.customization.footer_link_contact_url} placeholder={defaults.footer_link_contact_url} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_footer_link6_text')} fieldKey="footer_link_dealer_apply" value={form.data.customization.footer_link_dealer_apply} placeholder={defaults.footer_link_dealer_apply} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_footer_link6_url')} fieldKey="footer_link_dealer_apply_url" value={form.data.customization.footer_link_dealer_apply_url} placeholder={defaults.footer_link_dealer_apply_url} onChange={updateField} />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_footer_about')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_footer_logo_url')} fieldKey="footer_logo_url" value={form.data.customization.footer_logo_url} placeholder={defaults.footer_logo_url} onChange={updateField} span2 />
                    <Field label={translate('portal', 'sce_f_footer_brand_desc')} fieldKey="footer_about_desc" value={form.data.customization.footer_about_desc} placeholder={defaults.footer_about_desc} onChange={updateField} multiline rows={3} span2 />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_apps_copyright')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_play_store_link')} fieldKey="app_google_play_link" value={form.data.customization.app_google_play_link} placeholder={defaults.app_google_play_link} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_app_store_link')} fieldKey="app_apple_store_link" value={form.data.customization.app_apple_store_link} placeholder={defaults.app_apple_store_link} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_copyright')} fieldKey="copyright_text" value={form.data.customization.copyright_text} placeholder={defaults.copyright_text} onChange={updateField} span2 />
                    <Field label={translate('portal', 'sce_fld_copyright_text_bn')} fieldKey="copyright_text_bn" value={form.data.customization.copyright_text_bn} placeholder={bnCopy.copyright} onChange={updateField} span2 />
                </div>
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_floating')}>
                <div className="form-grid ed-grid">
                    <Field label={translate('portal', 'sce_f_chat_button_label')} fieldKey="floating_chat_label" value={form.data.customization.floating_chat_label} placeholder={defaults.floating_chat_label} onChange={updateField} />
                </div>
            </WebsiteGroup>
        </>
    );
}
