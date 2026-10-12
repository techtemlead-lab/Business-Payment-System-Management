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
    locationOptions,
    locationLabels,
    legacyLocations,
    locationCatalog,
}: {
    dealers: Page<Dealer>;
    filters: {
        q?: string;
        status?: string;
        level?: string;
        dealer_type?: string;
        division?: string;
        district?: string;
        upazila?: string;
        union_name?: string;
    };
    locationOptions: Record<string, string[]>;
    locationLabels: Record<string, Record<string, string>>;
    legacyLocations: Record<string, string[]>;
    locationCatalog: {
        repository_url: string;
        police_source_url: string;
        retrieved_at: string;
        counts: Record<string, number>;
    };
}) {
    const [create, setCreate] = useState(false);
    const [q, setQ] = useState(filters.q ?? "");
    const filter = (key: string, value: string) =>
        router.get(
            "/dealers",
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    const locations = [
        ["division", "Division"],
        ["district", "District"],
        ["upazila", "Upazila / Thana"],
        ["union_name", "Union"],
    ] as const;
    const filterLocation = (key: string, value: string) => {
        const next: Record<string, string | undefined> = {
            ...filters,
            [key]: value || undefined,
        };
        const position = locations.findIndex(([field]) => field === key);
        locations.slice(position + 1).forEach(([field]) => {
            delete next[field];
        });
        router.get("/dealers", next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
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
                            aria-label="Filter by account type"
                            value={filters.dealer_type ?? ""}
                            onChange={(event) =>
                                filter("dealer_type", event.target.value)
                            }
                        >
                            <option value="">All account types</option>
                            <option value="dealer">Dealer</option>
                            <option value="area_dealer">Area dealer</option>
                        </select>
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
                <div className="border-t border-gray-100 px-6 py-5">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-semibold">Filter by location</h2>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                const next = { ...filters };
                                locations.forEach(([key]) => delete next[key]);
                                router.get("/dealers", next, {
                                    preserveState: true,
                                    preserveScroll: true,
                                    replace: true,
                                });
                            }}
                        >
                            Clear location filters
                        </Button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {locations.map(([key, label], index) => (
                            <Field key={key} label={label}>
                                <select
                                    value={filters[key] ?? ""}
                                    disabled={
                                        index > 0 &&
                                        !filters[locations[index - 1][0]] &&
                                        !filters[key]
                                    }
                                    onChange={(event) =>
                                        filterLocation(key, event.target.value)
                                    }
                                >
                                    <option value="">
                                        {index > 0 &&
                                        !filters[locations[index - 1][0]] &&
                                        !filters[key] ? (
                                            `Choose ${locations[index - 1][1].toLowerCase()} first`
                                        ) : (
                                            <>
                                                All{" "}
                                                {label === "Upazila / Thana"
                                                    ? "upazilas / thanas"
                                                    : `${label.toLowerCase()}s`}
                                            </>
                                        )}
                                    </option>
                                    {filters[key] &&
                                        !locationOptions[key].includes(
                                            filters[key],
                                        ) && (
                                            <option value={filters[key]}>
                                                {filters[key]} (no matching
                                                accounts)
                                            </option>
                                        )}
                                    {locationOptions[key].map((location) => (
                                        <option key={location} value={location}>
                                            {locationLabels[key][location] ??
                                                location}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        ))}
                    </div>
                    <p className="field-hint mt-3">
                        Bangladesh location catalog:{" "}
                        {locationCatalog.counts.division} divisions,{" "}
                        {locationCatalog.counts.district} districts,{" "}
                        {locationCatalog.counts.upazila} upazilas,{" "}
                        {locationCatalog.counts.metropolitan_thana} metropolitan
                        thanas,{" "}
                        {locationCatalog.counts.union_name.toLocaleString()}{" "}
                        unions. Select each level in order. {dealers.total}{" "}
                        matching dealer
                        {dealers.total === 1 ? "" : "s"}.
                    </p>
                    <p className="field-hint mt-2">
                        <a
                            href={locationCatalog.repository_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-link"
                        >
                            Location data source
                        </a>{" "}
                        ·{" "}
                        <a
                            href={locationCatalog.police_source_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-link"
                        >
                            Thana source
                        </a>{" "}
                        · Collected {date(locationCatalog.retrieved_at)}. Thanas
                        are marked separately. Unions belong to administrative
                        upazilas.
                    </p>
                    {Object.values(legacyLocations).some(
                        (values) => values.length > 0,
                    ) && (
                        <p className="field-hint mt-2">
                            Choices marked “existing account location” are saved
                            dealer locations outside this catalog. The company
                            can review them in the dealer profile.
                        </p>
                    )}
                </div>
                {dealers.data.length ? (
                    <DealerTable dealers={dealers.data} />
                ) : (
                    <Empty
                        title="No dealers found."
                        description="Adjust the search or location filters to find another account."
                    />
                )}
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
