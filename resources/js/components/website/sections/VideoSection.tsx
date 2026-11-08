import React from 'react';
import { Field } from '../formFields';
import { WebsiteGroup, WebsiteSection, WebsiteToolLink, type WebsiteSectionProps } from '../WebsiteLayout';
import { ActionForm, type Field as ActionField, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';

export interface HomepageVideoSetting {
    enabled?: boolean;
    product_id?: number | string | null;
    title?: string;
    description?: string;
    version?: number;
}

/**
 * The one place for videos: which video plays on the home page, the video
 * gallery switch and headings, and where product videos are added.
 */
export default function VideoSection({
    form,
    defaults,
    updateField,
    videoSetting,
    videoProducts,
}: WebsiteSectionProps & { videoSetting: HomepageVideoSetting; videoProducts: Array<{ id: number; name: string }> }) {
    const translate = useT();
    const yesNo = (value: string) => translate('portal', value === '1' ? 'yes' : 'no');
    const galleryOn = form.data.customization.video_gallery_enabled !== '0';
    const homepageFields: ActionField[] = [
        { name: 'enabled', label: translate('portal', 'ops_fld_show_video'), required: true, options: ['1', '0'].map((value) => ({ value, label: yesNo(value) })) },
        { name: 'product_id', label: translate('portal', 'ops_fld_product_video'), required: false, options: videoProducts.map((product) => ({ value: String(product.id), label: product.name })) },
        { name: 'title', label: translate('portal', 'ops_fld_section_title'), required: true },
        { name: 'description', label: translate('portal', 'ops_fld_introduction'), type: 'textarea', required: false },
        { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true },
    ];
    const homepageDefaults: Row = {
        ...videoSetting,
        enabled: videoSetting.enabled ? '1' : '0',
        title: videoSetting.title ?? translate('portal', 'ops_video_title_default'),
    };

    return (
        <WebsiteSection id="video" title={translate('website_admin', 'section_video')} hint={translate('website_admin', 'section_video_hint')}>
            <WebsiteGroup title={translate('website_admin', 'group_home_video')}>
                <p className="ed-desc is-sm">{translate('website_admin', 'home_video_own_save')}</p>
                <p className="ed-desc is-sm">{translate('portal', 'ops_promote_video_hide')}</p>
                {videoProducts.length === 0 && <p className="ed-desc is-sm">{translate('portal', 'ops_no_product_videos')}</p>}
                <ActionForm action="/commands/operations/marketing/homepage-video" fields={homepageFields} defaults={homepageDefaults} submit={translate('portal', 'ops_save_homepage_video')} />
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
                    <Field label={translate('portal', 'sce_f_product_video_title')} fieldKey="product_video_title" value={form.data.customization.product_video_title} placeholder={defaults.product_video_title} onChange={updateField} />
                </div>
            </WebsiteGroup>
            <WebsiteToolLink href="/catalog" title={translate('website_admin', 'tool_product_videos_title')} body={translate('website_admin', 'tool_product_videos_body')} />
        </WebsiteSection>
    );
}
