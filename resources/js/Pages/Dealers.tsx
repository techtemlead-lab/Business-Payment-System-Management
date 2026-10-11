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
export default function Dealers({
    dealers,
    filters,
}: {
    dealers: Page<Dealer>;
    filters: { q?: string; status?: string; level?: string };
}) {
    const [create, setCreate] = useState(false);
    const [q, setQ] = useState(filters.q ?? "");
    const filter = (key: string, value: string) =>
        router.get(
            "/dealers",
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    return (
        <Layout title="Dealer management">
            <PageHeading
                eyebrow="PARTNERSHIPS THAT GROW"
                title="Your dealer network"
                description="Create, review, and manage your company-approved dealer accounts."
                action={<CreateDealerButton onClick={() => setCreate(true)} />}
            />
            <Card>
                <div className="list-toolbar">
                    <form
                        className="search-field"
                        onSubmit={(e) => {
                            e.preventDefault();
                            filter("q", q);
                        }}
                    >
                        <Search size={17} />
                        <input
                            aria-label="Search dealers"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search by name, dealer code, or business..."
                        />
                        <button className="text-link" type="submit">
                            Search
                        </button>
                    </form>
                    <div className="toolbar-filters">
                        <select
                            aria-label="Filter by status"
                            value={filters.status ?? ""}
                            onChange={(e) => filter("status", e.target.value)}
                        >
                            <option value="">All statuses</option>
                            {[
                                "active",
                                "pending",
                                "suspended",
                                "deactivated",
                            ].map((s) => (
                                <option key={s} value={s}>
                                    {s[0].toUpperCase() + s.slice(1)}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Filter by level"
                            value={filters.level ?? ""}
                            onChange={(e) => filter("level", e.target.value)}
                        >
                            <option value="">All levels</option>
                            {[
                                "Divisional",
                                "District",
                                "Upazila / Thana",
                                "Union",
                            ].map((l) => (
                                <option key={l}>{l}</option>
                            ))}
                        </select>
                        <Button asChild variant="outline" size="icon">
                            <a
                                href="/reports/export?type=dealers"
                                aria-label="Export dealer report"
                            >
                                <Download size={17} />
                            </a>
                        </Button>
                    </div>
                </div>
                <DealerTable dealers={dealers.data} />
                <Pagination page={dealers} />
            </Card>
            <Notice>
                Dealer accounts are created by company administrators. Public
                registration is not available.
            </Notice>
            <DealerForm open={create} onOpenChange={setCreate} />
        </Layout>
    );
}
