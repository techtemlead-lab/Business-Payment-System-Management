import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ExternalLink, RotateCcw, Save, SlidersHorizontal } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import { notify } from '../shared/notify';
import { buildCustomizationFormData } from './website/initialCustomization';
import { WebsiteModule, type HomepageVideoSetting, type TrustEditorData, type WebsitePage, type WebsiteProduct, type WebsiteSectionProps } from './website/WebsiteLayout';
import type { CustomizationData, MdMessageBanglaCopy, Slide } from './website/types';
import WebsiteFieldSearch from './website/WebsiteFieldSearch';
import { OLD_ANCHORS, RAIL_GROUPS, WEBSITE_AREAS, WEBSITE_MODULES, type AreaId, type WebsiteModuleDefinition } from './website/modules/moduleRegistry';

const firstModuleOf = (area: AreaId): string => WEBSITE_MODULES.find((module) => module.area === area)?.id ?? 'company';
const moduleById = (id: string): WebsiteModuleDefinition | undefined => WEBSITE_MODULES.find((module) => module.id === id);

/**
 * The owner's website settings: five main areas (the public website and each portal).
 * Inside the public website, every page and page part has its own sub-module. Every box
 * stays in the page while hidden, so the search can reach any of them.
 */
export default function SiteCustomizationEditor({
    customization,
    defaults,
    products = [],
    websitePages = [],
    bnCopy = {},
    videoSetting = { enabled: false, version: 0 },
    videoProducts = [],
    trustEditor = null,
}: {
    customization: CustomizationData;
    defaults: CustomizationData;
    bnCopy?: { copyright?: string; notice_empty?: string } & MdMessageBanglaCopy;
    products?: WebsiteProduct[];
    websitePages?: WebsitePage[];
    videoSetting?: HomepageVideoSetting;
    videoProducts?: Array<{ id: number; name: string }>;
    trustEditor?: TrustEditorData | null;
}) {
    const translate = useT();
    const pageRef = useRef<HTMLDivElement>(null);
    const form = useForm<{ customization: CustomizationData }>({
        customization: { ...buildCustomizationFormData(customization, defaults), ...(trustEditor?.values ?? {}) },
    });
    const resetForm = useForm({});
    const [activeModule, setActiveModule] = useState<string>('company');
    const activeDefinition = moduleById(activeModule) ?? WEBSITE_MODULES[0];
    const activeArea = activeDefinition.area;

    const latestForm = useRef(form);
    latestForm.current = form;

    const goToModule = useCallback((moduleId: string) => {
        if (!moduleById(moduleId)) {
            return;
        }
        setActiveModule(moduleId);
        window.history.replaceState(null, '', `#${moduleId}`);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    useEffect(() => {
        const wanted = window.location.hash.slice(1);
        const target = OLD_ANCHORS[wanted] ?? wanted;
        if (moduleById(target)) {
            setActiveModule(target);
        }
    }, []);

    /** The search lands on a box that may be hidden: show the sub-module that holds it first. */
    const revealBox = useCallback((target: HTMLElement) => {
        const moduleId = target.closest<HTMLElement>('[data-ws-module]')?.dataset.wsModule;
        if (moduleId) {
            setActiveModule(moduleId);
        }
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

    const sectionProps: WebsiteSectionProps = { form, defaults, products, websitePages, bnCopy, updateField, updateSlide, updateSideSlide, videoSetting, videoProducts, trustEditor, goToModule };
    const liveSiteHref = typeof window !== 'undefined'
        ? `${window.location.protocol}//${window.location.hostname.replace(/^(manage|sadmin|dealer|subdealer)\./, '')}${window.location.port ? ':' + window.location.port : ''}/`
        : '/';

    const areaModules = useMemo(() => WEBSITE_MODULES.filter((module) => module.area === activeArea), [activeArea]);
    const moduleTitle = (id: string): string => translate('website_admin', `module_${id}`);

    return (
        <div className="site-customization-container ws-page" ref={pageRef}>
            <div className="panel ws-hero">
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
                <WebsiteFieldSearch pageRef={pageRef} onReveal={revealBox} />
                <div className="ws-areas" role="tablist" aria-label={translate('website_admin', 'heading')}>
                    {WEBSITE_AREAS.map(({ id, icon: Icon }) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            aria-selected={activeArea === id}
                            className={`ws-area${activeArea === id ? ' is-active' : ''}`}
                            onClick={() => goToModule(firstModuleOf(id))}
                        >
                            <Icon size={22} aria-hidden="true" />
                            <span className="ws-area-title">{translate('website_admin', `main_${id}`)}</span>
                            <span className="ws-area-hint">{translate('website_admin', `main_${id}_hint`)}</span>
                        </button>
                    ))}
                </div>
            </div>

            <div className={`ws-workspace${areaModules.length > 1 ? ' has-rail' : ''}`}>
                {areaModules.length > 1 && (
                    <>
                        <nav className="panel ws-rail" aria-label={translate('website_admin', 'rail_choose')}>
                            {RAIL_GROUPS.map((group) => {
                                const items = areaModules.filter((module) => module.railGroup === group);
                                return items.length === 0 ? null : (
                                    <div key={group} className="ws-rail-group">
                                        <p className="ws-rail-heading">{translate('website_admin', `rail_${group}`)}</p>
                                        {items.map(({ id, icon: Icon, flag }) => {
                                            const hidden = flag ? (form.data.customization as Record<string, unknown>)[flag] === '0' : false;
                                            return (
                                                <button
                                                    key={id}
                                                    type="button"
                                                    className={`ws-rail-item${activeModule === id ? ' is-active' : ''}`}
                                                    aria-current={activeModule === id ? 'true' : undefined}
                                                    onClick={() => goToModule(id)}
                                                >
                                                    <Icon size={17} aria-hidden="true" />
                                                    <span>{moduleTitle(id)}</span>
                                                    {hidden && <small className="ws-rail-off">{translate('website_admin', 'page_hidden_short')}</small>}
                                                </button>
                                            );
                                        })}
                                    </div>
                                );
                            })}
                        </nav>
                        <label className="panel ws-rail-select">
                            <span className="ed-label">{translate('website_admin', 'rail_choose')}</span>
                            <select className="ed-input" value={activeModule} onChange={(event) => goToModule(event.target.value)}>
                                {RAIL_GROUPS.map((group) => (
                                    <optgroup key={group} label={translate('website_admin', `rail_${group}`)}>
                                        {areaModules.filter((module) => module.railGroup === group).map(({ id }) => (
                                            <option key={id} value={id}>{moduleTitle(id)}</option>
                                        ))}
                                    </optgroup>
                                ))}
                            </select>
                        </label>
                    </>
                )}

                <div className="ws-modules">
                    {WEBSITE_MODULES.map(({ id, area, icon, flag, slug, component: Component }) => (
                        <WebsiteModule
                            key={id}
                            moduleId={id}
                            area={area}
                            active={activeModule === id}
                            icon={icon}
                            title={moduleTitle(id)}
                            hint={translate('website_admin', `module_${id}_hint`)}
                            flag={flag && (form.data.customization as Record<string, unknown>)[flag] !== undefined ? flag : undefined}
                            slug={slug}
                            form={form}
                            updateField={updateField}
                        >
                            <Component {...sectionProps} />
                        </WebsiteModule>
                    ))}
                </div>
            </div>

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
