import { Head, Link, useForm, usePage } from "@inertiajs/react";
import {
    ArrowLeft,
    ArrowUpRight,
    CheckCircle2,
    ShieldCheck,
} from "lucide-react";
import { Logo } from "@/components/Brand";
import { Button, Field } from "@/components/Common";
import type { Shared } from "@/lib/types";

export default function RequestDealership() {
    const { flash, company } = usePage<Shared>().props;
    const form = useForm({
        name: "",
        email: "",
        mobile: "",
        business_name: "",
        business_address: "",
        level: "District",
        division: "Dhaka",
        district: "",
        upazila: "",
        union_name: "",
        message: "",
        consent: false,
    });
    const field = (
        key:
            | "name"
            | "email"
            | "mobile"
            | "business_name"
            | "district"
            | "upazila"
            | "union_name",
        label: string,
        type = "text",
        maxLength = 100,
    ) => (
        <Field label={label} error={form.errors[key]}>
            <input
                type={type}
                required
                maxLength={maxLength}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                autoComplete={
                    key === "name"
                        ? "name"
                        : key === "email"
                          ? "email"
                          : key === "mobile"
                            ? "tel"
                            : "off"
                }
                placeholder={key === "mobile" ? "+8801XXXXXXXXX" : undefined}
            />
        </Field>
    );
    return (
        <div className="public-page landing request-page">
            <Head title="Request dealership" />
            <header className="landing-header">
                <Link href="/" aria-label="Nexora home">
                    <Logo />
                </Link>
                <Link href="/login" className="landing-signin">
                    Already a dealer? Sign in <ArrowUpRight size={17} />
                </Link>
            </header>
            <main className="request-layout">
                <aside className="request-intro">
                    <Link href="/" className="text-link">
                        <ArrowLeft size={15} /> Back to home
                    </Link>
                    <span className="editorial-label">
                        LET’S START A CONVERSATION
                    </span>
                    <h1>
                        Your next chapter
                        <br />
                        starts <em>here.</em>
                    </h1>
                    <p>
                        Tell {company.company_name} about your business and the
                        community you want to serve.
                    </p>
                    <ol className="request-steps">
                        <li>
                            <span>01</span>
                            <div>
                                <strong>Share your details</strong>
                                <p>
                                    Introduce yourself, your business, and your
                                    territory.
                                </p>
                            </div>
                        </li>
                        <li>
                            <span>02</span>
                            <div>
                                <strong>The company reviews</strong>
                                <p>
                                    Your information goes directly to their
                                    private dashboard.
                                </p>
                            </div>
                        </li>
                        <li>
                            <span>03</span>
                            <div>
                                <strong>Connect and get started</strong>
                                <p>
                                    The company contacts you and creates your
                                    dealer account after approval.
                                </p>
                            </div>
                        </li>
                    </ol>
                    <div className="request-assurance">
                        <ShieldCheck size={20} />
                        <p>
                            Your information is shared with the company for
                            reviewing your dealership request.
                        </p>
                    </div>
                </aside>
                <section
                    className="request-form-panel"
                    aria-label="Dealership request"
                >
                    {flash.success ? (
                        <div className="request-success" role="status">
                            <CheckCircle2 size={38} />
                            <span className="editorial-label">
                                YOUR REQUEST IS IN
                            </span>
                            <h2>
                                A new connection.
                                <br />A next step.
                            </h2>
                            <p>{flash.success}</p>
                            <Button asChild>
                                <Link href="/">
                                    Back to home <ArrowUpRight size={17} />
                                </Link>
                            </Button>
                            <button
                                className="text-link"
                                onClick={() => {
                                    form.reset();
                                    window.location.assign(
                                        "/request-dealership",
                                    );
                                }}
                            >
                                Submit another request
                            </button>
                        </div>
                    ) : (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post("/request-dealership", {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <div className="request-form-heading">
                                <span className="editorial-label">
                                    DEALERSHIP ENQUIRY
                                </span>
                                <h2>Introduce your business.</h2>
                                <p>
                                    All fields are required except the optional
                                    message.
                                </p>
                            </div>
                            <div className="form-grid">
                                {field("name", "Full name", "text", 120)}
                                {field("email", "Email address", "email", 160)}
                                {field("mobile", "Mobile number", "tel", 20)}
                                {field(
                                    "business_name",
                                    "Business name",
                                    "text",
                                    160,
                                )}
                                <Field
                                    label="Dealer level"
                                    error={form.errors.level}
                                >
                                    <select
                                        value={form.data.level}
                                        onChange={(event) =>
                                            form.setData({
                                                ...form.data,
                                                level: event.target.value,
                                                district: "",
                                                upazila: "",
                                                union_name: "",
                                            })
                                        }
                                    >
                                        {[
                                            "Divisional",
                                            "District",
                                            "Upazila / Thana",
                                            "Union",
                                        ].map((level) => (
                                            <option key={level}>{level}</option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    label="Division"
                                    error={form.errors.division}
                                >
                                    <select
                                        value={form.data.division}
                                        onChange={(event) =>
                                            form.setData(
                                                "division",
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {[
                                            "Dhaka",
                                            "Chattogram",
                                            "Rajshahi",
                                            "Khulna",
                                            "Barishal",
                                            "Sylhet",
                                            "Rangpur",
                                            "Mymensingh",
                                        ].map((division) => (
                                            <option key={division}>
                                                {division}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                {form.data.level !== "Divisional" &&
                                    field("district", "District")}
                                {["Upazila / Thana", "Union"].includes(
                                    form.data.level,
                                ) && field("upazila", "Upazila / Thana")}
                                {form.data.level === "Union" &&
                                    field("union_name", "Union")}
                            </div>
                            <Field
                                label="Business address"
                                error={form.errors.business_address}
                            >
                                <textarea
                                    required
                                    maxLength={500}
                                    rows={3}
                                    value={form.data.business_address}
                                    onChange={(event) =>
                                        form.setData(
                                            "business_address",
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Message (optional)"
                                error={form.errors.message}
                            >
                                <textarea
                                    maxLength={1500}
                                    rows={3}
                                    value={form.data.message}
                                    onChange={(event) =>
                                        form.setData(
                                            "message",
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Tell us about your experience or ask a question."
                                />
                            </Field>
                            <label className="request-consent">
                                <input
                                    type="checkbox"
                                    required
                                    checked={form.data.consent}
                                    onChange={(event) =>
                                        form.setData(
                                            "consent",
                                            event.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    I agree to share these details with the
                                    company and be contacted about my dealership
                                    request.
                                </span>
                            </label>
                            {form.errors.consent && (
                                <p className="field-error">
                                    {form.errors.consent}
                                </p>
                            )}
                            <Button disabled={form.processing} type="submit">
                                {form.processing
                                    ? "Sending your request…"
                                    : "Send dealership request"}
                                <ArrowUpRight size={18} />
                            </Button>
                            <p className="request-form-note">
                                An account is created by the company after
                                review.
                            </p>
                        </form>
                    )}
                </section>
            </main>
        </div>
    );
}
