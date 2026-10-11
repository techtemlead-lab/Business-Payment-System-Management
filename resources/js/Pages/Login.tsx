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
export default function Login() {
    const form = useForm({ email: "", password: "" });
    const [show, setShow] = useState(false);
    const { demo } = usePage<Shared>().props;
    return (
        <AuthShell
            title="Welcome to your workspace"
            subtitle="Sign in to manage your partnerships and progress."
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/login");
                }}
            >
                <Field label="Email address" error={form.errors.email}>
                    <input
                        type="email"
                        required
                        autoComplete="username"
                        placeholder="you@company.com"
                        value={form.data.email}
                        onChange={(e) => form.setData("email", e.target.value)}
                    />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <span className="password-field">
                        <input
                            type={show ? "text" : "password"}
                            required
                            autoComplete="current-password"
                            placeholder="Enter your password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData("password", e.target.value)
                            }
                        />
                        <button
                            type="button"
                            aria-label={
                                show ? "Hide password" : "Show password"
                            }
                            onClick={() => setShow((v) => !v)}
                        >
                            {show ? <EyeOff size={18} /> : <Eye size={18} />}
                        </button>
                    </span>
                </Field>
                <div className="forgot-link">
                    <Link href="/forgot-password">Forgot password?</Link>
                </div>
                <Button className="full-width" disabled={form.processing}>
                    {form.processing ? "Signing in..." : "Sign in to workspace"}
                    <ArrowRight size={17} />
                </Button>
            </form>
            {demo && (
                <div className="demo-login">
                    <span>Or explore the local demonstration</span>
                    <Button
                        className="full-width"
                        variant="outline"
                        onClick={() =>
                            router.post("/demo-login", { role: "admin" })
                        }
                    >
                        Explore admin workspace
                    </Button>
                    <Button
                        className="full-width"
                        variant="ghost"
                        onClick={() =>
                            router.post("/demo-login", { role: "dealer" })
                        }
                    >
                        Explore dealer portal <ArrowUpRight size={15} />
                    </Button>
                </div>
            )}
        </AuthShell>
    );
}
