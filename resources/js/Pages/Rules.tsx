import { useState } from "react";
import { RewardTabs } from "@/components/RewardTabs";
import { useForm } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Layout } from "@/components/Layout";
import { Allocation } from "@/components/Allocation";
import { PercentageField } from "@/components/PercentageField";
import {
    Card,
    CardHeading,
    PageHeading,
    Badge,
    Button,
    Notice,
    Field,
    Modal,
} from "@/components/Common";
import { money, date, type Preview } from "@/lib/types";
type Definition = {
    company_bps: number;
    dealer_bps: number;
    pool_bps: number;
    rewards: Record<string, number>;
    referrals?: { generation_bps: number[] };
};
type Version = {
    id: number;
    name: string;
    status: string;
    effective_from: string | null;
    effective_to: string | null;
    definition: Definition;
    created_at: string;
};
export default function Rules({
    versions,
    activeVersionId,
    activeDefinition,
    ruleRevision,
    preview,
    purchaseTotals,
}: {
    versions: Version[];
    activeVersionId: number | null;
    activeDefinition: Definition;
    ruleRevision: number;
    preview: Preview | null;
    purchaseTotals: {
        company_minor: number;
        dealer_minor: number;
        pool_minor: number;
        unallocated_minor: number;
    };
}) {
    const [open, setOpen] = useState(false);
    const [versionId, setVersionId] = useState<number | undefined>();
    const [activation, setActivation] = useState<Version | null>(null);
    const form = useForm({
        name: "",
        company_bps: activeDefinition.company_bps,
        dealer_bps: activeDefinition.dealer_bps,
        pool_bps: activeDefinition.pool_bps,
        rewards: { ...activeDefinition.rewards },
        referral_bps: activeDefinition.referrals?.generation_bps ?? [0, 0, 0],
        reason: "",
        effective_from: new Intl.DateTimeFormat("en-CA", {
            timeZone: "Asia/Dhaka",
        }).format(new Date()),
        effective_to: "",
    });
    const approve = useForm({
        revision: ruleRevision,
        reason: "",
        password: "",
    });
    const start = (definition = activeDefinition, name = "") => {
        form.setData({
            ...form.data,
            name,
            company_bps: definition.company_bps,
            dealer_bps: definition.dealer_bps,
            pool_bps: definition.pool_bps,
            rewards: { ...definition.rewards },
            referral_bps: [
                ...(definition.referrals?.generation_bps ?? [0, 0, 0]),
            ],
        });
        form.clearErrors();
        setOpen(true);
    };
    const rate = (
        label: string,
        value: number,
        onChange: (bps: number) => void,
        error?: string,
    ) => (
        <PercentageField
            key={label}
            label={label}
            error={error}
            value={value}
            onChange={onChange}
        />
    );
    const rewardTotal = Object.values(form.data.rewards).reduce(
        (total, bps) => total + bps,
        0,
    );
    return (
        <Layout title="Commission & rewards">
            <RewardTabs active="rules" admin />
            <PageHeading
                eyebrow="CONTROL YOUR BUSINESS RULES"
                title="Commission & reward rules"
                description="Edit the purchasing dealer share, company share, reward categories, and three-generation referral percentages."
                action={
                    <Button onClick={() => start()}>
                        <Plus size={17} /> Edit percentages
                    </Button>
                }
            />
            <Notice>
                Active purchase model: company{" "}
                {activeDefinition.company_bps / 100}%, purchasing dealer{" "}
                {activeDefinition.dealer_bps / 100}%, reward pool{" "}
                {activeDefinition.pool_bps / 100}%.{" "}
                {activeVersionId
                    ? `Rule V${activeVersionId}`
                    : "Original confirmed model"}
                . Changes apply to new purchases after activation.
            </Notice>
            <Notice tone="warning">
                Area dealer commission is restricted to company-approved area
                dealers. Ordinary dealers can still earn purchase commission and
                other eligible rewards. Three-generation referrals are tracked.
                The referral commission base and payout eligibility are pending
                discussion. Generation percentages can be saved now; they do not
                create referral earnings yet.
            </Notice>
            <Card>
                <CardHeading
                    title="Reward categories"
                    subtitle="Each percentage below is a share of the reward pool. Allocations remain reserved for future eligibility rules."
                />
                <div className="purchase-breakdown">
                    {Object.entries(activeDefinition.rewards).map(
                        ([name, bps]) => (
                            <div key={name}>
                                <span>{name}</span>
                                <strong>{bps / 100}% of pool</strong>
                            </div>
                        ),
                    )}
                </div>
                <p className="field-hint">
                    Unallocated:{" "}
                    {(10000 -
                        Object.values(activeDefinition.rewards).reduce(
                            (sum, value) => sum + value,
                            0,
                        )) /
                        100}
                    % of the pool.
                </p>
            </Card>
            <Card className="purchase-allocation-summary">
                <CardHeading
                    title="Allocations from recorded purchases"
                    subtitle="Historical totals use each purchase’s saved rule version."
                />
                <div className="purchase-breakdown">
                    {[
                        ["Company share", purchaseTotals.company_minor],
                        ["Dealer earnings", purchaseTotals.dealer_minor],
                        ["Reward pool", purchaseTotals.pool_minor],
                        ["Unallocated pool", purchaseTotals.unallocated_minor],
                    ].map(([label, amount]) => (
                        <div key={String(label)}>
                            <span>{label}</span>
                            <strong>{money(Number(amount))}</strong>
                        </div>
                    ))}
                </div>
            </Card>
            <div className="rule-version-selector">
                <label htmlFor="rule-version">Preview rule version</label>
                <select
                    id="rule-version"
                    value={versionId ?? ""}
                    onChange={(event) =>
                        setVersionId(
                            event.target.value
                                ? Number(event.target.value)
                                : undefined,
                        )
                    }
                >
                    <option value="">Active purchase model</option>
                    {versions.map((version) => (
                        <option key={version.id} value={version.id}>
                            V{version.id} · {version.name}
                        </option>
                    ))}
                </select>
            </div>
            <Allocation
                key={versionId ?? "active"}
                preview={preview}
                detailed
                versionId={versionId}
            />
            <Card>
                <CardHeading
                    title="Rule version history"
                    subtitle="Edit creates a new version. Activation changes future purchases, while historical records stay intact."
                />
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Effective from</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {versions.map((version) => (
                                <tr key={version.id}>
                                    <td>V{version.id}</td>
                                    <td>{version.name}</td>
                                    <td>
                                        <Badge
                                            status={
                                                version.id === activeVersionId
                                                    ? "active"
                                                    : "pending"
                                            }
                                        >
                                            {version.id === activeVersionId
                                                ? "Active"
                                                : version.status === "reference"
                                                  ? "Reference"
                                                  : "Draft"}
                                        </Badge>
                                    </td>
                                    <td>
                                        {version.effective_from
                                            ? date(version.effective_from)
                                            : "No date limit"}
                                    </td>
                                    <td>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                start(
                                                    version.definition,
                                                    `${version.name} revision`,
                                                )
                                            }
                                        >
                                            Edit as new version
                                        </Button>
                                        {version.id !== activeVersionId && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    approve.reset();
                                                    approve.clearErrors();
                                                    approve.setData(
                                                        "revision",
                                                        ruleRevision,
                                                    );
                                                    setActivation(version);
                                                }}
                                            >
                                                Activate
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
            <Modal
                open={open}
                onOpenChange={setOpen}
                title="Edit commission and reward percentages"
                description="Save a version, preview it, then activate it for future purchase entries."
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post("/rules", {
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <Field label="Rule name" error={form.errors.name}>
                        <input
                            required
                            maxLength={100}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData("name", event.target.value)
                            }
                        />
                    </Field>
                    <h3>Percentages of total product MRP</h3>
                    <div className="form-grid">
                        {rate(
                            "Company (%)",
                            form.data.company_bps,
                            (value) => form.setData("company_bps", value),
                            form.errors.company_bps,
                        )}
                        {rate(
                            "Purchasing dealer (%)",
                            form.data.dealer_bps,
                            (value) => form.setData("dealer_bps", value),
                            form.errors.dealer_bps,
                        )}
                        {rate(
                            "Reward pool (%)",
                            form.data.pool_bps,
                            (value) => form.setData("pool_bps", value),
                            form.errors.pool_bps,
                        )}
                    </div>
                    <p>
                        Total:{" "}
                        {(form.data.company_bps +
                            form.data.dealer_bps +
                            form.data.pool_bps) /
                            100}
                        % · must equal 100%.
                    </p>
                    <h3>Reward categories · percentages of the pool</h3>
                    <div className="form-grid">
                        {Object.entries(form.data.rewards).map(([name, bps]) =>
                            rate(
                                `${name} (%)`,
                                bps,
                                (value) =>
                                    form.setData("rewards", {
                                        ...form.data.rewards,
                                        [name]: value,
                                    }),
                                (form.errors as Record<string, string>)[
                                    `rewards.${name}`
                                ],
                            ),
                        )}
                    </div>
                    <p>
                        Allocated: {rewardTotal / 100}% · unallocated:{" "}
                        {(10000 - rewardTotal) / 100}%.
                    </p>
                    <h3>Referral generations · calculation base pending</h3>
                    <div className="form-grid">
                        {form.data.referral_bps.map((bps, index) =>
                            rate(
                                `Generation ${index + 1} (%)`,
                                bps,
                                (value) =>
                                    form.setData(
                                        "referral_bps",
                                        form.data.referral_bps.map((old, i) =>
                                            i === index ? value : old,
                                        ),
                                    ),
                                (form.errors as Record<string, string>)[
                                    `referral_bps.${index}`
                                ],
                            ),
                        )}
                    </div>
                    <p className="field-hint">
                        These percentages are recorded for discussion. Referral
                        earnings remain inactive until the calculation base is
                        approved.
                    </p>
                    <div className="form-grid">
                        <Field
                            label="Effective from"
                            error={form.errors.effective_from}
                        >
                            <input
                                type="date"
                                required
                                value={form.data.effective_from}
                                onChange={(event) =>
                                    form.setData(
                                        "effective_from",
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Effective to (optional)"
                            error={form.errors.effective_to}
                        >
                            <input
                                type="date"
                                value={form.data.effective_to}
                                onChange={(event) =>
                                    form.setData(
                                        "effective_to",
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        label="Reason for this version"
                        error={form.errors.reason}
                    >
                        <textarea
                            required
                            minLength={10}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData("reason", event.target.value)
                            }
                        />
                    </Field>
                    {Object.entries(form.errors)
                        .filter(([key]) =>
                            ["rates", "referral_bps"].includes(key),
                        )
                        .map(([key, error]) => (
                            <Notice key={key} tone="warning">
                                {error}
                            </Notice>
                        ))}
                    <div className="modal-actions">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>
                            Save draft version
                        </Button>
                    </div>
                </form>
            </Modal>
            <Modal
                open={!!activation}
                onOpenChange={(value) => !value && setActivation(null)}
                title={`Activate V${activation?.id}`}
                description="This changes the purchasing dealer share for new purchases. Referral payouts remain inactive."
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        approve.post(`/rules/${activation?.id}/activate`, {
                            onSuccess: () => setActivation(null),
                        });
                    }}
                >
                    <Field
                        label="Activation reason"
                        error={approve.errors.reason}
                    >
                        <textarea
                            required
                            minLength={5}
                            value={approve.data.reason}
                            onChange={(event) =>
                                approve.setData("reason", event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Current password"
                        hint="Required outside the local demo."
                        error={approve.errors.password}
                    >
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={approve.data.password}
                            onChange={(event) =>
                                approve.setData("password", event.target.value)
                            }
                        />
                    </Field>
                    {Object.entries(approve.errors)
                        .filter(
                            ([key]) => !["reason", "password"].includes(key),
                        )
                        .map(([key, value]) => (
                            <Notice key={key} tone="warning">
                                {value}
                            </Notice>
                        ))}
                    <Button disabled={approve.processing}>
                        Activate purchase rule
                    </Button>
                </form>
            </Modal>
        </Layout>
    );
}
