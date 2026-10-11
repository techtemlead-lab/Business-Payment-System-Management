import { useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    ArrowDown,
    ArrowUpRight,
    Building2,
    Check,
    ChevronDown,
    Mail,
    Phone,
    ShieldCheck,
    Users,
} from "lucide-react";
import { Logo } from "@/components/Brand";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/Reveal";
import { NetworkScene } from "@/components/NetworkScene";
import type { Shared } from "@/lib/types";

const approach = [
    {
        title: "People first. Paperwork second.",
        body: "Dealer contacts, territories, approvals, and account status. Keep the details in one place, so the relationship can come first.",
        tags: ["Dealer profiles", "Territory management", "Company approval"],
    },
    {
        title: "Every number has a story.",
        body: "Follow commission allocation previews and recorded ledger activity without losing the context. Clear rules make for clearer conversations.",
        tags: ["Allocation previews", "Traceable records", "BDT precision"],
    },
    {
        title: "A little trust. A lot of care.",
        body: "The company manages the network. Each dealer sees their own workspace. Account protection and an audit history keep everyone accountable.",
        tags: [
            "Private workspaces",
            "Two-factor authentication",
            "Audit history",
        ],
    },
];

export default function Welcome() {
    const { company, demo } = usePage<Shared>().props;
    const [open, setOpen] = useState(0);
    const [workspace, setWorkspace] = useState<"company" | "dealer">("company");
    const [mobile, setMobile] = useState(false);
    return (
        <>
            <Head title="Good business starts with connection." />
            <div className="public-page landing">
                <a className="skip-link" href="#main">
                    Skip to content
                </a>
                <header className="landing-header">
                    <Link href="/" aria-label="Nexora home">
                        <Logo />
                    </Link>
                    <nav
                        className="landing-desktop-nav"
                        aria-label="Website navigation"
                    >
                        <a href="#partnerships">The way we work</a>
                        <a href="#workspaces">Your workspace</a>
                        <a href="#contact">Get in touch</a>
                        <Link href="/request-dealership">
                            Request dealership
                        </Link>
                    </nav>
                    <Link href="/login" className="landing-signin">
                        Sign in <ArrowUpRight size={17} />
                    </Link>
                    <button
                        type="button"
                        className="landing-menu"
                        aria-label="Website menu"
                        aria-expanded={mobile}
                        aria-controls="website-mobile-nav"
                        onClick={() => setMobile(!mobile)}
                    >
                        Menu <ChevronDown size={15} />
                    </button>
                    {mobile && (
                        <nav
                            id="website-mobile-nav"
                            className="landing-mobile-nav"
                            aria-label="Mobile website navigation"
                            onKeyDown={(event) => {
                                if (event.key === "Escape") {
                                    setMobile(false);
                                    document
                                        .querySelector<HTMLButtonElement>(
                                            ".landing-menu",
                                        )
                                        ?.focus();
                                }
                            }}
                        >
                            <a
                                href="#partnerships"
                                onClick={() => setMobile(false)}
                            >
                                The way we work <ArrowUpRight size={17} />
                            </a>
                            <a
                                href="#workspaces"
                                onClick={() => setMobile(false)}
                            >
                                Your workspace <ArrowUpRight size={17} />
                            </a>
                            <a href="#contact" onClick={() => setMobile(false)}>
                                Get in touch <ArrowUpRight size={17} />
                            </a>
                            <Link href="/request-dealership">
                                Request dealership <ArrowUpRight size={17} />
                            </Link>
                        </nav>
                    )}
                </header>
                <main id="main">
                    <section className="landing-hero">
                        <div className="landing-hero-copy">
                            <span className="editorial-label">
                                <i /> PEOPLE. PARTNERSHIPS. POSSIBILITIES.
                            </span>
                            <h1>
                                <span>Good business</span>
                                <span>starts with</span>
                                <em>connection.</em>
                            </h1>
                            <p>
                                A home for your dealer network. Bring the
                                people, the details, and the bigger picture
                                together.
                            </p>
                            <div className="landing-hero-actions">
                                <Button asChild>
                                    <Link href="/login">
                                        Enter your workspace{" "}
                                        <ArrowUpRight size={18} />
                                    </Link>
                                </Button>
                                <Link
                                    href="/request-dealership"
                                    className="landing-discover"
                                >
                                    Request dealership{" "}
                                    <ArrowUpRight size={16} />
                                </Link>
                            </div>
                            <div className="hero-footnote">
                                <span className="footnote-mark">
                                    <ShieldCheck size={17} />
                                </span>
                                <span>
                                    Company-led. Dealer-focused.
                                    <br />
                                    <strong>
                                        Built for your business in Bangladesh.
                                    </strong>
                                </span>
                            </div>
                        </div>
                        <Reveal className="landing-hero-art" delay={160}>
                            <NetworkScene />
                        </Reveal>
                    </section>
                    <div className="landing-facts">
                        <div>
                            <strong>
                                04<span> / levels</span>
                            </strong>
                            <p>From division to community.</p>
                        </div>
                        <div>
                            <strong>
                                01<span> / connected workspace</span>
                            </strong>
                            <p>A shared view. A clear direction.</p>
                        </div>
                        <div className="facts-principle">
                            <span className="facts-cross" aria-hidden="true">
                                ✳
                            </span>
                            <p>
                                Good partnerships need
                                <br />
                                <strong>more than a spreadsheet.</strong>
                            </p>
                        </div>
                    </div>
                    <section className="landing-approach" id="partnerships">
                        <Reveal className="section-intro">
                            <span className="editorial-label">
                                01 / THE WAY WE WORK
                            </span>
                            <h2>
                                Less friction.
                                <br />
                                <em>More forward.</em>
                            </h2>
                            <p>
                                Business is personal. Your workspace should make
                                room for that.
                            </p>
                            <a href="#workspaces" className="underlined-link">
                                Find your place <ArrowUpRight size={17} />
                            </a>
                        </Reveal>
                        <Reveal className="approach-editorial" delay={100}>
                            {approach.map((item, i) => (
                                <div
                                    className={`approach-item ${open === i ? "approach-open" : ""}`}
                                    key={item.title}
                                >
                                    <h3>
                                        <button
                                            type="button"
                                            aria-expanded={open === i}
                                            aria-controls={`approach-panel-${i}`}
                                            onClick={() =>
                                                setOpen(open === i ? -1 : i)
                                            }
                                        >
                                            <span className="approach-index">
                                                0{i + 1}
                                            </span>
                                            <span>{item.title}</span>
                                            <span
                                                className="approach-toggle"
                                                aria-hidden="true"
                                            >
                                                {open === i ? "−" : "+"}
                                            </span>
                                        </button>
                                    </h3>
                                    <div
                                        id={`approach-panel-${i}`}
                                        className="approach-panel"
                                        hidden={open !== i}
                                    >
                                        <p>{item.body}</p>
                                        <div className="approach-tags">
                                            {item.tags.map((tag) => (
                                                <span key={tag}>
                                                    <Check size={12} />
                                                    {tag}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            <div className="approach-signature">
                                <span className="signature-line" />
                                <span>One network. A little more human.</span>
                            </div>
                        </Reveal>
                    </section>
                    <section className="workspace-section" id="workspaces">
                        <Reveal className="workspace-intro">
                            <span className="editorial-label">
                                02 / A PLACE FOR EVERY PARTNER
                            </span>
                            <h2>
                                Your role.
                                <br />
                                <em>Your workspace.</em>
                            </h2>
                            <p>
                                A company-wide view or a space of your own.
                                Everything starts with your account.
                            </p>
                            <div
                                className="workspace-selector"
                                role="group"
                                aria-label="Choose your workspace"
                            >
                                <button
                                    type="button"
                                    aria-pressed={workspace === "company"}
                                    onClick={() => setWorkspace("company")}
                                >
                                    <Building2 size={17} />
                                    For the company
                                </button>
                                <button
                                    type="button"
                                    aria-pressed={workspace === "dealer"}
                                    onClick={() => setWorkspace("dealer")}
                                >
                                    <Users size={17} />
                                    For the dealer
                                </button>
                            </div>
                            <div
                                className="workspace-description"
                                aria-live="polite"
                                aria-atomic="true"
                            >
                                <h3>
                                    {workspace === "company"
                                        ? "See the whole picture."
                                        : "Make it your own."}
                                </h3>
                                <p>
                                    {workspace === "company"
                                        ? "Manage dealer profiles, review approvals, explore territories, and keep track of company records."
                                        : "Find your profile, review your recorded earnings, and follow payout requests from your personal portal."}
                                </p>
                            </div>
                            <Link
                                className="workspace-enter"
                                href={`/login?workspace=${workspace}`}
                            >
                                Sign in as{" "}
                                {workspace === "company" ? "company" : "dealer"}
                                <ArrowUpRight size={20} />
                            </Link>
                        </Reveal>
                        <Reveal className="workspace-preview" delay={140}>
                            <div
                                className="preview-window"
                                aria-label={`${workspace === "company" ? "Company" : "Dealer"} workspace illustration`}
                            >
                                <div className="preview-window-bar">
                                    <span className="window-dots">
                                        <i />
                                        <i />
                                        <i />
                                    </span>
                                    <span>
                                        NEXORA / {workspace.toUpperCase()}
                                    </span>
                                    <ShieldCheck size={15} />
                                </div>
                                <div className="preview-window-body">
                                    <div className="preview-monogram">
                                        n<span>.</span>
                                    </div>
                                    <span className="editorial-label">
                                        {workspace === "company"
                                            ? "THE BIGGER PICTURE"
                                            : "YOUR CORNER OF THE NETWORK"}
                                    </span>
                                    <h3>
                                        {workspace === "company"
                                            ? "Everything connected."
                                            : "Everything you need."}
                                    </h3>
                                    <p>
                                        {workspace === "company"
                                            ? "People, territories, and the details that matter."
                                            : "Your profile. Your records. Your progress."}
                                    </p>
                                    <div className="preview-lines">
                                        {(workspace === "company"
                                            ? [
                                                  "Dealer relationships",
                                                  "Network & territories",
                                                  "Commission previews",
                                              ]
                                            : [
                                                  "My dealer profile",
                                                  "My earnings",
                                                  "My payout requests",
                                              ]
                                        ).map((label, i) => (
                                            <div key={label}>
                                                <span>0{i + 1}</span>
                                                <strong>{label}</strong>
                                                <ArrowUpRight size={16} />
                                            </div>
                                        ))}
                                    </div>
                                    <div className="preview-window-note">
                                        <i />
                                        WORKSPACE ILLUSTRATION
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                    </section>
                    {demo && (
                        <section className="landing-demo">
                            <div>
                                <span className="editorial-label">
                                    LOCAL PREVIEW
                                </span>
                                <h2>A look around, before you begin.</h2>
                                <p>
                                    These sample workspaces open without a
                                    password. Your own accounts use email and
                                    password.
                                </p>
                            </div>
                            <div className="landing-demo-actions">
                                <Button
                                    onClick={() =>
                                        router.post("/demo-login", {
                                            role: "admin",
                                        })
                                    }
                                >
                                    Explore admin workspace{" "}
                                    <ArrowUpRight size={16} />
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post("/demo-login", {
                                            role: "dealer",
                                        })
                                    }
                                >
                                    Explore dealer portal{" "}
                                    <ArrowUpRight size={16} />
                                </Button>
                            </div>
                        </section>
                    )}
                    <section className="landing-contact" id="contact">
                        <Reveal>
                            <span className="editorial-label">
                                03 / LET'S TALK BUSINESS
                            </span>
                            <h2>
                                The next connection
                                <br />
                                <em>starts with you.</em>
                            </h2>
                            <p>
                                Speak to your company representative about
                                joining the network. Dealer accounts are created
                                after company review.
                            </p>
                        </Reveal>
                        <Reveal className="contact-details" delay={100}>
                            {company.contact_email && (
                                <a href={`mailto:${company.contact_email}`}>
                                    <Mail size={20} />
                                    <span>{company.contact_email}</span>
                                    <ArrowUpRight size={17} />
                                </a>
                            )}
                            {company.contact_phone && (
                                <a href={`tel:${company.contact_phone}`}>
                                    <Phone size={20} />
                                    <span>{company.contact_phone}</span>
                                    <ArrowUpRight size={17} />
                                </a>
                            )}
                            {!company.contact_email &&
                                !company.contact_phone && (
                                    <p>
                                        Already part of the network?
                                        <br />
                                        <Link href="/login">
                                            Your workspace is ready.{" "}
                                            <ArrowUpRight size={17} />
                                        </Link>
                                    </p>
                                )}
                            <span className="contact-stamp" aria-hidden="true">
                                <span>BUILT AROUND</span>
                                <strong>people.</strong>
                                <span>CONNECTED BY NEXORA</span>
                            </span>
                        </Reveal>
                    </section>
                </main>
                <footer className="landing-footer">
                    <Link href="/" aria-label="Nexora home">
                        <Logo />
                    </Link>
                    <span>
                        © {new Date().getFullYear()} {company.company_name}
                    </span>
                    <a href="#main">
                        Back to top <ArrowUpRight size={15} />
                    </a>
                </footer>
            </div>
        </>
    );
}
