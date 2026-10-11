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
export default function DealerProfile({
    dealer,
    balances,
}: {
    dealer: Dealer;
    balances: Balances;
}) {
    const { auth } = usePage<Shared>().props;
    const isAdmin = auth.user?.role === "admin";
    const form = useForm({
        name_bn: dealer.name_bn ?? "",
        business_name: dealer.business_name ?? "",
    });
    const [errors, setErrors] = useState("");
    return (
        <Layout title="Dealer profile">
            <Link
                href={isAdmin ? "/dealers" : "/dashboard"}
                className="back-link"
            >
                <ArrowLeft size={16} />
                {isAdmin ? "Back to dealers" : "Back to overview"}
            </Link>
            <PageHeading
                title={dealer.name}
                description={`${dealer.code} · ${dealer.level} dealer`}
                action={<Badge status={dealer.status} />}
            />
            <BalanceCards balances={balances} />
            <div className="two-column">
                <Card>
                    <CardHeading
                        title="Partner details"
                        subtitle="Account and approved geographical coverage."
                    />
                    <div className="profile-details">
                        {[
                            ["Dealer code", dealer.code],
                            ["English name", dealer.name],
                            ["Email address", dealer.email],
                            ["Mobile", dealer.mobile || "Not provided"],
                            ["Dealer level", dealer.level],
                            ["Division", dealer.division],
                            ["District", dealer.district || "Not assigned"],
                            [
                                "Upazila / Thana",
                                dealer.upazila || "Not assigned",
                            ],
                            ["Union", dealer.union_name || "Not assigned"],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <span>{label}</span>
                                <strong>{value}</strong>
                            </div>
                        ))}
                    </div>
                </Card>
                <Card>
                    <CardHeading
                        title="Profile information"
                        subtitle="Update permitted business and profile fields."
                    />
                    <form
                        className="card-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.patch(`/dealers/${dealer.id}`);
                        }}
                    >
                        <Field
                            label="Full name (Bangla)"
                            error={form.errors.name_bn}
                        >
                            <input
                                value={form.data.name_bn}
                                onChange={(e) =>
                                    form.setData("name_bn", e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Business name"
                            error={form.errors.business_name}
                        >
                            <input
                                value={form.data.business_name}
                                onChange={(e) =>
                                    form.setData(
                                        "business_name",
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Button disabled={form.processing}>Save profile</Button>
                    </form>
                    {isAdmin && (
                        <div className="lifecycle">
                            <h3>Account lifecycle</h3>
                            <p>
                                Review company eligibility before activating a
                                dealer. All changes are recorded.
                            </p>
                            {errors && <Notice tone="warning">{errors}</Notice>}
                            <div className="lifecycle-actions">
                                {["active", "suspended", "deactivated"]
                                    .filter((s) => s !== dealer.status)
                                    .map((s) => (
                                        <ConfirmAction
                                            key={s}
                                            title={`${s === "active" ? "Activate" : s === "suspended" ? "Suspend" : "Deactivate"} dealer`}
                                            description="This updates account access. Existing financial history is preserved."
                                            label={
                                                s === "active"
                                                    ? "Activate"
                                                    : s === "suspended"
                                                      ? "Suspend"
                                                      : "Deactivate"
                                            }
                                            onConfirm={(reason, password) =>
                                                router.post(
                                                    `/dealers/${dealer.id}/status`,
                                                    {
                                                        status: s,
                                                        reason,
                                                        password,
                                                    },
                                                    {
                                                        onError: (e) =>
                                                            setErrors(
                                                                Object.values(
                                                                    e,
                                                                )[0],
                                                            ),
                                                    },
                                                )
                                            }
                                        />
                                    ))}
                            </div>
                        </div>
                    )}
                </Card>
            </div>
        </Layout>
    );
}
