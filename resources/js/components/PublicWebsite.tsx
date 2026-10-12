import { useState, type MouseEvent } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import type { Shared } from "@/lib/types";
import { ArrowUpRight, Menu, X } from "lucide-react";
import { Logo } from "./Brand";
import { NetworkScene } from "./NetworkScene";
import { Reveal } from "./Reveal";
import {
    localized,
    siteStyle,
    type SiteDocument,
    type SiteSection,
} from "@/lib/website";

export function PublicWebsite({
    document,
    slug = "",
    preview = false,
    language: forcedLanguage,
    onSelect,
}: {
    document: SiteDocument;
    slug?: string;
    preview?: boolean;
    language?: string;
    onSelect?: (path: string) => void;
}) {
    const [language, setLanguage] = useState(document.languages.default);
    const [mobile, setMobile] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const d = localized(document, forcedLanguage ?? language);
    const { demo } = usePage<Shared>().props;
    const page = d.pages.find((page) => page.slug === slug);
    const sections = page?.sections ?? d.sections;
    const sectionPrefix = page
        ? `pages.${d.pages.indexOf(page)}.sections`
        : "sections";
    const announcement = d.announcement;
    const now = Date.now();
    const showAnnouncement =
        announcement.enabled &&
        !dismissed &&
        (!announcement.starts_at ||
            Date.parse(announcement.starts_at) <= now) &&
        (!announcement.ends_at || Date.parse(announcement.ends_at) >= now);
    const select = (event: MouseEvent, path: string) => {
        if (onSelect) {
            event.preventDefault();
            event.stopPropagation();
            onSelect(path);
        }
    };
    const menu = (
        <>
            {d.navigation
                .filter((item) => !item.group)
                .map((item, index) => (
                    <a key={index} href={item.href}>
                        {item.label}
                    </a>
                ))}
            {[
                ...new Set(
                    d.navigation.map((item) => item.group).filter(Boolean),
                ),
            ].map((group) => (
                <details key={group}>
                    <summary>{group}</summary>
                    <div>
                        {d.navigation
                            .filter((item) => item.group === group)
                            .map((item, index) => (
                                <a key={index} href={item.href}>
                                    {item.label}
                                </a>
                            ))}
                    </div>
                </details>
            ))}
        </>
    );
    return (
        <div
            className={`site-root public-page landing ${preview ? "site-preview" : ""}`}
            style={siteStyle(d)}
            lang={forcedLanguage ?? language}
            data-motion={d.theme.motion}
            data-mode={d.theme.mode}
            data-button={d.theme.button_style}
            data-shadow={d.theme.shadow}
            data-density={d.theme.density}
        >
            {!onSelect && (
                <Head title={page?.title ?? d.seo.title}>
                    <meta
                        head-key="description"
                        name="description"
                        content={page?.description ?? d.seo.description}
                    />
                    <meta
                        head-key="robots"
                        name="robots"
                        content={
                            preview || !(page?.index ?? d.seo.index)
                                ? "noindex,nofollow"
                                : "index,follow"
                        }
                    />
                    <meta
                        head-key="og:title"
                        property="og:title"
                        content={page?.title ?? d.seo.title}
                    />
                    <meta
                        head-key="og:description"
                        property="og:description"
                        content={page?.description ?? d.seo.description}
                    />
                    {d.seo.image && (
                        <meta
                            head-key="og:image"
                            property="og:image"
                            content={window.location.origin + d.seo.image}
                        />
                    )}
                    {d.brand.favicon && (
                        <link
                            head-key="favicon"
                            rel="icon"
                            href={d.brand.favicon}
                        />
                    )}
                </Head>
            )}
            {preview && !onSelect && (
                <div className="site-preview-notice">
                    Private draft preview ·{" "}
                    <Link href="/website/customize">Return to editor</Link>
                </div>
            )}
            <a className="skip-link" href="#main">
                Skip to content
            </a>
            {showAnnouncement && (
                <div
                    className="site-announcement"
                    onClick={(event) =>
                        onSelect && select(event, "announcement")
                    }
                >
                    <a href={announcement.href}>{announcement.text}</a>
                    {announcement.dismissible && (
                        <button
                            aria-label="Dismiss announcement"
                            onClick={() => setDismissed(true)}
                        >
                            <X size={16} />
                        </button>
                    )}
                </div>
            )}
            <header
                className={`landing-header site-header ${d.theme.sticky_header ? "site-sticky" : ""}`}
                data-layout={d.theme.header_layout}
                onClick={(event) => onSelect && select(event, "navigation")}
            >
                <a href="/" aria-label={`${d.brand.name} home`}>
                    <Logo document={d} />
                </a>
                <nav
                    className="site-desktop-nav"
                    aria-label="Website navigation"
                >
                    {menu}
                </nav>
                <a href="/login" className="landing-signin">
                    {language === "bn" ? "সাইন ইন" : "Sign in"}{" "}
                    <ArrowUpRight size={17} />
                </a>
                {d.languages.switcher && (
                    <button
                        className="site-language"
                        onClick={() =>
                            setLanguage(language === "en" ? "bn" : "en")
                        }
                    >
                        {language === "en" ? "বাংলা" : "English"}
                    </button>
                )}
                <button
                    className="site-menu-button"
                    aria-label="Website menu"
                    aria-expanded={mobile}
                    onClick={() => setMobile(!mobile)}
                >
                    <Menu size={20} />
                </button>
                {mobile && (
                    <nav
                        className="site-mobile-nav"
                        aria-label="Mobile website navigation"
                        onClick={() => setMobile(false)}
                        onKeyDown={(event) => {
                            if (event.key === "Escape") {
                                setMobile(false);
                                window.document
                                    .querySelector<HTMLButtonElement>(
                                        ".site-menu-button",
                                    )
                                    ?.focus();
                            }
                        }}
                    >
                        {menu}
                    </nav>
                )}
            </header>
            <main id="main">
                {d.availability.maintenance ? (
                    <section className="site-section">
                        <h1>{d.availability.title}</h1>
                        <p>{d.availability.message}</p>
                        <a className="site-button" href="/login">
                            Sign in
                        </a>
                    </section>
                ) : (
                    <>
                        {!page && (
                            <section
                                className="landing-hero site-hero"
                                style={{
                                    minHeight: d.hero.height,
                                    textAlign: d.hero.align,
                                }}
                                onClick={(event) =>
                                    onSelect && select(event, "hero")
                                }
                            >
                                <div className="landing-hero-copy">
                                    <span className="editorial-label">
                                        <i />
                                        {d.hero.eyebrow}
                                    </span>
                                    <h1>
                                        {String(d.hero.title)
                                            .split("\n")
                                            .map((line, index) => (
                                                <span key={index}>{line}</span>
                                            ))}
                                        <em>{d.hero.highlight}</em>
                                    </h1>
                                    <p>{d.hero.body}</p>
                                    <div className="landing-hero-actions">
                                        <a
                                            className="site-button"
                                            href={d.hero.button_href}
                                        >
                                            {d.hero.button_label}{" "}
                                            <ArrowUpRight size={18} />
                                        </a>
                                        <a
                                            className="landing-discover"
                                            href={d.hero.secondary_href}
                                        >
                                            {d.hero.secondary_label}{" "}
                                            <ArrowUpRight size={16} />
                                        </a>
                                    </div>
                                    <p className="site-tagline">
                                        {d.brand.tagline}
                                    </p>
                                </div>
                                <Reveal className="landing-hero-art">
                                    <div className="site-hero-media">
                                        {d.hero.image ? (
                                            <>
                                                <picture>
                                                    {d.hero.mobile_image && (
                                                        <source
                                                            media="(max-width: 640px)"
                                                            srcSet={
                                                                d.hero
                                                                    .mobile_image
                                                            }
                                                        />
                                                    )}
                                                    <img
                                                        src={d.hero.image}
                                                        alt={d.hero.image_alt}
                                                        style={{
                                                            objectPosition:
                                                                d.hero.focal,
                                                        }}
                                                    />
                                                </picture>
                                                <div
                                                    style={{
                                                        background: "#000",
                                                        opacity:
                                                            d.hero.overlay /
                                                            100,
                                                    }}
                                                    className="site-image-overlay"
                                                />
                                            </>
                                        ) : (
                                            d.hero.illustration && (
                                                <NetworkScene />
                                            )
                                        )}
                                    </div>
                                </Reveal>
                            </section>
                        )}
                        {page && (
                            <section className="site-page-heading">
                                <span className="editorial-label">
                                    {d.brand.name}
                                </span>
                                <h1>{page.title}</h1>
                                <p>{page.description}</p>
                            </section>
                        )}
                        {sections.map(
                            (section, index) =>
                                section.visible && (
                                    <section
                                        key={section.id}
                                        id={section.id}
                                        className={`site-section site-section-${section.type} site-background-${section.background}`}
                                        data-layout={section.layout}
                                        style={{
                                            textAlign:
                                                section.align === "center"
                                                    ? "center"
                                                    : "left",
                                        }}
                                        onClick={(event) =>
                                            onSelect &&
                                            select(
                                                event,
                                                `${sectionPrefix}.${index}`,
                                            )
                                        }
                                    >
                                        <Reveal>
                                            <span className="editorial-label">
                                                {String(index + 1).padStart(
                                                    2,
                                                    "0",
                                                )}{" "}
                                                / {d.brand.tagline}
                                            </span>
                                            <h2>{section.title}</h2>
                                            <p className="site-section-intro">
                                                {section.body}
                                            </p>
                                            <SectionBody
                                                section={section}
                                                document={d}
                                            />
                                            {section.button_label && (
                                                <a
                                                    className="site-button"
                                                    href={section.button_href}
                                                >
                                                    {section.button_label}{" "}
                                                    <ArrowUpRight size={16} />
                                                </a>
                                            )}
                                        </Reveal>
                                    </section>
                                ),
                        )}
                    </>
                )}
                {demo && !preview && (
                    <div className="site-section">
                        <p>Explore the local sample workspaces.</p>
                        <button
                            className="site-button"
                            onClick={() =>
                                router.post("/demo-login", { role: "admin" })
                            }
                        >
                            Explore admin workspace
                        </button>{" "}
                        <button
                            className="site-button"
                            onClick={() =>
                                router.post("/demo-login", { role: "dealer" })
                            }
                        >
                            Explore dealer workspace
                        </button>
                    </div>
                )}
            </main>
            <footer
                className="site-footer"
                onClick={(event) => onSelect && select(event, "footer")}
            >
                <Logo document={d} />
                <p>
                    © {new Date().getFullYear()} {d.brand.name}. {d.footer.text}
                </p>
                <nav>
                    {d.footer.links.map((link, index) => (
                        <a key={index} href={link.href}>
                            {link.group && `${link.group}: `}
                            {link.label}
                        </a>
                    ))}
                </nav>
                {d.footer.back_to_top && <a href="#main">Back to top ↑</a>}
            </footer>
        </div>
    );
}
function SectionBody({
    section: s,
    document: d,
}: {
    section: SiteSection;
    document: SiteDocument;
}) {
    if (s.type === "contact")
        return (
            <div className="site-contact">
                <p>{d.contact.address}</p>
                <p>{d.contact.hours}</p>
                {d.contact.email && (
                    <a href={`mailto:${d.contact.email}`}>{d.contact.email}</a>
                )}
                {d.contact.phone && (
                    <a href={`tel:${d.contact.phone}`}>{d.contact.phone}</a>
                )}
                {d.contact.whatsapp && (
                    <a
                        href={`https://wa.me/${d.contact.whatsapp.replace("+", "")}`}
                    >
                        WhatsApp
                    </a>
                )}
                {d.contact.socials.map((link, index) => (
                    <a key={index} href={link.href}>
                        {link.label}
                    </a>
                ))}
            </div>
        );
    if (s.type === "accordion")
        return (
            <div className="site-accordion">
                {s.items.map((item, index) => (
                    <details key={index}>
                        <summary>{item.title}</summary>
                        <p>{item.body}</p>
                    </details>
                ))}
            </div>
        );
    return (
        <div className="site-section-body">
            {s.image && (
                <img
                    className="site-section-image"
                    src={s.image}
                    alt={s.image_alt}
                    loading="lazy"
                    style={{ objectPosition: s.focal }}
                />
            )}
            <div className="site-items">
                {s.items.map((item, index) => (
                    <article className="site-item" key={index}>
                        {item.image && (
                            <img
                                src={item.image}
                                alt={item.alt ?? item.title}
                                loading="lazy"
                            />
                        )}
                        <h3>
                            {s.type === "steps" && `${index + 1}. `}
                            {item.title}
                        </h3>
                        <p>{item.body}</p>
                        {item.href && (
                            <a href={item.href}>
                                {s.type === "downloads"
                                    ? "Download"
                                    : "Learn more"}{" "}
                                ↗
                            </a>
                        )}
                        {s.type === "workspaces" && (
                            <a
                                className="site-button"
                                href={`/login?workspace=${index === 0 ? "company" : "dealer"}`}
                            >
                                Enter workspace ↗
                            </a>
                        )}
                    </article>
                ))}
            </div>
        </div>
    );
}
