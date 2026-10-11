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
export default function ForgotPassword() {
    const form = useForm({ email: "" });
    return (
        <AuthShell
            title="Let's get you back in"
            subtitle="Enter your account email to request a password reset."
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/forgot-password");
                }}
            >
                <Field label="Email address" error={form.errors.email}>
                    <input
                        type="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData("email", e.target.value)}
                    />
                </Field>
                <Button className="full-width" disabled={form.processing}>
                    Send reset link <Mail size={17} />
                </Button>
            </form>
            <Link className="auth-return" href="/login">
                Back to sign in
            </Link>
        </AuthShell>
    );
}
