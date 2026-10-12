import { useState, type ReactNode } from "react";
import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import {
    ArrowUpRight,
    ArrowRight,
    ShieldCheck,
    Network,
    Users,
    CircleDollarSign,
    Check,
    Mail,
    Phone,
    LockKeyhole,
    Eye,
    EyeOff,
    ChevronRight,
    Building2,
    Fingerprint,
    CheckCircle2,
    ArrowLeft,
    Globe2,
} from "lucide-react";
import { Logo } from "@/components/Brand";
import {
    Button,
    Field,
    Notice,
    Card,
    CardHeading,
    PageHeading,
} from "@/components/Common";
import type { Shared } from "@/lib/types";
import { localized, siteStyle } from "@/lib/website";
export function AuthShell({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle: string;
    children: ReactNode;
}) {
    const { flash, demo, website: source } = usePage<Shared>().props;
    const [language, setLanguage] = useState(source.languages.default);
    const website = localized(source, language);
    return (
        <>
            <Head title={title} />
            <div
                className="auth-page site-root"
                style={siteStyle(website)}
                data-motion={website.theme.motion}
            >
                <section className="auth-brand-panel">
                    <Link href="/">
                        <Logo light />
                    </Link>
                    <div className="auth-brand-copy">
                        <span className="banner-eyebrow">
                            A PLACE FOR EVERY PARTNER
                        </span>
                        <h1>{website.login.title}</h1>
                        <p>{website.login.body}</p>
                        {website.login.image && (
                            <img
                                className="site-section-image"
                                src={website.login.image}
                                alt=""
                            />
                        )}
                        <div className="auth-graphic">
                            <div className="auth-orbit" />
                            <span>
                                <Network size={50} />
                            </span>
                            <i>
                                <Users size={22} />
                            </i>
                            <b>
                                <ShieldCheck size={24} />
                            </b>
                        </div>
                    </div>
                    <span className="auth-brand-footer">
                        <ShieldCheck size={17} /> One network. A world of
                        possibilities.
                    </span>
                </section>
                <section className="auth-form-panel">
                    <Link href="/" className="back-link">
                        <ArrowLeft size={15} /> Back to website
                    </Link>
                    {website.languages.switcher && (
                        <button
                            onClick={() =>
                                setLanguage(language === "en" ? "bn" : "en")
                            }
                        >
                            {language === "en" ? "বাংলা" : "English"}
                        </button>
                    )}
                    <div className="auth-form-wrap">
                        <span className="auth-icon">
                            <LockKeyhole size={24} />
                        </span>
                        <h1>{title}</h1>
                        <p>{subtitle}</p>
                        {flash.success && (
                            <Notice tone="success">{flash.success}</Notice>
                        )}
                        {children}
                        <div className="auth-footnote">
                            <ShieldCheck size={15} /> {website.login.help_text}
                            <a href={website.login.support_href}>
                                Contact support
                            </a>
                        </div>
                    </div>
                    <span className="auth-form-footer">
                        Nexora · Secure dealer operations
                    </span>
                </section>
            </div>
        </>
    );
}
