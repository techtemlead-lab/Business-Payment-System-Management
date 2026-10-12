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
export default function Portal({
    dealer,
    balances,
    entries,
}: {
    dealer: Dealer;
    balances: Balances;
    entries: Entry[];
}) {
    return (
        <Layout title="Overview">
            <PageHeading
                eyebrow="YOUR PARTNERSHIP, IN FOCUS"
                title={`Welcome back, ${dealer.name.split(" ")[0]}.`}
                description="Your account, earnings, and payout history — all in one place."
                action={
                    <Button asChild>
                        <Link href="/payouts">
                            Request withdrawal <ArrowUpRight size={16} />
                        </Link>
                    </Button>
                }
            />
            <div className="dealer-banner">
                <div>
                    <span className="eyebrow">
                        {dealer.dealer_type === "area_dealer"
                            ? "AUTHORIZED AREA DEALER"
                            : "AUTHORIZED DEALER"}
                    </span>
                    <h2>{dealer.business_name || dealer.name}</h2>
                    <p>
                        <span>{dealer.code}</span>
                        <span>·</span>
                        <span>{dealer.level} territory</span>
                        <span>·</span>
                        <span>{dealer.division}</span>
                    </p>
                </div>
                <Button asChild variant="outline">
                    <Link href={`/dealers/${dealer.id}`}>
                        View my profile <ArrowUpRight size={16} />
                    </Link>
                </Button>
            </div>
            <BalanceCards balances={balances} />
            <Card>
                <CardHeading
                    title="Recent earnings"
                    subtitle="Only your authorized financial records appear here."
                    action={
                        <ArrowLink href="/ledger">View my ledger</ArrowLink>
                    }
                />
                {entries.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map((e) => (
                                    <tr key={e.id}>
                                        <td>{e.business_reference}</td>
                                        <td>{e.kind}</td>
                                        <td>{money(e.amount_minor)}</td>
                                        <td>{date(e.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        icon={<CircleDollarSign size={28} />}
                        title="Your earnings will appear here"
                        description="There are no posted earnings yet. Contact the company if you have questions about eligible transactions."
                    />
                )}
            </Card>
            <div className="two-column">
                <Card className="portal-link">
                    <Wallet size={28} />
                    <div>
                        <h3>Withdraw your earnings</h3>
                        <p>
                            Request a withdrawal and follow the company review.
                        </p>
                    </div>
                    <ArrowLink href="/payouts">Request withdrawal</ArrowLink>
                </Card>
                <Card className="portal-link">
                    <ShieldCheck size={28} />
                    <div>
                        <h3>Protect your partnership</h3>
                        <p>Add two-factor authentication.</p>
                    </div>
                    <ArrowLink href="/security">Manage security</ArrowLink>
                </Card>
            </div>
        </Layout>
    );
}
