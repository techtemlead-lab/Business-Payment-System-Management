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
export default function Ledger({
    entries,
    balances,
}: {
    entries: Page<Entry>;
    balances: Balances;
}) {
    return (
        <Layout title="Financial ledger">
            <PageHeading
                eyebrow="A HISTORY YOU CAN TRUST"
                title="Financial ledger"
                description="Immutable records of validated earnings, reservations, payouts, and reversals."
            />
            <BalanceCards balances={balances} />
            <Card>
                <CardHeading
                    title="Ledger transactions"
                    subtitle="All amounts are in Bangladeshi taka (BDT)."
                />
                {entries.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Rule version</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((e) => (
                                    <tr key={e.id}>
                                        <td className="version-code">
                                            {e.business_reference}
                                        </td>
                                        <td>
                                            <Badge status="neutral">
                                                {e.kind}
                                            </Badge>
                                        </td>
                                        <td className="money-cell">
                                            {money(e.amount_minor)}
                                        </td>
                                        <td>
                                            {e.rule_version_id
                                                ? `V${e.rule_version_id}`
                                                : "—"}
                                        </td>
                                        <td>{date(e.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        icon={<Layers size={28} />}
                        title="A clean starting point"
                        description="No financial entries have been posted. Approved source transactions and complete financial rules are required before earnings appear."
                    />
                )}
                <Pagination page={entries} />
            </Card>
            <Notice>
                Historical entries cannot be edited or deleted. Errors must be
                corrected through an authorized, auditable reversal.
            </Notice>
        </Layout>
    );
}
