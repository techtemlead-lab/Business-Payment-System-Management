import { useState } from "react";
import { router, useForm, usePage, Link } from "@inertiajs/react";
import { Plus, ArrowUpRight, ShoppingBag, Search } from "lucide-react";
import { Layout } from "@/components/Layout";
import {
    Button,
    Card,
    CardHeading,
    Field,
    Modal,
    Empty,
    Pagination,
    PageHeading,
    Badge,
    Notice,
} from "@/components/Common";
import { money, type Shared, type Page, type Preview } from "@/lib/types";
type Purchase = {
    id: number;
    dealer_name: string;
    dealer_code: string;
    reference: string;
    product_name: string;
    quantity: number;
    unit_mrp_minor: number;
    total_mrp_minor: number;
    amount_paid_minor: number;
    purchased_on: string;
    status: string;
    calculation: Preview;
    ledger_entry_id: number | null;
    note: string | null;
    cancellation_reason: string | null;
};
export default function Purchases({
    purchases,
    filters,
    totals,
}: {
    purchases: Page<Purchase>;
    filters: { q: string };
    totals: { records: number; mrp_minor: number; paid_minor: number };
}) {
    const { auth, company } = usePage<Shared>().props;
    const isCompany = auth.user?.role === "admin";
    const suggestedCode =
        new URLSearchParams(usePage().url.split("?")[1]).get("dealer_code") ??
        "";
    const [open, setOpen] = useState(isCompany && !!suggestedCode);
    const [q, setQ] = useState(filters.q);
    const [selected, setSelected] = useState<Purchase | null>(null);
    const form = useForm({
        dealer_code: suggestedCode,
        reference: "",
        product_name: "",
        quantity: "",
        unit_mrp: "",
        amount_paid: "",
        purchased_on: new Date().toLocaleDateString("en-CA", {
            timeZone: "Asia/Dhaka",
        }),
        note: "",
        password: "",
    });
    const cancel = useForm({ reason: "", password: "" });
    const numericMRP = /^\d+(\.\d{1,2})?$/.test(form.data.unit_mrp)
        ? Math.round(Number(form.data.unit_mrp) * 100)
        : 0;
    const estimate = numericMRP * Number(form.data.quantity || 0);
    const input = (
        key: keyof typeof form.data,
        label: string,
        type = "text",
        hint?: string,
    ) => (
        <Field label={label} error={form.errors[key]} hint={hint}>
            <input
                type={type}
                required={key !== "password"}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                min={key === "quantity" ? 1 : undefined}
                max={key === "quantity" ? 100000 : undefined}
                autoComplete={key === "password" ? "current-password" : "off"}
                inputMode={
                    ["unit_mrp", "amount_paid"].includes(key)
                        ? "decimal"
                        : undefined
                }
            />
        </Field>
    );
    return (
        <Layout title="Dealer purchases">
            <PageHeading
                eyebrow="THE BUSINESS BEHIND THE NUMBERS"
                title={
                    isCompany
                        ? "Every purchase, in focus."
                        : "Your purchase history."
                }
                description={
                    isCompany
                        ? "Record what each dealer bought. Calculations use total product MRP and the active commission rule."
                        : "Purchases recorded by your company, with your earnings breakdown."
                }
                action={
                    isCompany ? (
                        <Button
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setOpen(true);
                            }}
                        >
                            <Plus size={17} /> Record purchase
                        </Button>
                    ) : undefined
                }
            />
            {company.mode === "RANK_BASED" && (
                <Notice>
                    Rank-based financial rules are not configured. Purchases can
                    be recorded with a calculation preview; commission earnings
                    are posted only in commission mode.
                </Notice>
            )}
            <div className="purchase-totals">
                <Card>
                    <span>Recorded purchases</span>
                    <strong>{totals.records}</strong>
                </Card>
                <Card>
                    <span>Total product MRP</span>
                    <strong>{money(totals.mrp_minor)}</strong>
                </Card>
                <Card>
                    <span>Amount actually paid</span>
                    <strong>{money(totals.paid_minor)}</strong>
                </Card>
            </div>
            <Card>
                <form
                    className="list-toolbar"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            "/purchases",
                            { q },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <div className="search-field">
                        <Search size={17} />
                        <input
                            aria-label="Search purchases"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Purchase reference or product name"
                        />
                        <button className="text-link">Search</button>
                    </div>
                </form>
                {purchases.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reference / product</th>
                                    {isCompany && <th>Dealer</th>}
                                    <th>Quantity</th>
                                    <th>Total MRP</th>
                                    <th>Paid</th>
                                    <th>Status</th>
                                    <th>Breakdown</th>
                                </tr>
                            </thead>
                            <tbody>
                                {purchases.data.map((purchase) => (
                                    <tr key={purchase.id}>
                                        <td>
                                            {purchase.reference}
                                            <small className="cell-detail">
                                                {purchase.product_name}
                                            </small>
                                        </td>
                                        {isCompany && (
                                            <td>
                                                {purchase.dealer_name}
                                                <small className="cell-detail">
                                                    {purchase.dealer_code}
                                                </small>
                                            </td>
                                        )}
                                        <td>{purchase.quantity}</td>
                                        <td className="money-cell">
                                            {money(purchase.total_mrp_minor)}
                                        </td>
                                        <td>
                                            {money(purchase.amount_paid_minor)}
                                        </td>
                                        <td>
                                            <Badge status={purchase.status} />
                                        </td>
                                        <td>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setSelected(purchase);
                                                    cancel.reset();
                                                    cancel.clearErrors();
                                                }}
                                            >
                                                View breakdown
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        icon={<ShoppingBag size={30} />}
                        title="Your first purchase starts here."
                        description={
                            isCompany
                                ? "Record a dealer’s purchase to calculate the shares and credit their commission earnings."
                                : "Your company-recorded purchases will appear here."
                        }
                    />
                )}
                <Pagination page={purchases} />
            </Card>
            <Modal
                open={open}
                onOpenChange={setOpen}
                title="Record dealer purchase"
                description="Use a unique invoice or receipt reference. Saving in commission mode credits 50% of total MRP to this dealer."
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post("/purchases", {
                            preserveScroll: true,
                            onSuccess: () => {
                                setOpen(false);
                                form.reset();
                            },
                        });
                    }}
                >
                    <div className="form-grid">
                        {input(
                            "dealer_code",
                            "Dealer code",
                            "text",
                            "Use the active dealer’s code from Dealer management.",
                        )}
                        {input("reference", "Invoice / receipt reference")}
                        {input("product_name", "Product name")}
                        {input("quantity", "Quantity", "number")}
                        {input("unit_mrp", "Unit MRP (BDT)")}
                        {input(
                            "amount_paid",
                            "Total amount actually paid (BDT)",
                        )}
                        {input("purchased_on", "Purchase date", "date")}
                        {input(
                            "password",
                            "Current password",
                            "password",
                            "Required outside the local demo.",
                        )}
                    </div>
                    <div className="purchase-live-total">
                        <span>Total product MRP</span>
                        <strong>
                            {estimate > 0 && Number.isFinite(estimate)
                                ? money(estimate)
                                : "Enter MRP and quantity"}
                        </strong>
                    </div>
                    <Field label="Note (optional)" error={form.errors.note}>
                        <textarea
                            maxLength={500}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData("note", event.target.value)
                            }
                        />
                    </Field>
                    <p className="field-hint">
                        Company 30% · this dealer 50% · reward pool 20%. Pool
                        rewards await recipient eligibility rules.
                    </p>
                    <div className="modal-actions">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>
                            {form.processing ? "Recording…" : "Save purchase"}
                        </Button>
                    </div>
                </form>
            </Modal>
            {selected && (
                <Modal
                    open
                    onOpenChange={(value) => {
                        if (!value) setSelected(null);
                    }}
                    title={selected.reference}
                    description={`${selected.product_name} · ${selected.quantity} × ${money(selected.unit_mrp_minor)} · ${selected.purchased_on.slice(0, 10)}`}
                >
                    <div className="purchase-breakdown">
                        <div>
                            <span>Total product MRP</span>
                            <strong>{money(selected.total_mrp_minor)}</strong>
                        </div>
                        <div>
                            <span>Company · 30%</span>
                            <strong>
                                {money(selected.calculation.company_minor)}
                            </strong>
                        </div>
                        <div>
                            <span>Purchasing dealer · 50%</span>
                            <strong>
                                {money(selected.calculation.dealer_minor)}
                            </strong>
                        </div>
                        <div>
                            <span>Reward pool · 20%</span>
                            <strong>
                                {money(selected.calculation.pool_minor)}
                            </strong>
                        </div>
                    </div>
                    <Notice>
                        {selected.status === "cancelled"
                            ? "Cancelled. Original purchase and reversal remain in the history."
                            : selected.ledger_entry_id
                              ? "This dealer’s 50% share was credited to their earnings. Reward-pool distribution is pending eligibility."
                              : "Calculation preview only. No earnings were credited in rank mode."}
                    </Notice>
                    <details className="purchase-pool">
                        <summary>Reward-pool breakdown</summary>
                        {selected.calculation.rewards.map((reward) => (
                            <div key={reward.name}>
                                <span>
                                    {reward.name} ({reward.bps / 100}% of pool)
                                </span>
                                <strong>{money(reward.amount_minor)}</strong>
                            </div>
                        ))}
                        <div>
                            <span>Unallocated pool</span>
                            <strong>
                                {money(selected.calculation.unallocated_minor)}
                            </strong>
                        </div>
                        <p className="field-hint">
                            Pool amounts are allocations, not paid or posted
                            reward earnings.
                        </p>
                    </details>
                    {selected.note && (
                        <p className="field-hint">Note: {selected.note}</p>
                    )}
                    {selected.cancellation_reason && (
                        <p className="field-hint">
                            Cancellation: {selected.cancellation_reason}
                        </p>
                    )}
                    {isCompany && selected.status === "recorded" && (
                        <form
                            className="purchase-cancel"
                            onSubmit={(event) => {
                                event.preventDefault();
                                cancel.post(
                                    `/purchases/${selected.id}/cancel`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setSelected(null),
                                    },
                                );
                            }}
                        >
                            <h3>Correct this purchase</h3>
                            <p className="field-hint">
                                Cancellation reverses the dealer earning and
                                preserves history. Reserved or paid funds must
                                be reconciled first.
                            </p>
                            <Field
                                label="Cancellation reason"
                                error={cancel.errors.reason}
                            >
                                <textarea
                                    required
                                    minLength={5}
                                    maxLength={500}
                                    value={cancel.data.reason}
                                    onChange={(event) =>
                                        cancel.setData(
                                            "reason",
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Current password"
                                error={cancel.errors.password}
                            >
                                <input
                                    type="password"
                                    autoComplete="current-password"
                                    value={cancel.data.password}
                                    onChange={(event) =>
                                        cancel.setData(
                                            "password",
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {Object.entries(cancel.errors)
                                .filter(
                                    ([key]) =>
                                        !["reason", "password"].includes(key),
                                )
                                .map(([key, error]) => (
                                    <p className="field-error" key={key}>
                                        {error}
                                    </p>
                                ))}
                            <Button
                                variant="outline"
                                disabled={cancel.processing}
                            >
                                Cancel purchase & reverse earning
                            </Button>
                        </form>
                    )}
                </Modal>
            )}
        </Layout>
    );
}
