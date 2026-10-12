import { Link, useForm, usePage } from "@inertiajs/react";
import { Layout } from "@/components/Layout";
import { RewardTabs } from "@/components/RewardTabs";
import { BalanceCards } from "@/components/WorkspaceComponents";
import {
    Card,
    PageHeading,
    Field,
    Button,
    Notice,
    Empty,
    Pagination,
} from "@/components/Common";
import {
    money,
    date,
    type Shared,
    type Balances,
    type Page,
} from "@/lib/types";

type Category = {
    key: string;
    label: string;
    gross_minor: number;
    reversed_minor: number;
    net_minor: number;
    entries: number;
    status: string;
};
type Earning = {
    id: number;
    category: string;
    dealer_code: string;
    dealer_name: string;
    business_reference: string;
    amount_minor: number;
    kind: string;
    created_at: string;
    reason: string;
    rule_version_id: number | null;
    reversal_of: number | null;
};
type Filters = { code?: string; category?: string; from?: string; to?: string };
export default function Earnings({
    categories,
    entries,
    filters,
    dealer,
    balances,
    companyShareMinor,
}: {
    categories: Category[];
    entries: Page<Earning>;
    filters: Filters;
    dealer: { code: string; name: string; dealer_type: string } | null;
    balances: Balances | null;
    companyShareMinor: number | null;
}) {
    const isAdmin = usePage<Shared>().props.auth.user?.role === "admin";
    const form = useForm({
        code: filters.code ?? "",
        category: filters.category ?? "",
        from: filters.from ?? "",
        to: filters.to ?? "",
    });
    const total = categories.reduce((sum, item) => sum + item.net_minor, 0);
    const categoryUrl = (key: string) =>
        `/rewards/earnings?${new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value)), category: key }).toString()}`;
    return (
        <Layout title="Commission & rewards · Earnings">
            <PageHeading
                eyebrow="COMMISSION & REWARDS"
                title="See where your earnings come from."
                description={
                    isAdmin
                        ? "View dealer earnings across the company or inspect one account. Company income is shown separately."
                        : "Track purchase commission, referrals, salary, and rewards by category."
                }
            />
            <RewardTabs active="earnings" admin={isAdmin} />
            <Card className="mb-6 p-6">
                <form
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get("/rewards/earnings", { preserveScroll: true });
                    }}
                >
                    {isAdmin && (
                        <Field
                            label="Dealer code"
                            hint="Leave empty for all dealers."
                            error={form.errors.code}
                        >
                            <input
                                value={form.data.code}
                                maxLength={30}
                                onChange={(event) =>
                                    form.setData(
                                        "code",
                                        event.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="All dealers"
                            />
                        </Field>
                    )}
                    <Field
                        label="Statement category"
                        error={form.errors.category}
                    >
                        <select
                            value={form.data.category}
                            onChange={(event) =>
                                form.setData("category", event.target.value)
                            }
                        >
                            <option value="">All categories</option>
                            {categories.map((item) => (
                                <option key={item.key} value={item.key}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        label="From date (Bangladesh)"
                        error={form.errors.from}
                    >
                        <input
                            type="date"
                            value={form.data.from}
                            onChange={(event) =>
                                form.setData("from", event.target.value)
                            }
                        />
                    </Field>
                    <Field label="To date (Bangladesh)" error={form.errors.to}>
                        <input
                            type="date"
                            value={form.data.to}
                            onChange={(event) =>
                                form.setData("to", event.target.value)
                            }
                        />
                    </Field>
                    <div className="flex items-center gap-3">
                        <Button disabled={form.processing}>
                            Apply filters
                        </Button>
                        <Link className="text-link" href="/rewards/earnings">
                            Reset
                        </Link>
                    </div>
                </form>
            </Card>
            {dealer && (
                <div className="mb-5 flex flex-wrap items-center gap-3">
                    <strong>
                        {dealer.name} · {dealer.code}
                    </strong>
                    <span className="level-chip">
                        {dealer.dealer_type === "area_dealer"
                            ? "Area dealer"
                            : "Dealer"}
                    </span>
                </div>
            )}
            <div className="mb-6 grid gap-4 sm:grid-cols-2">
                <Card className="p-6">
                    <span className="eyebrow">
                        {isAdmin ? "DEALER NET EARNINGS" : "MY NET EARNINGS"}
                    </span>
                    <h2 className="mt-3 text-3xl">{money(total)}</h2>
                    <p className="field-hint">
                        All categories in the selected period, after reversals.
                    </p>
                </Card>
                {companyShareMinor !== null && (
                    <Card className="p-6">
                        <span className="eyebrow">COMPANY PURCHASE SHARE</span>
                        <h2 className="mt-3 text-3xl">
                            {money(companyShareMinor)}
                        </h2>
                        <p className="field-hint">
                            From recorded purchases by purchase date in the
                            selected period. Excludes cancellations and
                            previews.
                        </p>
                    </Card>
                )}
            </div>
            {balances && (
                <>
                    <p className="mb-3 field-hint">
                        Current wallet · all time, across all categories
                    </p>
                    <BalanceCards balances={balances} />
                </>
            )}
            <div className="my-6">
                <Notice>
                    Reward pool allocations are budgets. This view counts posted
                    earnings only. Referral, salary, and other reward payouts
                    await approved payout rules.{" "}
                    {dealer?.dealer_type === "dealer" &&
                        "Your account is not eligible for new area dealer commission awards."}
                </Notice>
            </div>
            <Card className="mb-6 p-6">
                <h2 className="mb-5 text-xl">Earnings by category</h2>
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Reward or commission</th>
                                <th>Earned</th>
                                <th>Reversed</th>
                                <th>Net earned</th>
                                <th>Status</th>
                                <th>Statement</th>
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((item) => (
                                <tr key={item.key}>
                                    <td>
                                        <strong>{item.label}</strong>
                                    </td>
                                    <td>{money(item.gross_minor)}</td>
                                    <td>{money(item.reversed_minor)}</td>
                                    <td>
                                        <strong>{money(item.net_minor)}</strong>
                                    </td>
                                    <td>
                                        <span className="field-hint">
                                            {item.status}
                                        </span>
                                    </td>
                                    <td>
                                        <Link
                                            href={categoryUrl(item.key)}
                                            className="text-link"
                                            aria-label={`View ${item.label} statement`}
                                        >
                                            View ({item.entries})
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
            <Card className="p-6">
                <h2 className="mb-5 text-xl">
                    {categories.find((item) => item.key === filters.category)
                        ?.label ?? "All earnings"}{" "}
                    · statement
                </h2>
                {entries.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date / reference</th>
                                    {isAdmin && <th>Dealer</th>}
                                    <th>Category / details</th>
                                    <th>Entry</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => (
                                    <tr key={entry.id}>
                                        <td>
                                            <strong>
                                                {entry.business_reference}
                                            </strong>
                                            <div className="field-hint">
                                                {date(entry.created_at)}
                                            </div>
                                        </td>
                                        {isAdmin && (
                                            <td>
                                                {entry.dealer_name}
                                                <div className="field-hint">
                                                    {entry.dealer_code}
                                                </div>
                                            </td>
                                        )}
                                        <td>
                                            {
                                                categories.find(
                                                    (item) =>
                                                        item.key ===
                                                        entry.category,
                                                )?.label
                                            }
                                            <div className="max-w-md field-hint">
                                                {entry.reason}
                                                {entry.rule_version_id &&
                                                    ` · Rule V${entry.rule_version_id}`}
                                                {entry.reversal_of &&
                                                    ` · Reverses entry #${entry.reversal_of}`}
                                            </div>
                                        </td>
                                        <td>
                                            {entry.kind === "earned"
                                                ? "Earned"
                                                : "Reversal"}
                                        </td>
                                        <td>{money(entry.amount_minor)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        title="No earnings in this selection."
                        description="Posted earnings and their reversals will appear here. Change the category or date range to view another statement."
                    />
                )}
                <Pagination page={entries} />
            </Card>
        </Layout>
    );
}
