import { useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import { Wallet, ArrowUpRight } from "lucide-react";
import {
    Button,
    Card,
    CardHeading,
    Badge,
    Field,
    Modal,
    Empty,
    Pagination,
    Notice,
} from "./Common";
import {
    money,
    date,
    type Shared,
    type Balances,
    type Page,
} from "@/lib/types";
export type Withdrawal = {
    id: number;
    dealer_id: number;
    dealer_name: string;
    dealer_code: string;
    amount_minor: number;
    status: string;
    note: string | null;
    review_note: string | null;
    created_at: string;
};
export function WithdrawalPanel({
    withdrawals,
    balances,
}: {
    withdrawals: Page<Withdrawal>;
    balances: Balances | null;
}) {
    const isCompany = usePage<Shared>().props.auth.user?.role === "admin";
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<Withdrawal | null>(null);
    const request = useForm({ amount: "", note: "", idempotency_key: "" });
    const review = useForm({ status: "approved", reason: "", password: "" });
    const available = balances?.available_minor ?? 0;
    return (
        <>
            {!isCompany && (
                <Card className="withdrawal-balance">
                    <div>
                        <span className="eyebrow">AVAILABLE TO REQUEST</span>
                        <h2>{money(available)}</h2>
                        <p>
                            {available > 0
                                ? "Withdraw from your posted earnings. Pending requests reserve their amount."
                                : "Your withdrawal becomes available when earnings are posted to your account."}
                        </p>
                    </div>
                    <Button
                        disabled={available <= 0}
                        onClick={() => {
                            request.reset();
                            request.clearErrors();
                            request.setData(
                                "idempotency_key",
                                crypto.randomUUID(),
                            );
                            setOpen(true);
                        }}
                    >
                        Request withdrawal <ArrowUpRight size={17} />
                    </Button>
                </Card>
            )}
            <Card>
                <CardHeading
                    title={
                        isCompany
                            ? "Withdrawal requests"
                            : "Your withdrawal requests"
                    }
                    subtitle={
                        isCompany
                            ? "Review requests from dealers. Approval is a review decision; payment is a separate step."
                            : "Follow the company review of your requests."
                    }
                />
                {withdrawals.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request</th>
                                    {isCompany && <th>Dealer</th>}
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Received</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {withdrawals.data.map((withdrawal) => (
                                    <tr key={withdrawal.id}>
                                        <td>#{withdrawal.id}</td>
                                        {isCompany && (
                                            <td>
                                                {withdrawal.dealer_name}
                                                <small className="cell-detail">
                                                    {withdrawal.dealer_code}
                                                </small>
                                            </td>
                                        )}
                                        <td className="money-cell">
                                            {money(withdrawal.amount_minor)}
                                        </td>
                                        <td>
                                            <Badge status={withdrawal.status} />
                                        </td>
                                        <td>{date(withdrawal.created_at)}</td>
                                        <td>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setSelected(withdrawal);
                                                    review.reset();
                                                    review.clearErrors();
                                                    review.setData(
                                                        "status",
                                                        isCompany
                                                            ? withdrawal.status ===
                                                              "approved"
                                                                ? "declined"
                                                                : "approved"
                                                            : "cancelled",
                                                    );
                                                }}
                                            >
                                                View request
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        icon={<Wallet size={28} />}
                        title="No withdrawal requests yet"
                        description={
                            isCompany
                                ? "Dealer requests appear here for your review."
                                : "Requests you submit from your available earnings will appear here."
                        }
                    />
                )}
                <Pagination page={withdrawals} />
            </Card>
            <Modal
                open={open}
                onOpenChange={setOpen}
                title="Request withdrawal"
                description={`Available earned balance: ${money(available)}`}
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        request.post("/withdrawal-requests", {
                            preserveScroll: true,
                            onSuccess: () => {
                                setOpen(false);
                                request.reset();
                            },
                        });
                    }}
                >
                    <Field
                        label="Withdrawal amount (BDT)"
                        error={request.errors.amount}
                    >
                        <input
                            type="text"
                            inputMode="decimal"
                            required
                            value={request.data.amount}
                            onChange={(event) =>
                                request.setData("amount", event.target.value)
                            }
                            placeholder="Enter an amount"
                        />
                    </Field>
                    <Field label="Note (optional)" error={request.errors.note}>
                        <textarea
                            maxLength={500}
                            value={request.data.note}
                            onChange={(event) =>
                                request.setData("note", event.target.value)
                            }
                        />
                    </Field>
                    {request.errors.idempotency_key && (
                        <p className="field-error">
                            {request.errors.idempotency_key}
                        </p>
                    )}
                    <p className="field-hint">
                        The amount is reserved while the company reviews your
                        request. This does not send a payment. Keep account
                        passwords and payment credentials out of the note.
                    </p>
                    <div className="modal-actions">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={request.processing}>
                            {request.processing
                                ? "Sending…"
                                : "Send withdrawal request"}
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
                    title={`Withdrawal #${selected.id}`}
                    description={`${money(selected.amount_minor)} · ${selected.status}`}
                >
                    <div className="enquiry-details">
                        {isCompany && (
                            <p>
                                {selected.dealer_name} · {selected.dealer_code}
                            </p>
                        )}
                        {selected.note && (
                            <blockquote>{selected.note}</blockquote>
                        )}
                        {selected.review_note && (
                            <p>Company review: {selected.review_note}</p>
                        )}
                    </div>
                    {selected.status === "pending" ||
                    (isCompany && selected.status === "approved") ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                review.patch(
                                    `/withdrawal-requests/${selected.id}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setSelected(null),
                                    },
                                );
                            }}
                        >
                            {isCompany && (
                                <Field
                                    label="Review decision"
                                    error={review.errors.status}
                                >
                                    <select
                                        value={review.data.status}
                                        onChange={(event) =>
                                            review.setData(
                                                "status",
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {selected.status === "pending" && (
                                            <option value="approved">
                                                Approve for payment processing
                                            </option>
                                        )}
                                        <option value="declined">
                                            Decline and release funds
                                        </option>
                                    </select>
                                </Field>
                            )}
                            <Field
                                label={
                                    isCompany
                                        ? "Review note"
                                        : "Cancellation reason"
                                }
                                error={review.errors.reason}
                            >
                                <textarea
                                    required
                                    minLength={5}
                                    maxLength={500}
                                    value={review.data.reason}
                                    onChange={(event) =>
                                        review.setData(
                                            "reason",
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Current password"
                                error={review.errors.password}
                                hint="Required outside the local demo."
                            >
                                <input
                                    type="password"
                                    autoComplete="current-password"
                                    value={review.data.password}
                                    onChange={(event) =>
                                        review.setData(
                                            "password",
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {(review.errors as Record<string, string>)
                                .withdrawal && (
                                <p className="field-error">
                                    {
                                        (
                                            review.errors as Record<
                                                string,
                                                string
                                            >
                                        ).withdrawal
                                    }
                                </p>
                            )}
                            <div className="modal-actions">
                                <Button disabled={review.processing}>
                                    {isCompany
                                        ? "Save withdrawal review"
                                        : "Cancel withdrawal request"}
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <Notice>
                            {selected.status === "approved"
                                ? "Approved requests remain reserved for verified payment processing. Approval does not mean paid."
                                : "This request is closed and its reserved funds have been released."}
                        </Notice>
                    )}
                    {isCompany && selected.status === "approved" && (
                        <div className="withdrawal-release">
                            <h3>Release payment</h3>
                            <p className="field-hint">
                                This request is ready for payment processing.
                                Connect your payout provider and verify its
                                disbursement flow to enable release.
                            </p>
                            <Button disabled>Release payment</Button>
                            <p className="field-hint">
                                Provider integration pending. The amount remains
                                reserved and is not marked paid.
                            </p>
                        </div>
                    )}
                </Modal>
            )}
        </>
    );
}
