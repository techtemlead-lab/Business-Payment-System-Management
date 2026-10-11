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
export default function Mode({
    ranks,
    rankReady,
}: {
    ranks: {
        id: number;
        name: string;
        eligibility: string;
        status: string;
        effect: string;
    }[];
    rankReady: boolean;
}) {
    const { company } = usePage<Shared>().props;
    const [errors, setErrors] = useState("");
    const [newRank, setNewRank] = useState(false);
    const form = useForm({ name: "", eligibility: "" });
    const switchMode = (mode: string, reason: string, password: string) =>
        router.post(
            "/operating-mode",
            { mode, revision: company.mode_revision, reason, password },
            { onError: (e) => setErrors(Object.values(e)[0]) },
        );
    return (
        <Layout title="Operating mode">
            <PageHeading
                eyebrow="ONE MODE. ONE CLEAR DIRECTION."
                title="Your operating model"
                description="Choose how your dealer network operates. Exactly one mode is active at a time."
            />
            <Notice>
                Mode changes preserve historical rules, earned balances, and
                completed payouts. Outstanding payouts or incomplete
                configuration can block a change.
            </Notice>
            {errors && <Notice tone="warning">{errors}</Notice>}
            <div className="mode-grid">
                {[
                    {
                        key: "COMMISSION_BASED",
                        title: "Commission-based",
                        Icon: CircleDollarSign,
                        description:
                            "Manage explicit commission rates and reward-pool allocations with versioned rules.",
                        points: [
                            "Approved percentages and allocations",
                            "Versioned financial rules",
                            "Rank rewards stay independent and inactive",
                        ],
                    },
                    {
                        key: "RANK_BASED",
                        title: "Rank-based",
                        Icon: Crown,
                        description:
                            "Manage company-defined ranks with explicit eligibility and approved behavior.",
                        points: [
                            "Company-configured rank definitions",
                            "Status-only ranks supported",
                            "Financial rank rewards require further rules",
                        ],
                    },
                ].map((m) => (
                    <Card
                        key={m.key}
                        className={`mode-card ${company.mode === m.key ? "mode-selected" : ""}`}
                    >
                        <div className="mode-card-top">
                            <span className="mode-icon">
                                <m.Icon size={29} />
                            </span>
                            {company.mode === m.key ? (
                                <Badge status="active">Active mode</Badge>
                            ) : (
                                <Badge status="neutral">Inactive</Badge>
                            )}
                        </div>
                        <h2>{m.title}</h2>
                        <p>{m.description}</p>
                        <ul>
                            {m.points.map((p) => (
                                <li key={p}>
                                    <Check size={16} />
                                    {p}
                                </li>
                            ))}
                        </ul>
                        {company.mode === m.key ? (
                            <div className="mode-current">
                                <CheckCircle2 size={17} /> Currently selected
                            </div>
                        ) : (
                            <ConfirmAction
                                title={`Switch to ${m.title.toLowerCase()}`}
                                description="This changes the active operating model. Historical financial entries are preserved, and undefined calculations remain inactive."
                                label={
                                    m.key === "RANK_BASED" && !rankReady
                                        ? "Configure ranks first"
                                        : "Switch to this mode"
                                }
                                disabled={m.key === "RANK_BASED" && !rankReady}
                                onConfirm={(reason, password) =>
                                    switchMode(m.key, reason, password)
                                }
                            />
                        )}
                    </Card>
                ))}
            </div>
            <Card>
                <CardHeading
                    title="Rank definitions"
                    subtitle="Configure explicit status eligibility. No financial effects are inferred."
                    action={
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setNewRank(true)}
                        >
                            <Plus size={15} /> Add rank
                        </Button>
                    }
                />
                {!ranks.length ? (
                    <Empty
                        icon={<Crown size={27} />}
                        title="Define what progress means"
                        description="Add company-approved eligibility before activating rank-based mode."
                    />
                ) : (
                    <div className="rank-list">
                        {ranks.map((r) => (
                            <div key={r.id}>
                                <span className="rank-medal">
                                    <Crown size={23} />
                                </span>
                                <div>
                                    <h3>{r.name}</h3>
                                    <p>{r.eligibility}</p>
                                    <span className="field-hint">
                                        Status only · No commission or reward
                                        posting
                                    </span>
                                </div>
                                <Badge
                                    status={
                                        r.status === "approved"
                                            ? "active"
                                            : "pending"
                                    }
                                >
                                    {r.status}
                                </Badge>
                                {r.status === "draft" && (
                                    <ConfirmAction
                                        title="Approve status-only rank"
                                        description="Confirm this eligibility has company approval. Automated assignments and financial rewards are not enabled."
                                        label="Approve"
                                        onConfirm={(_, password) =>
                                            router.post(
                                                `/ranks/${r.id}/approve`,
                                                { password },
                                                {
                                                    onError: (e) =>
                                                        setErrors(
                                                            Object.values(e)[0],
                                                        ),
                                                },
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Card>
            <Modal
                open={newRank}
                onOpenChange={setNewRank}
                title="Define a new rank"
                description="Use the company's explicit eligibility. This rank affects status only; no commission or rewards are implied."
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/ranks", {
                            onSuccess: () => {
                                setNewRank(false);
                                form.reset();
                            },
                        });
                    }}
                >
                    <Field label="Rank name" error={form.errors.name}>
                        <input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData("name", e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Company-defined eligibility"
                        error={form.errors.eligibility}
                    >
                        <textarea
                            value={form.data.eligibility}
                            onChange={(e) =>
                                form.setData("eligibility", e.target.value)
                            }
                            placeholder="Describe the approved qualification criteria"
                        />
                    </Field>
                    <div className="modal-actions">
                        <Button disabled={form.processing}>
                            Save draft rank
                        </Button>
                    </div>
                </form>
            </Modal>
        </Layout>
    );
}
