import { Link } from '@inertiajs/react';
import { ActionForm, type Field, type Row } from '../../UI';
import OfficePage from '../OfficePage';
import type { OfficeHub } from '../../OfficeHubTabs';
import { useT } from '../../../shared/i18n/t';

type VideoSetting = { enabled: boolean; version: number; product_id?: number | string | null; title?: string | null; description?: string | null };

/**
 * "ভিডিও": the home page video, one form. Whoever can open the website page also gets the way
 * to the video gallery, which is saved with the rest of the website there.
 */
export default function VideoMarketing({ hub, managedOnWebsite, videoSetting, videoProducts }: {
    hub?: OfficeHub | null;
    managedOnWebsite: boolean;
    videoSetting: VideoSetting;
    videoProducts: Array<{ id: number; name: string }>;
}) {
    const translate = useT();
    const yesOrNo = (value: string): string => translate('portal', value === '1' ? 'yes' : 'no');
    const fields: Field[] = [
        { name: 'enabled', label: translate('portal', 'ops_fld_show_video'), required: true, options: ['1', '0'].map((value) => ({ value, label: yesOrNo(value) })) },
        { name: 'product_id', label: translate('portal', 'ops_fld_product_video'), required: false, search: true, hint: translate('portal', videoProducts.length === 0 ? 'ops_no_product_videos' : 'ops_promote_video_hide'), options: videoProducts.map((product) => ({ value: String(product.id), label: product.name })) },
        { name: 'title', label: translate('portal', 'ops_fld_section_title'), required: true },
        { name: 'description', label: translate('portal', 'ops_fld_introduction'), type: 'textarea', required: false },
        { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true, place: 'settings.homepage_video' },
    ];
    const defaults: Row = {
        ...videoSetting,
        enabled: videoSetting.enabled ? '1' : '0',
        title: videoSetting.title ?? translate('portal', 'ops_video_title_default'),
    };

    return (
        <OfficePage
            title={hub?.label ?? translate('website_admin', 'heading')}
            hub={hub}
            action={managedOnWebsite ? <Link className="button" href="/website#website-video">{translate('office_website', 'video_gallery_link')}</Link> : undefined}
        >
            <section className="panel narrow-panel">
                <ActionForm key={videoSetting.version} action="/commands/operations/marketing/homepage-video" fields={fields} defaults={defaults} submit={translate('portal', 'ops_save_homepage_video')} />
            </section>
        </OfficePage>
    );
}
