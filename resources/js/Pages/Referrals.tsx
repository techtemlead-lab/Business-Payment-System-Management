import { useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import { Layout } from "@/components/Layout";
import { ManageReferral } from "@/components/ManageReferral";
import {
    Card,
    CardHeading,
    PageHeading,
    Field,
    Button,
    Notice,
    Pagination,
    Badge,
} from "@/components/Common";
import { type Shared, type Page, date } from "@/lib/types";
type Row = {
    id: number;
    code: string;
    name: string;
    status: string;
    referrer_code: string | null;
    referrer_name?: string | null;
    referral_verified_at?: string | null;
    referral_verified_by_name?: string | null;
    created_at: string;
};
export default function Referrals({
    root,
    counts,
    generation,
    dealers,
    ancestors,
    referralLink,
}: {
    root: { id: number; code: string } | null;
    counts: number[];
    generation: number;
    dealers: Page<Row>;
    ancestors: { generation: number; code: string; status: string }[];
    referralLink: string | null;
}) {
    const admin = usePage<Shared>().props.auth.user?.role === "admin";
    const [code, setCode] = useState(root?.code ?? "");
    const [copied, setCopied] = useState(false);
    const filter = (level: number) =>
        router.get(
            "/referrals",
            {
                ...(admin ? { code: root?.code ?? code } : {}),
                generation: level,
            },
            { preserveState: true },
        );
    return (
        <Layout title={admin ? "Referral network" : "My referrals"}>
            <PageHeading
                eyebrow="THREE GENERATIONS, ONE TRACEABLE NETWORK"
                title={admin ? "Referral network" : "Your referral network"}
                description="Generation 1 is your direct referrals; generation 2 is their referrals; generation 3 is the next level."
            />
            <Notice tone="warning">
                Referral relationships are tracked. Referral earnings await the
                commission-base and eligibility discussion.
            </Notice>
            {admin && (
                <Card>
                    <form
                        className="card-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get("/referrals", { code, generation: 1 });
                        }}
                    >
                        <Field label="View dealer network (dealer code)">
                            <input
                                value={code}
                                onChange={(event) =>
                                    setCode(event.target.value.toUpperCase())
                                }
                                placeholder="Enter a dealer code"
                            />
                        </Field>
                        <Button>View three generations</Button>
                    </form>
                </Card>
            )}
            {root && (
                <>
                    <Card>
                        <CardHeading
                            title={`Referral code: ${root.code}`}
                            subtitle="Share this application link. Account creation remains with the company."
                        />
                        <Field label="Referral link">
                            <input readOnly value={referralLink ?? ""} />
                        </Field>
                        <Button
                            variant="outline"
                            onClick={() =>
                                void navigator.clipboard
                                    .writeText(referralLink ?? "")
                                    .then(() => setCopied(true))
                            }
                        >
                            {copied ? "Copied" : "Copy referral link"}
                        </Button>
                        {ancestors.length > 0 && (
                            <p className="field-hint">
                                Your referral ancestors:{" "}
                                {ancestors
                                    .map(
                                        (row) =>
                                            `G${row.generation}: ${row.code}`,
                                    )
                                    .join(" → ")}
                            </p>
                        )}
                    </Card>
                    <div className="stats-grid">
                        {counts.map((count, index) => (
                            <Card key={index}>
                                <Button
                                    variant={
                                        generation === index + 1
                                            ? "default"
                                            : "outline"
                                    }
                                    onClick={() => filter(index + 1)}
                                >
                                    Generation {index + 1}
                                </Button>
                                <h2>{count} dealers</h2>
                            </Card>
                        ))}
                    </div>
                </>
            )}
            <Card>
                <CardHeading
                    title={
                        root
                            ? `Generation ${generation} · ${root.code}`
                            : "Company dealer referral records"
                    }
                    subtitle="Referrals are separate from geographical dealer levels."
                />
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Dealer</th>
                                <th>Code</th>
                                <th>Direct referrer</th>
                                <th>Status</th>
                                <th>Created</th>
                                {admin && <th>Actions</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {dealers.data.map((dealer) => (
                                <tr key={dealer.id}>
                                    <td>{dealer.name}</td>
                                    <td>{dealer.code}</td>
                                    <td>
                                        {dealer.referrer_code ??
                                            "No referrer recorded"}
                                    </td>
                                    <td>
                                        <Badge status={dealer.status} />
                                    </td>
                                    <td>{date(dealer.created_at)}</td>
                                    {admin && (
                                        <td>
                                            <ManageReferral dealer={dealer} />{" "}
                                            <Link
                                                href={`/referrals?code=${encodeURIComponent(dealer.code)}`}
                                            >
                                                View network
                                            </Link>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {!dealers.data.length && (
                        <p>No dealers in this generation yet.</p>
                    )}
                </div>
                <Pagination page={dealers} />
            </Card>
        </Layout>
    );
}
