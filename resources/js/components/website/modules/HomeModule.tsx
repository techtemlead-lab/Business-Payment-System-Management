import React from 'react';
import { FeaturedProductsManager } from '../FeaturedProductsManager';
import { Field, ImageUploadButton } from '../formFields';
import { VideoGalleryRows } from '../VideoGalleryRows';
import { WebsiteGroup, WebsiteToolLink, type WebsiteSectionProps } from '../WebsiteLayout';
import { ActionForm, type Field as ActionField, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';

/** Banners, products, reviews and videos on the home page. */
export default function HomeModule({ form, defaults, products, updateField, updateSlide, updateSideSlide, videoSetting, videoProducts }: WebsiteSectionProps) {
    const translate = useT();
    const yesNo = (value: string) => translate('portal', value === '1' ? 'yes' : 'no');
    const galleryOn = form.data.customization.video_gallery_enabled !== '0';
    const homepageFields: ActionField[] = [
        { name: 'enabled', label: translate('portal', 'ops_fld_show_video'), required: true, options: ['1', '0'].map((value) => ({ value, label: yesNo(value) })) },
        { name: 'product_id', label: translate('portal', 'ops_fld_product_video'), required: false, options: videoProducts.map((product) => ({ value: String(product.id), label: product.name })) },
        { name: 'title', label: translate('portal', 'ops_fld_section_title'), required: true },
        { name: 'description', label: translate('portal', 'ops_fld_introduction'), type: 'textarea', required: false },
        { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true, place: 'settings.homepage_video' },
    ];
    const homepageDefaults: Row = {
        ...videoSetting,
        enabled: videoSetting.enabled ? '1' : '0',
        title: videoSetting.title ?? translate('portal', 'ops_video_title_default'),
    };

    return (
        <>
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
                                                    <ImageUploadButton onUploaded={url => updateSlide(idx, 'image', url)} />
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
                                                            <ImageUploadButton onUploaded={url => updateSideSlide(1, idx, 'image', url)} />
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
                                                            <ImageUploadButton onUploaded={url => updateSideSlide(2, idx, 'image', url)} />
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
                                    </div>
                                </div>
                            </div>
        </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_home_video')}>
                <p className="ed-desc is-sm">{translate('website_admin', 'home_video_own_save')}</p>
                <p className="ed-desc is-sm">{translate('portal', 'ops_promote_video_hide')}</p>
                {videoProducts.length === 0 && <p className="ed-desc is-sm">{translate('portal', 'ops_no_product_videos')}</p>}
                <ActionForm key={videoSetting.version} action="/commands/operations/marketing/homepage-video" fields={homepageFields} defaults={homepageDefaults} submit={translate('portal', 'ops_save_homepage_video')} />
            </WebsiteGroup>

            <WebsiteGroup title={translate('website_admin', 'group_video_gallery')}>
                <div className="ed-head">
                    <p className="ed-desc is-sm">{translate('portal', 'sce_video_showcase_hint')}</p>
                    <button
                        type="button"
                        className={`button small ${galleryOn ? 'dark' : ''}`}
                        aria-pressed={galleryOn}
                        onClick={() => updateField('video_gallery_enabled', galleryOn ? '0' : '1')}
                    >
                        {galleryOn ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                    </button>
                </div>
                <div className="form-grid ed-grid is-md">
                    <Field label={translate('portal', 'sce_f_video_title')} fieldKey="video_gallery_title" value={form.data.customization.video_gallery_title} placeholder={defaults.video_gallery_title} onChange={updateField} />
                    <Field label={translate('portal', 'sce_f_video_subtitle')} fieldKey="video_gallery_subtitle" value={form.data.customization.video_gallery_subtitle} placeholder={defaults.video_gallery_subtitle} onChange={updateField} />
                </div>
                <VideoGalleryRows form={form} />
            </WebsiteGroup>

            <WebsiteToolLink href="/catalog?tab=offers" title={translate('portal', 'cms_product_mgmt')} body={translate('portal', 'cms_product_banner')} />
        </>
    );
}
