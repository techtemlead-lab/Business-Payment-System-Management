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
export const levelShort = (level: string) => level.replace(" / Thana", "");
export function DealerTable({
    dealers,
    compact = false,
}: {
    dealers: Dealer[];
    compact?: boolean;
}) {
    return (
        <div className="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Dealer</th>
                        <th>Level</th>
                        <th>Territory</th>
                        <th>Status</th>
                        <th>
                            <span className="sr-only">View profile</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {dealers.map((d, i) => (
                        <tr key={d.id}>
                            <td>
                                <Link
                                    className="dealer-cell"
                                    href={`/dealers/${d.id}`}
                                >
                                    <span className={`avatar avatar-${i % 4}`}>
                                        {d.name
                                            .split(" ")
                                            .map((x) => x[0])
                                            .slice(0, 2)
                                            .join("")}
                                    </span>
                                    <div>
                                        <strong>{d.name}</strong>
                                        <span>{d.code}</span>
                                    </div>
                                </Link>
                            </td>
                            <td>
                                <span className="level-chip">
                                    {levelShort(d.level)}
                                </span>
                            </td>
                            <td>
                                <span className="territory-cell">
                                    <MapPin size={13} />
                                    {d.division}
                                </span>
                            </td>
                            <td>
                                <Badge status={d.status} />
                            </td>
                            <td>
                                <Link
                                    href={`/dealers/${d.id}`}
                                    className="icon-button"
                                    aria-label={`View ${d.name}`}
                                >
                                    <ArrowUpRight size={17} />
                                </Link>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {!dealers.length && (
                <Empty
                    title="Your network starts here"
                    description="Create your first company-approved dealer account."
                />
            )}
        </div>
    );
}
export function BalanceCards({ balances }: { balances: Balances }) {
    return (
        <div className="stats-grid">
            <Stat
                icon={<CircleDollarSign />}
                label="Total earned"
                value={money(balances.earned_minor)}
                note="Validated ledger earnings"
            />
            <Stat
                icon={<Wallet />}
                label="Available balance"
                value={money(
                    balances.available_minor ??
                        balances.earned_minor -
                            balances.reserved_minor -
                            balances.paid_minor,
                )}
                note="After reserves and payouts"
            />
            <Stat
                icon={<Clock3 />}
                label="Reserved for payout"
                value={money(balances.reserved_minor)}
                note="Funds held for active payouts"
            />
            <Stat
                icon={<CheckCircle2 />}
                label="Successfully paid"
                value={money(balances.paid_minor)}
                note="Verified payout evidence"
            />
        </div>
    );
}
export function Stat({
    icon,
    label,
    value,
    note,
    accent = false,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    note: string;
    accent?: boolean;
}) {
    return (
        <Card className={`stat-card ${accent ? "stat-accent" : ""}`}>
            <div className="stat-top">
                <span>{label}</span>
                <span className="stat-icon">{icon}</span>
            </div>
            <strong className="stat-value">{value}</strong>
            <div className="stat-note">
                <span className="tiny-dot" />
                {note}
            </div>
        </Card>
    );
}
