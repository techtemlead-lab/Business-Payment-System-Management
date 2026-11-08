import React, { useEffect, useRef } from 'react';
import { Link, usePage, type InertiaFormProps } from '@inertiajs/react';
import { ArrowUpRight, ExternalLink, type LucideIcon } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import type { CustomizationData, MdMessageBanglaCopy, Slide } from './types';

export type WebsiteForm = InertiaFormProps<{ customization: CustomizationData }>;

export interface WebsiteProduct {
    id: number;
    name: string;
    sku?: string;
    mrp: string;
    dealer_price?: string;
    category: string;
    featured?: boolean;
    stock?: number;
    image?: string;
}

export interface WebsitePage {
    key: string;
    slug: string;
    label: string;
    toggleable?: boolean;
    enabled?: boolean;
    flag?: string | null;
}

export interface HomepageVideoSetting {
    enabled?: boolean;
    product_id?: number | string | null;
    title?: string;
    description?: string;
    version?: number;
}

/** Wording and drafts for the trust pages. Only sent to staff who may change website text. */
export interface TrustEditorData {
    values: Record<string, string>;
    text_keys: string[];
    identity_keys: string[];
    contact_keys: string[];
    pages: string[];
    drafts: { en: Record<string, string>; bn: Record<string, string> };
    labels: Record<string, string>;
}

/** What every part of the website page receives from the page itself. */
export interface WebsiteSectionProps {
    form: WebsiteForm;
    defaults: CustomizationData;
    products: WebsiteProduct[];
    websitePages: WebsitePage[];
    bnCopy: { copyright?: string; notice_empty?: string } & MdMessageBanglaCopy;
    updateField: (field: keyof CustomizationData, value: string) => void;
    updateSlide: (index: number, key: keyof Slide, value: string) => void;
    updateSideSlide: (banner: 1 | 2, index: number, key: keyof Slide, value: string) => void;
    videoSetting: HomepageVideoSetting;
    videoProducts: Array<{ id: number; name: string }>;
    trustEditor: TrustEditorData | null;
    goToModule: (moduleId: string) => void;
}

/** One sub-module: its heading, the page's on/off switch and preview link, then its boxes. */
export function WebsiteModule({
    moduleId,
    area,
    active,
    icon: Icon,
    title,
    hint,
    flag,
    slug,
    form,
    updateField,
    children,
}: {
    moduleId: string;
    area: string;
    active: boolean;
    icon: LucideIcon;
    title: string;
    hint: string;
    flag?: string;
    slug?: string;
    form: WebsiteForm;
    updateField: WebsiteSectionProps['updateField'];
    children: React.ReactNode;
}) {
    const translate = useT();
    const { publicBase = '' } = usePage<{ publicBase?: string }>().props;
    const flagKey = flag as keyof CustomizationData | undefined;
    const shown = flagKey ? form.data.customization[flagKey] !== '0' : true;

    return (
        <section className="panel ws-module" id={`website-${moduleId}`} data-ws-module={moduleId} data-ws-area={area} hidden={!active} aria-labelledby={`website-${moduleId}-title`}>
            <header className="ws-module-head">
                <span className="ws-module-icon" aria-hidden="true"><Icon size={22} /></span>
                <div className="ws-module-heading">
                    <h2 className="ws-section-title" id={`website-${moduleId}-title`}>{title}</h2>
                    <p className="ed-desc">{hint}</p>
                </div>
                {(flagKey || slug) && (
                    <div className="ws-module-actions">
                        {flagKey && (
                            <button
                                type="button"
                                className={`button small ${shown ? 'dark' : ''}`}
                                aria-pressed={shown}
                                onClick={() => updateField(flagKey, shown ? '0' : '1')}
                            >
                                {translate('website_admin', shown ? 'page_shown' : 'page_hidden')}
                            </button>
                        )}
                        {slug && (
                            <a className="button small" href={`${publicBase}${slug}`} target="_blank" rel="noopener noreferrer">
                                <ExternalLink size={14} aria-hidden="true" /> {translate('website_admin', 'preview_page')}
                            </a>
                        )}
                    </div>
                )}
            </header>
            <div className="ws-group-list">{children}</div>
        </section>
    );
}

/**
 * A row that opens to show its boxes. The boxes stay in the page while
 * closed, so the search can still find them.
 */
export function WebsiteGroup({ title, defaultOpen = false, children }: { title: string; defaultOpen?: boolean; children: React.ReactNode }) {
    const detailsRef = useRef<HTMLDetailsElement>(null);

    useEffect(() => {
        if (defaultOpen && detailsRef.current) {
            detailsRef.current.open = true;
        }
    }, [defaultOpen]);

    return (
        <details className="ws-group" ref={detailsRef}>
            <summary className="ws-group-summary">
                <span className="ws-group-title">{title}</span>
            </summary>
            <div className="ws-group-body">{children}</div>
        </details>
    );
}

/** A row that takes the owner to one of the other website tools. */
export function WebsiteToolLink({ href, title, body }: { href: string; title: string; body: string }) {
    const { portalBase } = usePage<{ portalBase?: string }>().props;
    return (
        <Link className="ws-tool" href={`${portalBase ?? ''}${href}`}>
            <span className="ws-tool-text">
                <span className="ws-tool-title">{title}</span>
                <span className="ed-desc is-sm">{body}</span>
            </span>
            <ArrowUpRight size={18} aria-hidden="true" />
        </Link>
    );
}
