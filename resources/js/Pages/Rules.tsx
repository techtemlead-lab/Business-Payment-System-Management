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
export default function Rules({
    versions,
    preview,
    purchaseTotals,
}: {
    versions: {
        id: number;
        name: string;
        status: string;
        effective_from: string | null;
        definition: Record<string, unknown>;
        created_at: string;
    }[];
    preview: Preview | null;
    purchaseTotals: {
        company_minor: number;
        dealer_minor: number;
        pool_minor: number;
        unallocated_minor: number;
    };
}) {
    const [draft, setDraft] = useState(false);
    const [versionId, setVersionId] = useState<number | undefined>(undefined);
    const form = useForm({
        name: "",
        company_bps: 3000,
        dealer_bps: 5000,
        pool_bps: 2000,
        reason: "",
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: "",
    });
    return (
        <Layout title="Commission & rewards">
            <PageHeading
                eyebrow="CLARITY BEFORE CALCULATION"
                title="Commission & reward rules"
                description="Understand the confirmed model. Preview allocations before any financial posting."
                action={
                    <Button variant="outline" onClick={() => setDraft(true)}>
                        <Plus size={17} /> New draft version
                    </Button>
                }
            />
            <Notice tone="warning">
                <strong>
                    Purchase commission is active in commission mode.
                </strong>{" "}
                Company-recorded purchases credit 50% of total MRP to the
                purchasing dealer. Reward recipient eligibility remains
                unconfigured; rule drafts are inactive.
            </Notice>
            <Card className="purchase-allocation-summary">
                <CardHeading
                    title="Allocations from recorded purchases"
                    subtitle="Commission-mode purchase totals after cancellations. Pool amounts are held for allocation; no reward payments are posted."
                />
                <div className="purchase-breakdown">
                    <div>
                        <span>Company share · 30%</span>
                        <strong>{money(purchaseTotals.company_minor)}</strong>
                    </div>
                    <div>
                        <span>Dealer earnings · 50%</span>
                        <strong>{money(purchaseTotals.dealer_minor)}</strong>
                    </div>
                    <div>
                        <span>Reward pool · 20%</span>
                        <strong>{money(purchaseTotals.pool_minor)}</strong>
                    </div>
                    <div>
                        <span>Unallocated reward pool · 60% of pool</span>
                        <strong>
                            {money(purchaseTotals.unallocated_minor)}
                        </strong>
                    </div>
                </div>
            </Card>
            <div className="rule-version-selector">
                <label htmlFor="rule-version">Preview rule version</label>
                <select
                    id="rule-version"
                    value={versionId ?? ""}
                    onChange={(e) =>
                        setVersionId(
                            e.target.value ? Number(e.target.value) : undefined,
                        )
                    }
                >
                    <option value="">Confirmed MRP model</option>
                    {versions
                        .filter((v) => v.status === "draft")
                        .map((v) => (
                            <option key={v.id} value={v.id}>
                                V{v.id} · {v.name} (inactive draft)
                            </option>
                        ))}
                </select>
            </div>
            <Allocation
                key={versionId ?? "reference"}
                preview={preview}
                detailed
                versionId={versionId}
            />
            <Card>
                <CardHeading
                    title="Rule version history"
                    subtitle="Previous versions are retained for an auditable history."
                />
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Rule name</th>
                                <th>Status</th>
                                <th>Effective from</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {versions.map((v) => (
                                <tr key={v.id}>
                                    <td>
                                        <span className="version-code">
                                            V{v.id.toString().padStart(3, "0")}
                                        </span>
                                    </td>
                                    <td>{v.name}</td>
                                    <td>
                                        <Badge
                                            status={
                                                v.status === "reference"
                                                    ? "active"
                                                    : "pending"
                                            }
                                        >
                                            {v.status === "reference"
                                                ? v.name ===
                                                  "Confirmed dealer-purchase model"
                                                    ? "Purchase commission model"
                                                    : "Calculation reference"
                                                : "Draft · inactive"}
                                        </Badge>
                                    </td>
                                    <td>
                                        {v.effective_from
                                            ? date(v.effective_from)
                                            : "Not activated"}
                                    </td>
                                    <td>{date(v.created_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
            <Modal
                open={draft}
                onOpenChange={setDraft}
                title="Create a rule draft"
                description="Save a new immutable version for review. Saving a draft does not activate it or change historical transactions."
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/rules", {
                            onSuccess: () => {
                                setDraft(false);
                                form.reset();
                            },
                        });
                    }}
                >
                    <Field label="Version name" error={form.errors.name}>
                        <input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData("name", e.target.value)
                            }
                            placeholder="e.g. Revised commission proposal"
                        />
                    </Field>
                    <div className="form-grid">
                        {(
                            ["company_bps", "dealer_bps", "pool_bps"] as const
                        ).map((key) => (
                            <Field
                                key={key}
                                label={
                                    key === "company_bps"
                                        ? "Company %"
                                        : key === "dealer_bps"
                                          ? "Dealer %"
                                          : "Reward pool %"
                                }
                                error={form.errors[key]}
                            >
                                <input
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={form.data[key] / 100}
                                    onChange={(e) =>
                                        form.setData(
                                            key,
                                            Math.round(
                                                Number(e.target.value) * 100,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                    {(form.errors as Record<string, string>).rates && (
                        <Notice tone="warning">
                            {(form.errors as Record<string, string>).rates}
                        </Notice>
                    )}
                    <div className="form-grid">
                        <Field
                            label="Effective from"
                            error={form.errors.effective_from}
                        >
                            <input
                                type="date"
                                value={form.data.effective_from}
                                onChange={(e) =>
                                    form.setData(
                                        "effective_from",
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Effective to (optional)"
                            error={form.errors.effective_to}
                        >
                            <input
                                type="date"
                                value={form.data.effective_to}
                                onChange={(e) =>
                                    form.setData("effective_to", e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        label="Reason for this version"
                        error={form.errors.reason}
                    >
                        <textarea
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData("reason", e.target.value)
                            }
                        />
                    </Field>
                    <p className="field-hint">
                        Existing reward-pool percentages are preserved.
                        Top-level percentages must total 100%.
                    </p>
                    <div className="modal-actions">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDraft(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>
                            Save inactive draft
                        </Button>
                    </div>
                </form>
            </Modal>
        </Layout>
    );
}
