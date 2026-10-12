import { usePage } from "@inertiajs/react";
import type { Shared } from "@/lib/types";
import type { SiteDocument } from "@/lib/website";
export function Logo({
    light = false,
    document,
}: {
    light?: boolean;
    document?: SiteDocument;
}) {
    const shared = usePage<Shared>().props.website;
    const brand = (document ?? shared)?.brand;
    const image = light && brand?.dark_logo ? brand.dark_logo : brand?.logo;
    if (image)
        return (
            <img
                className="site-logo"
                src={image}
                width={brand.logo_width}
                alt={brand.name}
                style={{
                    maxWidth: "100%",
                    height: "auto",
                    maxHeight: 70,
                    objectFit: "contain",
                }}
            />
        );
    return (
        <span className={`brand ${light ? "brand-light" : ""}`}>
            <span className="brand-mark">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <path
                        d="M7 24V8h4l10 11V8h4v16h-4L11 13v11z"
                        fill="currentColor"
                    />
                </svg>
            </span>
            <span>
                {brand?.name ?? "nexora"}
                <span className="brand-period">.</span>
            </span>
        </span>
    );
}
