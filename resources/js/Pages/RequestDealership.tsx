import { useState } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import { ArrowLeft, ArrowUpRight, CheckCircle2 } from "lucide-react";
import { Logo } from "@/components/Brand";
import { Button, Field } from "@/components/Common";
import { ApplicationFields } from "@/components/ApplicationFields";
import { localized, siteStyle } from "@/lib/website";
import type { Shared } from "@/lib/types";
export default function RequestDealership() {
    const { website, flash } = usePage<Shared>().props;
    const [language, setLanguage] = useState(website.languages.default);
    const d = localized(website, language);
    const form = useForm({
        name: "",
        email: "",
        mobile: "",
        business_name: "",
        business_address: "",
        dealer_type: "dealer",
        level: "District",
        division: "Dhaka",
        district: "",
        upazila: "",
        union_name: "",
        message: "",
        consent: false,
        language: website.languages.default,
        additional_answers: {} as Record<string, string>,
        referral_code:
            new URLSearchParams(usePage().url.split("?")[1])
                .get("ref")
                ?.toUpperCase() ?? "",
    });
    return (
        <div
            className="site-root public-page landing request-page"
            style={siteStyle(d)}
            lang={language}
            data-motion={d.theme.motion}
        >
            <Head title="Request dealership" />
            <header className="landing-header">
                <Link href="/" aria-label={`${d.brand.name} home`}>
                    <Logo />
                </Link>
                {d.languages.switcher && (
                    <button
                        onClick={() => {
                            const next = language === "en" ? "bn" : "en";
                            setLanguage(next);
                            form.setData("language", next);
                        }}
                    >
                        {language === "en" ? "বাংলা" : "English"}
                    </button>
                )}
                <Link href="/login" className="landing-signin">
                    Already a dealer? Sign in <ArrowUpRight size={17} />
                </Link>
            </header>
            <main className="request-layout">
                <aside className="request-intro">
                    <Link href="/" className="text-link">
                        <ArrowLeft size={15} /> Back to home
                    </Link>
                    <span className="editorial-label">DEALERSHIP ENQUIRY</span>
                    <h1>{d.application.title}</h1>
                    <p>{d.application.intro}</p>
                    <ol className="request-steps">
                        {[
                            "Share your details",
                            "The company reviews",
                            "Connect and get started",
                        ].map((step, index) => (
                            <li key={step}>
                                <span>0{index + 1}</span>
                                <strong>{step}</strong>
                            </li>
                        ))}
                    </ol>
                </aside>
                <section
                    className="request-form-panel"
                    aria-label="Dealership request"
                >
                    {!d.application.open ? (
                        <div role="status">
                            <h2>Applications closed</h2>
                            <p>{d.application.closed_message}</p>
                        </div>
                    ) : flash.success ? (
                        <div className="request-success" role="status">
                            <CheckCircle2 size={38} />
                            <h2>Your request is in.</h2>
                            <p>{flash.success}</p>
                            <Button asChild>
                                <Link href="/">Back to home</Link>
                            </Button>
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
                                <h2>Introduce your business.</h2>
                                <p>
                                    Required identity and territory information
                                    helps the company review your application.
                                </p>
                            </div>
                            <ApplicationFields
                                document={d}
                                values={form.data}
                                errors={form.errors}
                                onChange={(key, value) => {
                                    form.setData(
                                        key as keyof typeof form.data,
                                        value,
                                    );
                                }}
                            />
                            <Field
                                label="Referral code (optional)"
                                hint="The dealer who referred you. The company will review this connection."
                                error={form.errors.referral_code}
                            >
                                <input
                                    maxLength={30}
                                    value={form.data.referral_code}
                                    onChange={(event) =>
                                        form.setData(
                                            "referral_code",
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </Field>
                            <Button disabled={form.processing}>
                                {form.processing
                                    ? "Sending…"
                                    : d.application.button_label}{" "}
                                <ArrowUpRight size={17} />
                            </Button>
                        </form>
                    )}
                </section>
            </main>
        </div>
    );
}
