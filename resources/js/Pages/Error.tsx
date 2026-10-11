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
import { AuthShell } from "@/components/AuthShell";
export default function Error({ status }: { status: number }) {
    return (
        <>
            <Head
                title={
                    status === 403 ? "Access restricted" : "Page unavailable"
                }
            />
            <div className="error-page">
                <Logo />
                <span>{status}</span>
                <h1>
                    {status === 403
                        ? "This area is private."
                        : status === 404
                          ? "A little off the path."
                          : status === 429
                            ? "A moment between requests."
                            : "We need a moment."}
                </h1>
                <p>
                    {status === 403
                        ? "Your account does not have access to this page, or it is awaiting activation. Contact your company administrator."
                        : status === 404
                          ? "The page you requested could not be found."
                          : status === 429
                            ? "You’ve reached the request limit. Please wait a few minutes, then try again."
                            : "The workspace could not complete this request. Please try again shortly."}
                </p>
                <Button asChild>
                    <Link href="/">
                        Return to workspace <ArrowRight size={17} />
                    </Link>
                </Button>
            </div>
        </>
    );
}
