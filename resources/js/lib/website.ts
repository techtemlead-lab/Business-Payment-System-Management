import type { CSSProperties } from "react";

export type SiteLink = { label: string; href: string; group?: string };
export type SiteItem = {
    title: string;
    body: string;
    image?: string;
    href?: string;
    alt?: string;
};
export type SiteSection = {
    id: string;
    type: string;
    title: string;
    body: string;
    visible: boolean;
    layout: string;
    background: string;
    align: string;
    image: string;
    image_alt: string;
    focal: string;
    button_label: string;
    button_href: string;
    items: SiteItem[];
};
export type SitePage = {
    slug: string;
    title: string;
    description: string;
    visible: boolean;
    index: boolean;
    sections: SiteSection[];
};
export type SiteQuestion = {
    id: string;
    label: string;
    type: string;
    required: boolean;
    options: string[];
    when_field: string;
    when_value: string;
};
export type SiteDocument = {
    brand: Record<string, any>;
    theme: Record<string, any>;
    hero: Record<string, any>;
    navigation: SiteLink[];
    announcement: Record<string, any>;
    sections: SiteSection[];
    pages: SitePage[];
    contact: {
        email: string;
        phone: string;
        address: string;
        hours: string;
        whatsapp: string;
        socials: SiteLink[];
    };
    footer: { text: string; back_to_top: boolean; links: SiteLink[] };
    application: {
        open: boolean;
        closed_message: string;
        title: string;
        intro: string;
        button_label: string;
        success_message: string;
        consent: string;
        field_order: string[];
        labels: Record<string, string>;
        hints: Record<string, string>;
        placeholders: Record<string, string>;
        questions: SiteQuestion[];
    };
    login: Record<string, string>;
    portal: {
        announcement: string;
        landing: string;
        compact_tables: boolean;
        dashboard_order: string[];
    };
    seo: { title: string; description: string; image: string; index: boolean };
    availability: { maintenance: boolean; title: string; message: string };
    languages: { default: string; switcher: boolean };
    translations: Record<string, string>;
    redirects: { from: string; to: string }[];
};
export type SiteMedia = {
    key: string;
    url: string;
    name: string;
    alt: string;
    folder: string;
    size: number;
};
export function readPath(object: any, path: string): any {
    return path.split(".").reduce((value, key) => value?.[key], object);
}
export function writePath(object: any, path: string, value: any): void {
    const keys = path.split(".");
    let cursor = object;
    keys.slice(0, -1).forEach((key) => {
        cursor[key] ??= {};
        cursor = cursor[key];
    });
    cursor[keys[keys.length - 1]] = value;
}
export function localized(
    document: SiteDocument,
    language: string,
): SiteDocument {
    if (language !== "bn") return document;
    const copy = structuredClone(document);
    Object.entries(document.translations).forEach(([path, value]) => {
        if (value) writePath(copy, path, value);
    });
    return copy;
}
export function siteStyle(document: SiteDocument): CSSProperties {
    const t = document.theme;
    return {
        "--green": t.primary,
        "--citron": t.accent,
        "--paper": t.background,
        "--border": t.border,
        "--muted": t.muted,
        "--site-surface": t.surface,
        "--site-text": t.text,
        "--site-primary": t.primary,
        "--site-accent": t.accent,
        "--site-font":
            t.body_font === "system"
                ? "system-ui"
                : `${t.body_font === "Manrope" ? "Manrope Variable" : t.body_font}, "Nirmala UI", sans-serif`,
        "--site-heading-font":
            t.heading_font === "system"
                ? "system-ui"
                : `${t.heading_font === "Manrope" ? "Manrope Variable" : t.heading_font}, "Nirmala UI", sans-serif`,
        "--site-heading": `${t.heading_size}px`,
        "--site-radius": `${t.radius}px`,
        "--site-width": `${t.width}px`,
        "--site-spacing": `${t.spacing}px`,
        "--site-motion": `${t.motion_duration}ms`,
        fontSize: `${t.font_size}px`,
        lineHeight: t.line_height,
        color: t.text,
        background: t.background,
    } as CSSProperties;
}
export const sectionTypes = [
    "text",
    "accordion",
    "statistics",
    "workspaces",
    "contact",
    "benefits",
    "steps",
    "products",
    "testimonials",
    "logos",
    "gallery",
    "downloads",
    "cta",
    "territories",
];
export function newSection(type = "text"): SiteSection {
    return {
        id: `section-${crypto.randomUUID()}`,
        type,
        title:
            type === "accordion"
                ? "Frequently asked questions"
                : "Your section title",
        body: "Tell your visitors what matters.",
        visible: true,
        layout: "grid",
        background: "paper",
        align: "left",
        image: "",
        image_alt: "",
        focal: "center",
        button_label: "",
        button_href: "/request-dealership",
        items: [],
    };
}
export function activeQuestion(
    question: SiteQuestion,
    values: Record<string, string>,
): boolean {
    return (
        !question.when_field ||
        values[question.when_field] === question.when_value
    );
}
export function remapTranslations(
    translations: Record<string, string>,
    prefix: string,
    map: (index: number) => number | null,
): Record<string, string> {
    const result: Record<string, string> = {};
    Object.entries(translations).forEach(([key, value]) => {
        if (!key.startsWith(prefix + ".")) {
            result[key] = value;
            return;
        }
        const tail = key.slice(prefix.length + 1);
        const [index, ...rest] = tail.split(".");
        const next = map(Number(index));
        if (next !== null)
            result[`${prefix}.${next}.${rest.join(".")}`] = value;
    });
    return result;
}
export function contrastRatio(first: string, second: string): number {
    const luminance = (hex: string) => {
        const values = [1, 3, 5]
            .map((index) => parseInt(hex.slice(index, index + 2), 16) / 255)
            .map((value) =>
                value <= 0.04045
                    ? value / 12.92
                    : ((value + 0.055) / 1.055) ** 2.4,
            );
        return values[0] * 0.2126 + values[1] * 0.7152 + values[2] * 0.0722;
    };
    const a = luminance(first),
        b = luminance(second);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
export async function websiteRequest(
    url: string,
    method: string,
    data: unknown,
): Promise<any> {
    const token = document.cookie
        .split("; ")
        .find((row) => row.startsWith("XSRF-TOKEN="))
        ?.split("=")
        .slice(1)
        .join("=");
    const headers: Record<string, string> = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": decodeURIComponent(token ?? ""),
    };
    if (!(data instanceof FormData))
        headers["Content-Type"] = "application/json";
    const response = await fetch(url, {
        method,
        headers,
        credentials: "same-origin",
        body: data instanceof FormData ? data : JSON.stringify(data),
    });
    const result = await response.json();
    if (!response.ok) throw result;
    return result;
}
export async function optimizeImage(file: File): Promise<File> {
    if (typeof createImageBitmap === "undefined") return file;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type))
        return file;
    const image = await createImageBitmap(file);
    const scale = Math.min(1, 2000 / Math.max(image.width, image.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(image.width * scale);
    canvas.height = Math.round(image.height * scale);
    const context = canvas.getContext("2d");
    if (!context) {
        image.close();
        return file;
    }
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    image.close();
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, "image/webp", 0.85),
    );
    return blob
        ? new File([blob], file.name.replace(/\.[^.]+$/, "") + ".webp", {
              type: blob.type,
          })
        : file;
}
