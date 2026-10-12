import { useState } from "react";
import { DealerTypeField } from "@/components/DealerTypeField";
import { ManageReferral } from "@/components/ManageReferral";
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
        name: dealer.name,
        email: dealer.email,
        mobile: dealer.mobile ?? "",
        ...(isAdmin ? { dealer_type: dealer.dealer_type } : {}),
        level: dealer.level,
        division: dealer.division,
        district: dealer.district ?? "",
        upazila: dealer.upazila ?? "",
        union_name: dealer.union_name ?? "",
        password: "",
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
            <Card>
                <CardHeading
                    title="Referral network"
                    subtitle="Track this dealer’s three generations and direct referrer."
                />
                <Button asChild variant="outline">
                    <Link
                        href={
                            isAdmin
                                ? `/referrals?code=${encodeURIComponent(dealer.code)}`
                                : "/referrals"
                        }
                    >
                        View referral network
                    </Link>
                </Button>
            </Card>
            {isAdmin && (
                <Card className="my-6 p-6">
                    <h2 className="mb-3 text-xl">Referral connection</h2>
                    <p>
                        <strong>Unique referral code:</strong> {dealer.code}
                    </p>
                    <p>
                        <strong>Direct referrer:</strong>{" "}
                        {dealer.referrer_code
                            ? `${dealer.referrer_code} · ${dealer.referrer_name}`
                            : "No referrer"}
                    </p>
                    <p className="field-hint mb-3">
                        {dealer.referrer_code
                            ? dealer.referral_verified_at
                                ? `Verified ${date(dealer.referral_verified_at)} by ${dealer.referral_verified_by_name}`
                                : "Existing connection · manual verification pending"
                            : "The company can connect a referring dealer at any time."}
                    </p>
                    <ManageReferral dealer={dealer} />
                </Card>
            )}
            {isAdmin && (
                <Card className="dealer-financial-actions">
                    <div>
                        <h2>Manage this dealer’s financial records</h2>
                        <p>
                            Record purchases to credit earnings. Balances come
                            from the ledger and corrections preserve history.
                        </p>
                    </div>
                    <Button asChild>
                        <Link
                            href={`/purchases?dealer_code=${encodeURIComponent(dealer.code)}`}
                        >
                            Record purchase <ArrowUpRight size={16} />
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/payouts">Review withdrawals</Link>
                    </Button>
                </Card>
            )}
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
                            [
                                "Account type",
                                dealer.dealer_type === "area_dealer"
                                    ? "Area dealer"
                                    : "Dealer",
                            ],
                            ["Territory level", dealer.level],
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
                        {isAdmin && (
                            <>
                                <DealerTypeField
                                    value={
                                        form.data.dealer_type ??
                                        dealer.dealer_type
                                    }
                                    error={form.errors.dealer_type}
                                    onChange={(value) =>
                                        form.setData(
                                            "dealer_type",
                                            value as "dealer" | "area_dealer",
                                        )
                                    }
                                />
                                {(
                                    [
                                        ["name", "Full name (English)"],
                                        ["email", "Email address"],
                                        ["mobile", "Mobile number"],
                                    ] as const
                                ).map(([key, label]) => (
                                    <Field
                                        key={key}
                                        label={label}
                                        error={form.errors[key]}
                                    >
                                        <input
                                            type={
                                                key === "email"
                                                    ? "email"
                                                    : key === "mobile"
                                                      ? "tel"
                                                      : "text"
                                            }
                                            value={form.data[key]}
                                            onChange={(event) =>
                                                form.setData(
                                                    key,
                                                    event.target.value,
                                                )
                                            }
                                            required={key !== "mobile"}
                                        />
                                    </Field>
                                ))}
                                <Field
                                    label="Territory level"
                                    error={form.errors.level}
                                >
                                    <select
                                        value={form.data.level}
                                        onChange={(event) =>
                                            form.setData(
                                                "level",
                                                event.target.value,
                                            )
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
                                {form.data.level !== "Divisional" && (
                                    <Field
                                        label="District"
                                        error={form.errors.district}
                                    >
                                        <input
                                            required
                                            value={form.data.district}
                                            onChange={(event) =>
                                                form.setData(
                                                    "district",
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {["Upazila / Thana", "Union"].includes(
                                    form.data.level,
                                ) && (
                                    <Field
                                        label="Upazila / Thana"
                                        error={form.errors.upazila}
                                    >
                                        <input
                                            required
                                            value={form.data.upazila}
                                            onChange={(event) =>
                                                form.setData(
                                                    "upazila",
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {form.data.level === "Union" && (
                                    <Field
                                        label="Union"
                                        error={form.errors.union_name}
                                    >
                                        <input
                                            required
                                            value={form.data.union_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    "union_name",
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                <Field
                                    label="Current password"
                                    error={form.errors.password}
                                    hint="Required outside the local demo."
                                >
                                    <input
                                        type="password"
                                        autoComplete="current-password"
                                        value={form.data.password}
                                        onChange={(event) =>
                                            form.setData(
                                                "password",
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </>
                        )}
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
