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
export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const form = useForm({
        token,
        email: email ?? "",
        password: "",
        password_confirmation: "",
    });
    return (
        <AuthShell
            title="A fresh start"
            subtitle="Choose a strong new password for your account."
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/reset-password");
                }}
            >
                <Field label="Account email" error={form.errors.email}>
                    <input
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData("email", e.target.value)}
                    />
                </Field>
                <Field label="New password" error={form.errors.password}>
                    <input
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData("password", e.target.value)
                        }
                    />
                </Field>
                <Field label="Confirm password">
                    <input
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                "password_confirmation",
                                e.target.value,
                            )
                        }
                    />
                </Field>
                <p className="field-hint">
                    12+ characters, including uppercase, lowercase, number and
                    symbol.
                </p>
                <Button className="full-width" disabled={form.processing}>
                    Reset password
                </Button>
            </form>
        </AuthShell>
    );
}
