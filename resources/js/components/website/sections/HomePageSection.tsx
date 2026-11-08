import React from 'react';
import { CategoryManager } from '../CategoryManager';
import { FeaturedProductsManager } from '../FeaturedProductsManager';
import { HeaderNavCategoriesManager } from '../HeaderNavCategoriesManager';
import { MobileSearchCategoriesManager } from '../MobileSearchCategoriesManager';
import { Field, MdPhotoField } from '../formFields';
import { WebsiteGroup, WebsiteSection, WebsiteToolLink, type WebsiteSectionProps } from '../WebsiteLayout';
import { useT } from '../../../shared/i18n/t';

export default function HomePageSection({ form, defaults, products, updateField, updateSlide, updateSideSlide }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <WebsiteSection id="home" title={translate('website_admin', 'section_home')} hint={translate('website_admin', 'section_home_hint')}>
        <WebsiteGroup title={translate('portal', 'cms_tab_header')}>
                            <div className="form-grid ed-grid">
                                <Field label={translate('portal', 'sce_f_top_bar_text')} fieldKey="top_bar_text" value={form.data.customization.top_bar_text} placeholder={defaults.top_bar_text} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_search_placeholder')} fieldKey="search_placeholder" value={form.data.customization.search_placeholder} placeholder={defaults.search_placeholder} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_catalog_link')} fieldKey="header_catalog_title" value={form.data.customization.header_catalog_title} placeholder={defaults.header_catalog_title} onChange={updateField} />
                                
                                <Field label={translate('portal', 'sce_f_about_link_title')} fieldKey="header_about_title" value={form.data.customization.header_about_title} placeholder={defaults.header_about_title} onChange={updateField} />
                                
                                <Field label={translate('portal', 'sce_f_contact_link_title')} fieldKey="header_contact_title" value={form.data.customization.header_contact_title} placeholder={defaults.header_contact_title} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_network_link_title')} fieldKey="header_network_title" value={form.data.customization.header_network_title} placeholder={defaults.header_network_title} onChange={updateField} />

                                <Field label={translate('portal', 'sce_f_account_link_title')} fieldKey="header_account_title" value={form.data.customization.header_account_title} placeholder={defaults.header_account_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_account_link_subtitle')} fieldKey="header_account_subtitle" value={form.data.customization.header_account_subtitle} placeholder={defaults.header_account_subtitle} onChange={updateField} />

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

        <WebsiteGroup title={translate('website_admin', 'group_bismillah')}>
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

        <WebsiteGroup title={translate('website_admin', 'group_women_special')}>
                            <div className="ed-head ed-mb">
                                <p className="ed-desc is-sm">{translate('website_admin', 'women_special_hint')}</p>
                                <button
                                    type="button"
                                    className={`button small ${form.data.customization.women_special_enabled !== '0' ? 'dark' : ''}`}
                                    aria-pressed={form.data.customization.women_special_enabled !== '0'}
                                    onClick={() => updateField('women_special_enabled', form.data.customization.women_special_enabled !== '0' ? '0' : '1')}
                                >
                                    {form.data.customization.women_special_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                                </button>
                            </div>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('website_admin', 'field_women_special_title')} fieldKey="women_special_title" value={form.data.customization.women_special_title} placeholder={defaults.women_special_title} onChange={updateField} />
                                <Field label={translate('website_admin', 'field_women_special_button')} fieldKey="women_special_button" value={form.data.customization.women_special_button} placeholder={defaults.women_special_button} onChange={updateField} />
                                <Field label={translate('website_admin', 'field_women_special_body')} fieldKey="women_special_body" value={form.data.customization.women_special_body} placeholder={defaults.women_special_body} onChange={updateField} multiline rows={2} span2 />
                                <Field label={translate('website_admin', 'field_women_special_link')} fieldKey="women_special_link" value={form.data.customization.women_special_link} placeholder={defaults.women_special_link} onChange={updateField} span2 />
                                <MdPhotoField
                                    value={form.data.customization.women_special_image}
                                    onChange={val => updateField('women_special_image', val)}
                                    label={translate('website_admin', 'field_women_special_image')}
                                    clearLabel={translate('website_admin', 'women_special_picture_remove')}
                                    hint={translate('website_admin', 'women_special_picture_hint')}
                                />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'cms_banners_title')}>
                            <div className="ed-stack is-loose ed-mb-lg">
                                {(form.data.customization.slides || []).map((slide, idx) => {
                                    const defSlide = defaults.slides?.[idx] || { image: '', link: '', alt: '' };
                                    return (
                                        <div key={idx} className="ed-card is-soft">
                                            <h4 className="ed-subtitle">{translate('portal', 'sce_slide_number', { number: String(idx + 1) })}</h4>
                                            <div className="ed-grid is-md">
                                                <label>
                                                    <span className="ed-label">{translate('portal', 'sce_image_url')}</span>
                                                    <input
                                                        type="text"
                                                        value={slide.image}
                                                        placeholder={defSlide.image}
                                                        onChange={e => updateSlide(idx, 'image', e.target.value)}
                                                        className="ed-input"
                                                    />
                                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSlide.image ?? '') })}</small>
                                                </label>
                                                <label>
                                                    <span className="ed-label">{translate('portal', 'sce_target_link')}</span>
                                                    <input
                                                        type="text"
                                                        value={slide.link}
                                                        placeholder={defSlide.link}
                                                        onChange={e => updateSlide(idx, 'link', e.target.value)}
                                                        className="ed-input"
                                                    />
                                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSlide.link ?? '') })}</small>
                                                </label>
                                                <label>
                                                    <span className="ed-label">{translate('portal', 'sce_alt_caption')}</span>
                                                    <input
                                                        type="text"
                                                        value={slide.alt}
                                                        placeholder={defSlide.alt}
                                                        onChange={e => updateSlide(idx, 'alt', e.target.value)}
                                                        className="ed-input"
                                                    />
                                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSlide.alt ?? '') })}</small>
                                                </label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_side_banners_heading')}>
                            <div className="ed-grid is-lg">
                                {/* Side Banner 1 (Top) */}
                                <div className="ed-card is-soft">
                                    <h4 className="ed-subtitle">{translate('portal', 'sce_side_top_card_heading')}</h4>
                                    <p className="ed-desc is-sm is-flush ed-mb">{translate('portal', 'sce_side_top_card_hint')}</p>
                                    <div className="ed-stack">
                                        {((form.data.customization.side_banner_1_slides || defaults.side_banner_1_slides) || []).map((slide, idx) => {
                                            const defSlide = defaults.side_banner_1_slides?.[idx] || { image: '', link: '', alt: '' };
                                            return (
                                                <div key={idx} className="ed-card is-compact">
                                                    <span className="ed-label">{translate('portal', 'sce_side_top_slide_number', { number: String(idx + 1) })}</span>
                                                    <div className="ed-grid is-sm">
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_image_url')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.image}
                                                                placeholder={defSlide.image}
                                                                onChange={e => updateSideSlide(1, idx, 'image', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.image}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_target_link')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.link}
                                                                placeholder={defSlide.link}
                                                                onChange={e => updateSideSlide(1, idx, 'link', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.link}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_alt_text')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.alt}
                                                                placeholder={defSlide.alt}
                                                                onChange={e => updateSideSlide(1, idx, 'alt', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.alt}</small>
                                                        </label>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Side Banner 2 (Bottom) */}
                                <div className="ed-card is-soft">
                                    <h4 className="ed-subtitle">{translate('portal', 'sce_side_bottom_card_heading')}</h4>
                                    <p className="ed-desc is-sm is-flush ed-mb">{translate('portal', 'sce_side_bottom_card_hint')}</p>
                                    <div className="ed-stack">
                                        {((form.data.customization.side_banner_2_slides || defaults.side_banner_2_slides) || []).map((slide, idx) => {
                                            const defSlide = defaults.side_banner_2_slides?.[idx] || { image: '', link: '', alt: '' };
                                            return (
                                                <div key={idx} className="ed-card is-compact">
                                                    <span className="ed-label">{translate('portal', 'sce_side_bottom_slide_number', { number: String(idx + 1) })}</span>
                                                    <div className="ed-grid is-sm">
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_image_url')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.image}
                                                                placeholder={defSlide.image}
                                                                onChange={e => updateSideSlide(2, idx, 'image', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.image}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_target_link')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.link}
                                                                placeholder={defSlide.link}
                                                                onChange={e => updateSideSlide(2, idx, 'link', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.link}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_alt_text')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.alt}
                                                                placeholder={defSlide.alt}
                                                                onChange={e => updateSideSlide(2, idx, 'alt', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.alt}</small>
                                                        </label>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
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

        <WebsiteGroup title={translate('portal', 'sce_dealer_banner_ticker_heading')}>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label={translate('portal', 'sce_f_ticker_text')} fieldKey="announcement_text" value={form.data.customization.announcement_text} placeholder={defaults.announcement_text} onChange={updateField} span2 />
                                <Field label={translate('portal', 'sce_f_dealer_banner_title')} fieldKey="dealer_banner_title" value={form.data.customization.dealer_banner_title} placeholder={defaults.dealer_banner_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dealer_banner_subtitle')} fieldKey="dealer_banner_subtitle" value={form.data.customization.dealer_banner_subtitle} placeholder={defaults.dealer_banner_subtitle} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_dealer_banner_btn')} fieldKey="dealer_banner_btn_text" value={form.data.customization.dealer_banner_btn_text} placeholder={defaults.dealer_banner_btn_text} onChange={updateField} />
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_featured_products_section')}>
                            <FeaturedProductsManager
                                customization={form.data.customization}
                                defaults={defaults}
                                products={products}
                                updateField={updateField}
                            />
        </WebsiteGroup>

        <WebsiteGroup title={translate('website_admin', 'group_customer_reviews')}>
                            <div className="ed-card is-soft is-lg ed-mb-lg">
                                {/* Customer & Partner Testimonials */}
                                <div className="ed-card">
                                    <div className="ed-head">
                                        <div>
                                            <strong className="ed-strong">{translate('website_admin', 'testimonials_heading')}</strong>
                                            <p className="ed-desc is-sm">{translate('portal', 'sce_testimonials_hint')}</p>
                                        </div>
                                        <button
                                            type="button"
                                            className={`button small ${form.data.customization.testimonials_section_enabled !== '0' ? 'dark' : ''}`}
                                            onClick={() => updateField('testimonials_section_enabled', form.data.customization.testimonials_section_enabled !== '0' ? '0' : '1')}
                                        >
                                            {form.data.customization.testimonials_section_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                                        </button>
                                    </div>
                                    <div className="form-grid ed-grid is-md">
                                        <Field label={translate('portal', 'sce_f_testimonials_title')} fieldKey="testimonials_title" value={form.data.customization.testimonials_title} placeholder={defaults.testimonials_title} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_f_testimonials_subtitle')} fieldKey="testimonials_subtitle" value={form.data.customization.testimonials_subtitle} placeholder={defaults.testimonials_subtitle} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_fld_testimonials_invite_title')} fieldKey="testimonials_invite_title" value={form.data.customization.testimonials_invite_title} placeholder={defaults.testimonials_invite_title} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_fld_testimonials_invite_body')} fieldKey="testimonials_invite_body" value={form.data.customization.testimonials_invite_body} placeholder={defaults.testimonials_invite_body} onChange={updateField} multiline rows={2} />
                                        <Field label={translate('portal', 'sce_fld_feedback_page_title')} fieldKey="feedback_page_title" value={form.data.customization.feedback_page_title} placeholder={defaults.feedback_page_title} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_fld_feedback_page_lead')} fieldKey="feedback_page_lead" value={form.data.customization.feedback_page_lead} placeholder={defaults.feedback_page_lead} onChange={updateField} multiline rows={2} />
                                    </div>
                                </div>
                            </div>
        </WebsiteGroup>

        <WebsiteToolLink href="/catalog?tab=offers" title={translate('portal', 'cms_product_mgmt')} body={translate('portal', 'cms_product_banner')} />
        </WebsiteSection>
    );
}
