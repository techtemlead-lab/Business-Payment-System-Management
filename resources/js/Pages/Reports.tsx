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
export default function Reports({
    counts,
}: {
    counts: {
        dealers: number;
        ledger: number;
        payouts: number;
        audit: number;
        purchases: number;
        withdrawals: number;
    };
}) {
    return (
        <Layout title="Reports">
            <PageHeading
                eyebrow="THE RIGHT DATA. THE RIGHT DECISIONS."
                title="Reports & exports"
                description="Download authorized workspace records for review and reconciliation."
            />
            <div className="reports-grid">
                {[
                    {
                        key: "dealers",
                        title: "Dealer network",
                        description:
                            "Dealer codes, levels, territories, and account status.",
                        icon: Users,
                    },
                    {
                        key: "ledger",
                        title: "Financial ledger",
                        description:
                            "Validated amounts, transaction types, and rule references.",
                        icon: CircleDollarSign,
                    },
                    {
                        key: "purchases",
                        title: "Dealer purchases",
                        description:
                            "Invoice references, quantities, total MRP, actual payments, and ledger links.",
                        icon: Building2,
                    },
                    {
                        key: "withdrawals",
                        title: "Withdrawal requests",
                        description:
                            "Requested amounts, company review, and request status.",
                        icon: Wallet,
                    },
                    {
                        key: "payouts",
                        title: "Payout records",
                        description:
                            "Requests, processing status, and provider references.",
                        icon: Wallet,
                    },
                    {
                        key: "audit",
                        title: "Audit history",
                        description:
                            "Sensitive actions, actors, and recorded timestamps.",
                        icon: ShieldCheck,
                    },
                ].map((r) => (
                    <Card key={r.key} className="report-card">
                        <span className="report-icon">
                            <r.icon size={26} />
                        </span>
                        <h2>{r.title}</h2>
                        <p>{r.description}</p>
                        <div>
                            <span>
                                {counts[r.key as keyof typeof counts]} records
                            </span>
                            <Button asChild variant="outline" size="sm">
                                <a href={`/reports/export?type=${r.key}`}>
                                    <Download size={15} /> Export CSV
                                </a>
                            </Button>
                        </div>
                    </Card>
                ))}
            </div>
            <Notice>
                Exports follow the same access permissions as the workspace.
                Dealer identity documents and payment secrets are excluded.
            </Notice>
            <Card>
                <CardHeading
                    title="Reconciliation readiness"
                    subtitle="What must be complete before financial activity begins."
                />
                <div className="checklist">
                    {[
                        "Review the active commission version for future purchases.",
                        "Confirm the calculation base and eligibility for three-generation referral payouts.",
                        "Confirm reward eligibility and recipients for pool allocations.",
                        "Confirm a disbursement provider, payout limits, and approval policies.",
                    ].map((t) => (
                        <div key={t}>
                            <span className="pending-check">
                                <Clock3 size={16} />
                            </span>
                            {t}
                            <Badge status="pending">Pending decision</Badge>
                        </div>
                    ))}
                </div>
            </Card>
        </Layout>
    );
}
