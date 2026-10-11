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
import { AuthShell } from "@/components/AuthShell";
export default function TwoFactor() {
    const form = useForm({ code: "" });
    return (
        <AuthShell
            title="One more step"
            subtitle="Enter your authenticator code or an unused recovery code."
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/two-factor");
                }}
            >
                <Field label="Authentication code" error={form.errors.code}>
                    <input
                        autoFocus
                        autoComplete="one-time-code"
                        value={form.data.code}
                        onChange={(e) => form.setData("code", e.target.value)}
                        placeholder="6-digit code or recovery code"
                    />
                </Field>
                <Button className="full-width" disabled={form.processing}>
                    Verify and continue <ArrowRight size={17} />
                </Button>
            </form>
            <button
                className="auth-return"
                onClick={() => router.post("/logout")}
            >
                Sign out
            </button>
        </AuthShell>
    );
}
