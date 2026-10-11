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
export default function Welcome() {
    const { company, demo } = usePage<Shared>().props;
    return (
        <>
            <Head title="Better partnerships. Clearer rewards." />
            <div className="public-page">
                <header className="public-header">
                    <Link href="/" aria-label="Nexora home">
                        <Logo />
                    </Link>
                    <nav>
                        <a href="#partnerships">Our approach</a>
                        <a href="#contact">Contact</a>
                        <Button asChild variant="outline">
                            <Link href="/login">
                                Workspace sign in <ArrowUpRight size={15} />
                            </Link>
                        </Button>
                    </nav>
                </header>
                <main>
                    <section className="public-hero">
                        <div className="hero-copy">
                            <span className="public-eyebrow">
                                <span /> BUILT AROUND BETTER PARTNERSHIPS
                            </span>
                            <h1>
                                A stronger network.
                                <br />A <em>clearer</em> future.
                            </h1>
                            <p>
                                Bring your dealer relationships, commissions,
                                and rewards together in one thoughtfully
                                connected workspace.
                            </p>
                            <div className="hero-actions">
                                <Button asChild>
                                    <Link href="/login">
                                        Enter your workspace{" "}
                                        <ArrowRight size={17} />
                                    </Link>
                                </Button>
                                <a href="#partnerships" className="text-link">
                                    Discover our approach{" "}
                                    <ArrowUpRight size={16} />
                                </a>
                            </div>
                            <div className="hero-assurance">
                                <ShieldCheck size={17} />
                                <span>
                                    Private accounts. Transparent records.
                                    Trusted partnerships.
                                </span>
                            </div>
                        </div>
                        <div className="hero-visual">
                            <span className="visual-caption">
                                <span /> THE POWER OF CONNECTION
                            </span>
                            <div className="network-illustration">
                                <svg viewBox="0 0 500 400" aria-hidden="true">
                                    <defs>
                                        <radialGradient id="halo">
                                            <stop
                                                offset="0%"
                                                stopColor="#c9dcb1"
                                                stopOpacity=".7"
                                            />
                                            <stop
                                                offset="100%"
                                                stopColor="#c9dcb1"
                                                stopOpacity="0"
                                            />
                                        </radialGradient>
                                    </defs>
                                    <circle
                                        cx="250"
                                        cy="205"
                                        r="180"
                                        fill="url(#halo)"
                                    />
                                    <circle
                                        cx="250"
                                        cy="205"
                                        r="142"
                                        fill="none"
                                        stroke="#cfdbc5"
                                        strokeDasharray="4 7"
                                    />
                                    <circle
                                        cx="250"
                                        cy="205"
                                        r="94"
                                        fill="none"
                                        stroke="#d9e2d3"
                                    />
                                    <path
                                        d="M250 205L130 105M250 205L375 120M250 205L140 310M250 205L365 295"
                                        stroke="#89a783"
                                        strokeWidth="1.5"
                                    />
                                    <circle
                                        cx="130"
                                        cy="105"
                                        r="5"
                                        fill="#174d3b"
                                    />
                                    <circle
                                        cx="375"
                                        cy="120"
                                        r="5"
                                        fill="#174d3b"
                                    />
                                    <circle
                                        cx="140"
                                        cy="310"
                                        r="5"
                                        fill="#174d3b"
                                    />
                                    <circle
                                        cx="365"
                                        cy="295"
                                        r="5"
                                        fill="#174d3b"
                                    />
                                </svg>
                                <span className="illustration-core">
                                    <Network size={48} />
                                </span>
                                <div className="partner-node node-a">
                                    <span>
                                        <Building2 size={20} />
                                    </span>
                                    <div>
                                        <strong>Divisional</strong>
                                        <small>Shared direction</small>
                                    </div>
                                    <Check size={14} />
                                </div>
                                <div className="partner-node node-b">
                                    <span>
                                        <Users size={20} />
                                    </span>
                                    <div>
                                        <strong>District</strong>
                                        <small>Stronger connections</small>
                                    </div>
                                    <Check size={14} />
                                </div>
                                <div className="partner-node node-c">
                                    <span>
                                        <Globe2 size={20} />
                                    </span>
                                    <div>
                                        <strong>Upazila / Thana</strong>
                                        <small>Local expertise</small>
                                    </div>
                                    <Check size={14} />
                                </div>
                                <div className="partner-node node-d">
                                    <span>
                                        <ShieldCheck size={20} />
                                    </span>
                                    <div>
                                        <strong>Union</strong>
                                        <small>Community reach</small>
                                    </div>
                                    <Check size={14} />
                                </div>
                            </div>
                            <div className="visual-footer">
                                <span>Four dealer levels.</span>
                                <strong>One connected workspace.</strong>
                                <ArrowUpRight size={19} />
                            </div>
                        </div>
                    </section>
                    <div className="public-pillars">
                        <span>
                            <Users size={19} /> People-first relationships
                        </span>
                        <span>
                            <CircleDollarSign size={19} /> Clear commission
                            records
                        </span>
                        <span>
                            <ShieldCheck size={19} /> Privacy at every level
                        </span>
                    </div>
                    <section className="approach-section" id="partnerships">
                        <div>
                            <span className="eyebrow">
                                A THOUGHTFUL WAY FORWARD
                            </span>
                            <h2>
                                More clarity.
                                <br />
                                Better collaboration.
                            </h2>
                            <p>
                                A dedicated workspace for the company and its
                                authorized dealer network.
                            </p>
                        </div>
                        <div className="approach-cards">
                            {[
                                {
                                    icon: Network,
                                    n: "01",
                                    title: "Connected relationships",
                                    body: "Manage dealer profiles and approved territories across all four dealer levels.",
                                },
                                {
                                    icon: CircleDollarSign,
                                    n: "02",
                                    title: "Transparent rewards",
                                    body: "Understand commissions and reward allocations with clear, traceable records.",
                                },
                                {
                                    icon: ShieldCheck,
                                    n: "03",
                                    title: "Confidence in every step",
                                    body: "Private dealer access, controlled approvals, and a protected history of changes.",
                                },
                            ].map((c) => (
                                <Card key={c.n}>
                                    <span className="approach-number">
                                        {c.n}
                                    </span>
                                    <c.icon size={25} />
                                    <h3>{c.title}</h3>
                                    <p>{c.body}</p>
                                </Card>
                            ))}
                        </div>
                    </section>
                    {demo && (
                        <section className="demo-section">
                            <div>
                                <span className="eyebrow">
                                    LOCAL DEMONSTRATION
                                </span>
                                <h2>Take a look around.</h2>
                                <p>
                                    Explore with sample dealer profiles.
                                    Financial balances start at zero.
                                </p>
                            </div>
                            <div>
                                <Button
                                    onClick={() =>
                                        router.post("/demo-login", {
                                            role: "admin",
                                        })
                                    }
                                >
                                    Explore admin workspace{" "}
                                    <ArrowRight size={17} />
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
                                    <ArrowUpRight size={17} />
                                </Button>
                            </div>
                        </section>
                    )}
                    <section className="contact-section" id="contact">
                        <div>
                            <span className="eyebrow">
                                LET'S STAY CONNECTED
                            </span>
                            <h2>Partnership begins with a conversation.</h2>
                            <p>
                                Dealer accounts are created by the company after
                                review and approval.
                            </p>
                        </div>
                        <div>
                            {company.contact_email && (
                                <a href={`mailto:${company.contact_email}`}>
                                    <Mail size={19} />
                                    {company.contact_email}
                                </a>
                            )}
                            {company.contact_phone && (
                                <a href={`tel:${company.contact_phone}`}>
                                    <Phone size={19} />
                                    {company.contact_phone}
                                </a>
                            )}
                            {!company.contact_email &&
                                !company.contact_phone && (
                                    <p>
                                        Contact your company representative for
                                        account and partnership enquiries.
                                    </p>
                                )}
                        </div>
                    </section>
                </main>
                <footer className="public-footer">
                    <Logo />
                    <span>
                        © {new Date().getFullYear()} {company.company_name}. All
                        rights reserved.
                    </span>
                    <Link href="/login">
                        Authorized workspace <ArrowUpRight size={14} />
                    </Link>
                </footer>
            </div>
        </>
    );
}
