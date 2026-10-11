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
export default function Security({
    setupSecret,
    recoveryCodes,
}: {
    setupSecret: string | null;
    recoveryCodes: string[] | null;
}) {
    const { auth } = usePage<Shared>().props;
    const begin = useForm({ password: "" });
    const enable = useForm({ code: "" });
    return (
        <Layout title="Account security">
            <PageHeading
                eyebrow="PROTECT YOUR PARTNERSHIP"
                title="Account security"
                description="Add a second layer of protection with an authenticator app."
            />
            <Card className="security-card">
                <CardHeading
                    title="Two-factor authentication"
                    subtitle="Required for company administrators in production."
                    action={<ShieldCheck size={23} />}
                />
                {auth.user?.mfa_enabled ? (
                    <div className="security-enabled">
                        <span>
                            <CheckCircle2 size={32} />
                        </span>
                        <h2>Your account has extra protection</h2>
                        <p>
                            Use your authenticator app when signing in. Keep
                            recovery codes somewhere safe.
                        </p>
                    </div>
                ) : setupSecret ? (
                    <form
                        className="card-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            enable.post("/security/enable");
                        }}
                    >
                        <Notice>
                            Open your authenticator app and add a time-based
                            account manually using this setup key. Keep this key
                            private.
                        </Notice>
                        <Field label="Private setup key">
                            <input
                                value={setupSecret}
                                readOnly
                                className="version-code"
                            />
                        </Field>
                        <Field
                            label="6-digit authenticator code"
                            error={enable.errors.code}
                        >
                            <input
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                value={enable.data.code}
                                onChange={(e) =>
                                    enable.setData("code", e.target.value)
                                }
                            />
                        </Field>
                        <Button disabled={enable.processing}>
                            Enable two-factor authentication
                        </Button>
                    </form>
                ) : (
                    <form
                        className="card-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            begin.post("/security/setup");
                        }}
                    >
                        <p>
                            Use an authenticator app such as Google
                            Authenticator, Microsoft Authenticator, or
                            1Password.
                        </p>
                        <Field
                            label="Confirm your password"
                            error={begin.errors.password}
                        >
                            <input
                                type="password"
                                autoComplete="current-password"
                                value={begin.data.password}
                                onChange={(e) =>
                                    begin.setData("password", e.target.value)
                                }
                            />
                        </Field>
                        <Button disabled={begin.processing}>
                            Set up authenticator <ShieldCheck size={17} />
                        </Button>
                    </form>
                )}
                {recoveryCodes && (
                    <div className="recovery-box">
                        <Notice tone="warning">
                            Save these recovery codes now. Each code works once
                            and will not be shown again.
                        </Notice>
                        <div>
                            {recoveryCodes.map((c) => (
                                <code key={c}>{c}</code>
                            ))}
                        </div>
                    </div>
                )}
            </Card>
        </Layout>
    );
}
