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
import { WithdrawalPanel, type Withdrawal } from "@/components/WithdrawalPanel";
export default function Payouts({
    payouts,
    withdrawals,
    balances,
}: {
    withdrawals: Page<Withdrawal>;
    balances: Balances | null;
    payouts: Page<{
        id: number;
        dealer_id: number;
        amount_minor: number;
        status: string;
        provider_reference: string | null;
        created_at: string;
    }>;
}) {
    return (
        <Layout title="Payouts">
            <PageHeading
                eyebrow="FROM EARNINGS TO PAYOUTS"
                title="Withdrawals & payouts"
                description="Request withdrawals, review decisions, and track verified payments."
            />
            <WithdrawalPanel withdrawals={withdrawals} balances={balances} />
            <Notice tone="warning">
                <strong>Payment execution is not yet enabled.</strong> The
                company must confirm a payout provider, beneficiary
                verification, limits, and approval policies.
            </Notice>
            <Card>
                <CardHeading
                    title="Payout queue"
                    subtitle="A request is not proof that money has moved."
                />
                {payouts.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request</th>
                                    <th>Dealer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Provider reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                {payouts.data.map((p) => (
                                    <tr key={p.id}>
                                        <td>#{p.id}</td>
                                        <td>#{p.dealer_id}</td>
                                        <td className="money-cell">
                                            {money(p.amount_minor)}
                                        </td>
                                        <td>
                                            <Badge status={p.status} />
                                        </td>
                                        <td>
                                            {p.provider_reference ??
                                                "No verified reference"}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        icon={<Wallet size={29} />}
                        title="No payout requests yet"
                        description="Verified payment records will appear here when the payout provider is configured. Withdrawal requests are shown above."
                    />
                )}
                <Pagination page={payouts} />
            </Card>
            <div className="payout-principles">
                {[
                    {
                        icon: LockKeyhole,
                        title: "Funds protected",
                        text: "Available balances must be validated and reserved safely.",
                    },
                    {
                        icon: ShieldCheck,
                        title: "Evidence required",
                        text: "Only verified provider results can confirm success.",
                    },
                    {
                        icon: Activity,
                        title: "Every step traceable",
                        text: "Ambiguous results require reconciliation before retrying.",
                    },
                ].map((p) => (
                    <Card key={p.title}>
                        <p.icon size={24} />
                        <h3>{p.title}</h3>
                        <p>{p.text}</p>
                    </Card>
                ))}
            </div>
        </Layout>
    );
}
