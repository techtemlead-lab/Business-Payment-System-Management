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
export default function Audit({
    events,
    filters,
}: {
    events: Page<Audit>;
    filters: { q?: string };
}) {
    const [q, setQ] = useState(filters.q ?? "");
    return (
        <Layout title="Audit trail">
            <PageHeading
                eyebrow="TRANSPARENCY, BY DESIGN"
                title="Workspace audit trail"
                description="A protected record of account changes, rule decisions, and sensitive actions."
            />
            <Card>
                <div className="list-toolbar">
                    <form
                        className="search-field"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                "/audit",
                                { q },
                                { preserveState: true },
                            );
                        }}
                    >
                        <Search size={17} />
                        <input
                            aria-label="Search audit events"
                            placeholder="Filter by action, e.g. dealer or mode..."
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                        <button type="submit" className="text-link">
                            Search
                        </button>
                    </form>
                    <Badge status="active">Append-only</Badge>
                </div>
                {events.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Actor</th>
                                    <th>Subject</th>
                                    <th>Details</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            <span className="audit-action">
                                                <ShieldCheck size={15} />
                                                {actionLabel(e.action)}
                                            </span>
                                        </td>
                                        <td>
                                            {e.actor_id
                                                ? `User #${e.actor_id}`
                                                : "System"}
                                        </td>
                                        <td>
                                            {e.subject_id
                                                ? `#${e.subject_id}`
                                                : "—"}
                                        </td>
                                        <td>
                                            <span className="audit-detail">
                                                {Object.entries(e.details)
                                                    .map(
                                                        ([k, v]) =>
                                                            `${k.replaceAll("_", " ")}: ${typeof v === "object" ? JSON.stringify(v) : v}`,
                                                    )
                                                    .join(" · ") || "—"}
                                            </span>
                                        </td>
                                        <td>{date(e.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        icon={<ScrollText size={28} />}
                        title="Your history starts here"
                        description="Sensitive actions will appear here automatically as your team uses the workspace."
                    />
                )}
                <Pagination page={events} />
            </Card>
        </Layout>
    );
}
