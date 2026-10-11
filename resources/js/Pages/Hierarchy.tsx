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
export default function Hierarchy({ dealers }: { dealers: Dealer[] }) {
    const levels = ["Divisional", "District", "Upazila / Thana", "Union"];
    return (
        <Layout title="Network & territories">
            <PageHeading
                eyebrow="CONNECTED ACROSS BANGLADESH"
                title="A network with local reach"
                description="See dealer levels and coverage without inferring unapproved hierarchy relationships."
            />
            <div className="territory-levels">
                {levels.map((l, i) => (
                    <Card key={l}>
                        <span className={`territory-icon territory-icon-${i}`}>
                            <Network size={24} />
                        </span>
                        <span className="eyebrow">LEVEL {i + 1}</span>
                        <h2>{levelShort(l)} dealers</h2>
                        <strong>
                            {dealers
                                .filter((d) => d.level === l)
                                .length.toString()
                                .padStart(2, "0")}
                        </strong>
                        <p>
                            {i === 0
                                ? "Division-wide coverage"
                                : i === 1
                                  ? "District-level coverage"
                                  : i === 2
                                    ? "Upazila and thana coverage"
                                    : "Union-level coverage"}
                        </p>
                    </Card>
                ))}
            </div>
            <Notice>
                Territories do not imply parent, sponsor, referral, or
                commission relationships. These rules require an explicit
                company decision.
            </Notice>
            <Card>
                <CardHeading
                    title="Dealer coverage"
                    subtitle="Company-assigned geographical information."
                />
                <DealerTable dealers={dealers} />
            </Card>
        </Layout>
    );
}
