import { useState } from "react";
import { Link, router, useForm, usePage } from "@inertiajs/react";
import {
    Users,
    Plus,
    ArrowUpRight,
    ArrowRight,
    CircleDollarSign,
    Wallet,
    Gift,
    Search,
    Download,
    CalendarDays,
    MoreHorizontal,
    MapPin,
    ShieldCheck,
    Check,
    CheckCircle2,
    LockKeyhole,
    Network,
    Crown,
    Settings2,
    CircleCheck,
    Info,
    FileText,
    ArrowLeft,
    Mail,
    Building2,
    Phone,
    FlaskConical,
    SlidersHorizontal,
    ScrollText,
    Activity,
    AlertCircle,
    ChevronRight,
    Layers,
    ExternalLink,
    Clock3,
} from "lucide-react";
import { Layout, CreateDealerButton } from "@/components/Layout";
import { DealerForm } from "@/components/DealerForm";
import { Allocation } from "@/components/Allocation";
import {
    Card,
    CardHeading,
    PageHeading,
    Badge,
    Empty,
    Button,
    ArrowLink,
    Notice,
    Field,
    Pagination,
    Modal,
    ConfirmAction,
} from "@/components/Common";
import {
    type Shared,
    type Dealer,
    type Preview,
    type Page,
    type Audit,
    type Balances,
    type Entry,
    money,
    date,
    actionLabel,
} from "@/lib/types";
import {
    levelShort,
    DealerTable,
    BalanceCards,
    Stat,
} from "@/components/WorkspaceComponents";
export default function Dashboard({
    stats,
    dealers,
    levels,
    activities,
    preview,
    rankReady,
}: {
    stats: {
        dealers: number;
        active: number;
        pending: number;
        earned_minor: number;
        payouts: number;
    };
    dealers: Dealer[];
    levels: { name: string; count: number }[];
    activities: Audit[];
    preview: Preview;
    rankReady: boolean;
}) {
    const { auth, company } = usePage<Shared>().props;
    const [create, setCreate] = useState(false);
    return (
        <Layout title="Overview">
            <PageHeading
                eyebrow="YOUR NETWORK, IN FOCUS"
                title={`Welcome back, ${auth.user!.name.split(" ")[0]}.`}
                description="A clearer picture of your partnerships, commissions, and what's next."
                action={
                    <div className="heading-actions">
                        <span className="date-chip">
                            <CalendarDays size={16} />
                            {new Date().toLocaleDateString("en-GB", {
                                day: "numeric",
                                month: "short",
                                year: "numeric",
                                timeZone: "Asia/Dhaka",
                            })}
                        </span>
                        <CreateDealerButton onClick={() => setCreate(true)} />
                    </div>
                }
            />
            <div className="welcome-banner">
                <div>
                    <span className="banner-eyebrow">
                        <span /> COMPANY OPERATING MODE
                    </span>
                    <h2>
                        {company.mode === "COMMISSION_BASED"
                            ? "Commission-based. Partnership-focused."
                            : "Rank-based. Partnership-focused."}
                    </h2>
                    <p>
                        One active mode. Clear rules. A stronger dealer network.
                    </p>
                    <Link href="/operating-mode">
                        Manage operating mode <ArrowUpRight size={15} />
                    </Link>
                </div>
                <div className="banner-art" aria-hidden="true">
                    <div className="orbit orbit-one" />
                    <div className="orbit orbit-two" />
                    <span className="art-node art-node-one">
                        <Users size={21} />
                    </span>
                    <span className="art-node art-node-two">
                        <Gift size={20} />
                    </span>
                    <span className="art-node art-node-three">
                        <ShieldCheck size={20} />
                    </span>
                    <span className="art-core">
                        <Network size={40} />
                    </span>
                    <div className="banner-mode">
                        <span />
                        {company.mode === "COMMISSION_BASED"
                            ? "Commission mode"
                            : "Rank mode"}
                        <Check size={12} />
                    </div>
                </div>
            </div>
            <div className="stats-grid">
                <Stat
                    icon={<Users />}
                    label="Total dealers"
                    value={String(stats.dealers).padStart(2, "0")}
                    note={`${stats.active} active accounts`}
                    accent
                />
                <Stat
                    icon={<CircleDollarSign />}
                    label="Total commissions earned"
                    value={money(stats.earned_minor)}
                    note="From validated ledger entries"
                />
                <Stat
                    icon={<Wallet />}
                    label="Payouts awaiting review"
                    value={String(stats.payouts).padStart(2, "0")}
                    note="Pending or requiring reconciliation"
                />
                <Stat
                    icon={<Gift />}
                    label="Unallocated reward pool"
                    value="60%"
                    note="Confirmed model · simulation"
                />
            </div>
            <div className="overview-main">
                <Allocation preview={preview} />
                <Card className="network-card">
                    <CardHeading
                        title="A network with reach"
                        subtitle="Your dealers, across every level."
                        action={<Network size={19} className="muted" />}
                    />
                    <div className="network-total">
                        <strong>{stats.dealers}</strong>
                        <span>
                            dealer partnerships
                            <br />
                            and counting
                        </span>
                        <span className="network-total-icon">
                            <Users size={24} />
                        </span>
                    </div>
                    <div className="level-bars">
                        {levels.map((l, i) => (
                            <div key={l.name} className="level-row">
                                <div>
                                    <span>
                                        <span
                                            className={`level-color level-color-${i}`}
                                        />
                                        {levelShort(l.name)}
                                    </span>
                                    <strong>
                                        {l.count.toString().padStart(2, "0")}
                                    </strong>
                                </div>
                                <div className="level-track">
                                    <span
                                        className={`level-fill level-fill-${i}`}
                                        style={{
                                            width: `${stats.dealers ? (l.count / stats.dealers) * 100 : 0}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="network-bottom">
                        <span>
                            <MapPin size={14} /> Bangladesh dealer network
                        </span>
                        <ArrowLink href="/hierarchy">Explore</ArrowLink>
                    </div>
                </Card>
            </div>
            <div className="overview-bottom">
                <Card className="recent-dealers">
                    <CardHeading
                        title="Your dealer network"
                        subtitle="The latest partners in your workspace."
                        action={
                            <ArrowLink href="/dealers">
                                View all dealers
                            </ArrowLink>
                        }
                    />
                    <DealerTable dealers={dealers} compact />
                </Card>
                <Card className="next-steps-card">
                    <CardHeading
                        title="A little attention goes a long way"
                        subtitle="Keep your workspace ready for growth."
                    />
                    <Link href="/dealers?status=pending" className="task-row">
                        <span className="task-icon task-amber">
                            <Users size={19} />
                        </span>
                        <div>
                            <strong>Review new dealers</strong>
                            <p>
                                {stats.pending} account
                                {stats.pending !== 1 ? "s" : ""} waiting for
                                activation
                            </p>
                        </div>
                        <ChevronRight size={17} />
                    </Link>
                    <Link href="/rules" className="task-row">
                        <span className="task-icon">
                            <SlidersHorizontal size={19} />
                        </span>
                        <div>
                            <strong>Complete financial rules</strong>
                            <p>Dealer distribution needs approval</p>
                        </div>
                        <ChevronRight size={17} />
                    </Link>
                    <Link href="/security" className="task-row">
                        <span className="task-icon task-purple">
                            <ShieldCheck size={19} />
                        </span>
                        <div>
                            <strong>Protect your account</strong>
                            <p>
                                {auth.user?.mfa_enabled
                                    ? "Two-factor authentication enabled"
                                    : "Enable two-factor authentication"}
                            </p>
                        </div>
                        <ChevronRight size={17} />
                    </Link>
                    <div className="quiet-note">
                        <Info size={14} />
                        <span>
                            Financial posting stays inactive until required
                            business rules are approved.
                        </span>
                    </div>
                </Card>
            </div>
            <div className="activity-strip">
                <span className="activity-icon">
                    <Activity size={17} />
                </span>
                <strong>Latest activity</strong>
                <span>
                    {activities[0]
                        ? actionLabel(activities[0].action)
                        : "Your audit trail will record workspace changes here."}
                </span>
                <ArrowLink href="/audit">Audit trail</ArrowLink>
            </div>
            <DealerForm open={create} onOpenChange={setCreate} />
        </Layout>
    );
}
