import React from 'react';
import { Link, usePage, type InertiaFormProps } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
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

/** What every section of the website page receives from the page itself. */
export interface WebsiteSectionProps {
    form: WebsiteForm;
    defaults: CustomizationData;
    products: WebsiteProduct[];
    websitePages: WebsitePage[];
    bnCopy: { copyright?: string; notice_empty?: string } & MdMessageBanglaCopy;
    updateField: (field: keyof CustomizationData, value: string) => void;
    updateSlide: (index: number, key: keyof Slide, value: string) => void;
    updateSideSlide: (banner: 1 | 2, index: number, key: keyof Slide, value: string) => void;
}

/** One of the five big parts of the website page. */
export function WebsiteSection({ id, title, hint, children }: { id: string; title: string; hint: string; children: React.ReactNode }) {
    return (
        <section className="panel ws-section" id={`website-${id}`} aria-labelledby={`website-${id}-title`}>
            <h2 className="ws-section-title" id={`website-${id}-title`}>{title}</h2>
            <p className="ed-desc">{hint}</p>
            <div className="ws-group-list">{children}</div>
        </section>
    );
}

/**
 * A closed row that opens to show its boxes. The boxes stay in the page while
 * closed, so the search can still find them.
 */
export function WebsiteGroup({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <details className="ws-group">
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
