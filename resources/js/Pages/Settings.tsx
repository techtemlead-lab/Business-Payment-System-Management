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
export default function Settings() {
    const { company } = usePage<Shared>().props;
    const form = useForm({
        company_name: company.company_name,
        contact_email: company.contact_email ?? "",
        contact_phone: company.contact_phone ?? "",
    });
    return (
        <Layout title="Settings">
            <PageHeading
                eyebrow="MAKE THIS WORKSPACE YOURS"
                title="Company settings"
                description="Manage your company's public information and workspace preferences."
            />
            <div className="two-column">
                <Card>
                    <CardHeading
                        title="Company profile"
                        subtitle="Only approved contact details appear on the public website."
                    />
                    <form
                        className="card-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.patch("/settings");
                        }}
                    >
                        <Field
                            label="Company name"
                            error={form.errors.company_name}
                        >
                            <input
                                value={form.data.company_name}
                                onChange={(e) =>
                                    form.setData("company_name", e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Public contact email"
                            error={form.errors.contact_email}
                        >
                            <input
                                type="email"
                                value={form.data.contact_email}
                                onChange={(e) =>
                                    form.setData(
                                        "contact_email",
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Public contact phone"
                            error={form.errors.contact_phone}
                        >
                            <input
                                type="tel"
                                value={form.data.contact_phone}
                                onChange={(e) =>
                                    form.setData(
                                        "contact_phone",
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Button disabled={form.processing}>
                            Save company profile
                        </Button>
                    </form>
                </Card>
                <div>
                    <Card>
                        <CardHeading title="Workspace defaults" />
                        <div className="profile-details">
                            <div>
                                <span>Currency</span>
                                <strong>BDT · Bangladeshi taka</strong>
                            </div>
                            <div>
                                <span>Display timezone</span>
                                <strong>Asia / Dhaka</strong>
                            </div>
                            <div>
                                <span>Dealer onboarding</span>
                                <strong>Admin-created accounts</strong>
                            </div>
                            <div>
                                <span>Active mode</span>
                                <strong>
                                    {company.mode === "COMMISSION_BASED"
                                        ? "Commission-based"
                                        : "Rank-based"}
                                </strong>
                            </div>
                        </div>
                    </Card>
                    <Card className="settings-security">
                        <ShieldCheck size={28} />
                        <h3>One more layer of protection</h3>
                        <p>
                            Manage two-factor authentication for your account.
                        </p>
                        <ArrowLink href="/security">Account security</ArrowLink>
                    </Card>
                </div>
            </div>
        </Layout>
    );
}
