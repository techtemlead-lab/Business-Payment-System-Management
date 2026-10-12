import { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import {
    ArrowUpRight,
    Building2,
    Mail,
    Phone,
    MapPin,
    Search,
} from "lucide-react";
import { Layout } from "@/components/Layout";
import { DealerForm } from "@/components/DealerForm";
import {
    Badge,
    Button,
    Card,
    Empty,
    Field,
    Modal,
    PageHeading,
    Pagination,
    Notice,
} from "@/components/Common";
import { date, type Page } from "@/lib/types";
type Enquiry = {
    id: number;
    name: string;
    email: string;
    mobile: string;
    business_name: string;
    business_address: string;
    dealer_type: "dealer" | "area_dealer";
    level: string;
    division: string;
    district: string | null;
    upazila: string | null;
    union_name: string | null;
    message: string | null;
    status: string;
    review_note: string | null;
    created_at: string;
    additional_answers?: { label: string; value: string }[];
    referral_code?: string | null;
};
export default function DealershipRequests({
    requests,
    filters,
    counts,
}: {
    requests: Page<Enquiry>;
    filters: { q: string; status: string };
    counts: Record<string, number>;
}) {
    const [q, setQ] = useState(filters.q);
    const [selected, setSelected] = useState<Enquiry | null>(null);
    const [create, setCreate] = useState<Enquiry | null>(null);
    const review = useForm({ status: "reviewed", reason: "", password: "" });
    const filter = (status = filters.status) =>
        router.get(
            "/dealership-requests",
            { q, status },
            { preserveState: true, replace: true },
        );
    return (
        <Layout title="Dealership requests">
            <PageHeading
                eyebrow="NEW CONNECTIONS"
                title="People ready to partner."
                description="Review requests from your website. Dealer accounts are created by your company."
            />
            <div className="request-status-tabs" aria-label="Filter requests">
                {["pending", "reviewed", "declined", "all"].map((status) => (
                    <button
                        key={status}
                        aria-pressed={filters.status === status}
                        onClick={() => filter(status)}
                    >
                        {status === "all" ? "All requests" : status}
                        <span>
                            {status === "all"
                                ? Object.values(counts).reduce(
                                      (sum, count) => sum + Number(count),
                                      0,
                                  )
                                : (counts[status] ?? 0)}
                        </span>
                    </button>
                ))}
            </div>
            <Card>
                <form
                    className="list-toolbar"
                    onSubmit={(event) => {
                        event.preventDefault();
                        filter();
                    }}
                >
                    <div className="search-field">
                        <Search size={17} />
                        <input
                            aria-label="Search requests"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Name, business, or email"
                        />
                        <button className="text-link" type="submit">
                            Search
                        </button>
                    </div>
                </form>
                {requests.data.length ? (
                    <div className="enquiry-list">
                        {requests.data.map((request) => (
                            <article key={request.id} className="enquiry-row">
                                <div className="enquiry-icon">
                                    <Building2 size={21} />
                                </div>
                                <div>
                                    <h2>{request.business_name}</h2>
                                    <p>
                                        {request.name} · {request.level} ·{" "}
                                        {request.division}
                                    </p>
                                    <small>
                                        Received {date(request.created_at)}
                                    </small>
                                </div>
                                <Badge status={request.status} />
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSelected(request);
                                        review.reset();
                                        review.clearErrors();
                                        review.setData(
                                            "status",
                                            request.status === "pending"
                                                ? "reviewed"
                                                : request.status,
                                        );
                                    }}
                                >
                                    View request <ArrowUpRight size={16} />
                                </Button>
                            </article>
                        ))}
                    </div>
                ) : (
                    <Empty
                        title="Room for new connections."
                        description="Requests from the public dealership form appear here. Try another status or search."
                    />
                )}
                <Pagination page={requests} />
            </Card>
            {selected && (
                <Modal
                    open
                    onOpenChange={(open) => {
                        if (!open) setSelected(null);
                    }}
                    title={selected.business_name}
                    description={`Request from ${selected.name} · ${selected.level}`}
                >
                    <div className="enquiry-details">
                        <p>
                            <strong>Requested account type:</strong>{" "}
                            {selected.dealer_type === "area_dealer"
                                ? "Area dealer"
                                : "Dealer"}{" "}
                            · company approval required
                        </p>
                        {selected.referral_code && (
                            <p>
                                <strong>Referred by:</strong>{" "}
                                {selected.referral_code}
                            </p>
                        )}
                        <p>
                            <Mail size={17} />
                            <a href={`mailto:${selected.email}`}>
                                {selected.email}
                            </a>
                        </p>
                        <p>
                            <Phone size={17} />
                            <a href={`tel:${selected.mobile}`}>
                                {selected.mobile}
                            </a>
                        </p>
                        <p>
                            <MapPin size={17} />
                            <span>
                                {[
                                    selected.division,
                                    selected.district,
                                    selected.upazila,
                                    selected.union_name,
                                ]
                                    .filter(Boolean)
                                    .join(" / ")}
                                <br />
                                {selected.business_address}
                            </span>
                        </p>
                        {selected.message && (
                            <blockquote>{selected.message}</blockquote>
                        )}
                        {selected.additional_answers?.map((answer, index) => (
                            <p key={index}>
                                <strong>{answer.label}:</strong> {answer.value}
                            </p>
                        ))}
                        {selected.review_note && (
                            <p className="field-hint">
                                Previous review: {selected.review_note}
                            </p>
                        )}
                    </div>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            review.patch(
                                `/dealership-requests/${selected.id}`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setSelected(null),
                                },
                            );
                        }}
                    >
                        <Field
                            label="Review status"
                            error={review.errors.status}
                        >
                            <select
                                value={review.data.status}
                                onChange={(event) =>
                                    review.setData("status", event.target.value)
                                }
                            >
                                <option value="pending">Pending</option>
                                <option value="reviewed">Reviewed</option>
                                <option value="declined">Declined</option>
                            </select>
                        </Field>
                        <Field label="Review note" error={review.errors.reason}>
                            <textarea
                                required
                                minLength={5}
                                maxLength={500}
                                value={review.data.reason}
                                onChange={(event) =>
                                    review.setData("reason", event.target.value)
                                }
                                placeholder="Record your review or the next step."
                            />
                        </Field>
                        <Field
                            label="Current password"
                            hint="Required outside the local demo."
                            error={review.errors.password}
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
                        <div className="modal-actions">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setCreate(selected);
                                    setSelected(null);
                                }}
                            >
                                Create dealer account
                            </Button>
                            <Button disabled={review.processing}>
                                Save review
                            </Button>
                        </div>
                        <p className="field-hint">
                            Saving a review does not create or activate an
                            account.
                        </p>
                    </form>
                </Modal>
            )}
            {create && (
                <DealerForm
                    key={create.id}
                    applicationId={create.id}
                    open
                    onOpenChange={(open) => {
                        if (!open) setCreate(null);
                    }}
                    initial={{
                        name: create.name,
                        email: create.email,
                        mobile: create.mobile,
                        business_name: create.business_name,
                        dealer_type: create.dealer_type,
                        level: create.level,
                        division: create.division,
                        district: create.district ?? "",
                        upazila: create.upazila ?? "",
                        union_name: create.union_name ?? "",
                        referrer_code: create.referral_code ?? "",
                    }}
                />
            )}
        </Layout>
    );
}
