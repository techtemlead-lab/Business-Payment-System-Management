import React, { useCallback, useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { ExternalLink, RotateCcw, Save, SlidersHorizontal } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import { notify } from '../shared/notify';
import { buildCustomizationFormData } from './website/initialCustomization';
import type { WebsitePage, WebsiteProduct, WebsiteSectionProps } from './website/WebsiteLayout';
import type { CustomizationData, MdMessageBanglaCopy, Slide } from './website/types';
import WebsiteFieldSearch from './website/WebsiteFieldSearch';
import HomePageSection from './website/sections/HomePageSection';
import OtherPagesSection from './website/sections/OtherPagesSection';
import TextPoliciesSection from './website/sections/TextPoliciesSection';
import VideoSection, { type HomepageVideoSetting } from './website/sections/VideoSection';
import FooterContactSection from './website/sections/FooterContactSection';

const SECTION_IDS = ['home', 'pages', 'text', 'video', 'footer'] as const;

/**
 * The owner's one website page: five sections of closed rows, a search that
 * jumps to any box by name, and a Save bar that stays in view.
 */
export default function SiteCustomizationEditor({
    customization,
    defaults,
    products = [],
    websitePages = [],
    bnCopy = {},
    videoSetting = { enabled: false, version: 0 },
    videoProducts = [],
}: {
    customization: CustomizationData;
    defaults: CustomizationData;
    bnCopy?: { copyright?: string; notice_empty?: string } & MdMessageBanglaCopy;
    products?: WebsiteProduct[];
    websitePages?: WebsitePage[];
    videoSetting?: HomepageVideoSetting;
    videoProducts?: Array<{ id: number; name: string }>;
}) {
    const translate = useT();
    const pageRef = useRef<HTMLDivElement>(null);
    const form = useForm<{ customization: CustomizationData }>({
        customization: buildCustomizationFormData(customization, defaults),
    });
    const resetForm = useForm({});

    const latestForm = useRef(form);
    latestForm.current = form;

    useEffect(() => {
        const wanted = window.location.hash.slice(1);
        const target = wanted ? document.getElementById(wanted) : null;
        target?.scrollIntoView({ block: 'start' });
    }, []);

    const saveAll = () => {
        form.post('/commands/customization', {
            preserveScroll: true,
            preserveState: true,
            onError: () => notify(translate('website_admin', 'save_failed'), 'error'),
        });
    };

    const handleResetAll = () => {
        if (confirm(translate('portal', 'cms_confirm_reset_all_frontend'))) {
            resetForm.post('/commands/customization/reset');
        }
    };

    const updateField = useCallback((field: keyof CustomizationData, value: string) => {
        latestForm.current.setData('customization', {
            ...latestForm.current.data.customization,
            [field]: value,
        });
    }, []);

    const updateSlide = (index: number, key: keyof Slide, value: string) => {
        const slides = [...(form.data.customization.slides || [])];
        if (!slides[index]) slides[index] = { image: '', link: '', alt: '' };
        slides[index] = { ...slides[index], [key]: value };
        form.setData('customization', { ...form.data.customization, slides });
    };

    const updateSideSlide = (banner: 1 | 2, index: number, key: keyof Slide, value: string) => {
        const fieldKey = banner === 1 ? 'side_banner_1_slides' : 'side_banner_2_slides';
        const list = [...((form.data.customization[fieldKey] as Slide[] | undefined) || defaults[fieldKey] || [])];
        if (!list[index]) list[index] = { image: '', link: '', alt: '' };
        list[index] = { ...list[index], [key]: value };
        form.setData('customization', { ...form.data.customization, [fieldKey]: list });
    };

    const sectionProps: WebsiteSectionProps = { form, defaults, products, websitePages, bnCopy, updateField, updateSlide, updateSideSlide };
    const liveSiteHref = typeof window !== 'undefined'
        ? `${window.location.protocol}//${window.location.hostname.replace(/^(manage|sadmin|dealer|subdealer)\./, '')}${window.location.port ? ':' + window.location.port : ''}/`
        : '/';

    return (
        <div className="site-customization-container ws-page" ref={pageRef}>
            <div className="panel">
                <div className="ed-head is-flush">
                    <div>
                        <h2 className="ed-row is-loose">
                            <SlidersHorizontal size={22} className="icon-brand" /> {translate('website_admin', 'heading')}
                        </h2>
                        <p className="ed-desc">{translate('website_admin', 'heading_hint')}</p>
                    </div>
                    <a href={liveSiteHref} target="_blank" rel="noopener noreferrer" className="button small">
                        <ExternalLink size={14} /> {translate('portal', 'cms_preview_live')}
                    </a>
                </div>
                <WebsiteFieldSearch pageRef={pageRef} />
                <nav className="ws-jump" aria-label={translate('website_admin', 'heading')}>
                    {SECTION_IDS.map((id) => (
                        <a key={id} className="button small" href={`#website-${id}`}>{translate('website_admin', `section_${id}`)}</a>
                    ))}
                </nav>
            </div>

            <HomePageSection {...sectionProps} />
            <OtherPagesSection {...sectionProps} />
            <TextPoliciesSection {...sectionProps} />
            <VideoSection {...sectionProps} videoSetting={videoSetting} videoProducts={videoProducts} />
            <FooterContactSection {...sectionProps} />

            <div className="panel ws-danger-row">
                <span className="ed-desc is-sm">{translate('website_admin', 'reset_hint')}</span>
                <button type="button" onClick={handleResetAll} className="button small danger">
                    <RotateCcw size={14} /> {translate('portal', 'cms_reset_all')}
                </button>
            </div>

            <div className="ws-save-bar">
                <span className={`ws-save-status${form.isDirty ? ' is-dirty' : ''}`} role="status">
                    {translate('website_admin', form.isDirty ? 'unsaved_changes' : 'all_saved')}
                </span>
                <button type="button" onClick={saveAll} disabled={form.processing} className="button dark ed-lg">
                    <Save size={16} />
                    <span>{form.processing ? translate('portal', 'cms_saving') : translate('portal', 'cms_save')}</span>
                </button>
            </div>
        </div>
    );
}
