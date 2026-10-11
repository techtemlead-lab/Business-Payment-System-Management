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
import { Logo, Layout } from "@/components/Layout";
import {
    Button,
    Field,
    Notice,
    Card,
    CardHeading,
    PageHeading,
} from "@/components/Common";
import type { Shared } from "@/lib/types";
export function AuthShell({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle: string;
    children: ReactNode;
}) {
    const { flash, demo } = usePage<Shared>().props;
    return (
        <>
            <Head title={title} />
            <div className="auth-page">
                <section className="auth-brand-panel">
                    <Link href="/">
                        <Logo light />
                    </Link>
                    <div className="auth-brand-copy">
                        <span className="banner-eyebrow">BETTER TOGETHER</span>
                        <h1>
                            Great partnerships.
                            <br />
                            Clearer possibilities.
                        </h1>
                        <p>
                            Your dealer network deserves a workspace built
                            around trust, transparency, and shared progress.
                        </p>
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
                            <ShieldCheck size={15} /> Accounts are created and
                            approved by the company.
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
