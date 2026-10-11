import { useEffect, useState } from "react";
import {
    CircleDollarSign,
    ArrowRight,
    AlertCircle,
    FlaskConical,
    RefreshCw,
} from "lucide-react";
import { type Preview, money } from "@/lib/types";
import { Card, CardHeading, Field, Button, Notice, Badge } from "./Common";
export function Allocation({
    preview: initial,
    detailed = false,
    versionId,
}: {
    preview: Preview;
    detailed?: boolean;
    versionId?: number;
}) {
    const [preview, setPreview] = useState(initial);
    const [mrp, setMrp] = useState("200.00");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    async function calculate() {
        setLoading(true);
        setError("");
        try {
            const res = await fetch("/rules/preview", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": decodeURIComponent(
                        document.cookie
                            .split("; ")
                            .find((value) => value.startsWith("XSRF-TOKEN="))
                            ?.slice(11) ?? "",
                    ),
                },
                body: JSON.stringify({ mrp, version_id: versionId }),
            });
            const data = await res.json();
            if (!res.ok)
                throw new Error(
                    data.errors?.mrp?.[0] ??
                        data.message ??
                        "Unable to preview this amount.",
                );
            setPreview(data);
        } catch (e) {
            setError(e instanceof Error ? e.message : "Unable to preview.");
        } finally {
            setLoading(false);
        }
    }
    useEffect(() => {
        if (detailed && versionId !== undefined) {
            void calculate();
        }
    }, [versionId]);
    return (
        <Card
            className={`allocation-card ${detailed ? "allocation-detailed" : ""}`}
        >
            <CardHeading
                title="Every taka, accounted for."
                subtitle={
                    versionId === undefined
                        ? "The confirmed commission model, made clear."
                        : "Selected rule version · preview only."
                }
                action={
                    <span className="preview-tag">
                        <FlaskConical size={13} /> Simulation
                    </span>
                }
            />
            <div className="allocation-content">
                <div className="allocation-donut">
                    <svg
                        viewBox="0 0 180 180"
                        role="img"
                        aria-label={`MRP allocation: ${preview.dealer_bps / 100}% dealer, ${preview.company_bps / 100}% company, ${preview.pool_bps / 100}% reward pool`}
                    >
                        <circle
                            cx="90"
                            cy="90"
                            r="71"
                            fill="none"
                            stroke="#edf1ee"
                            strokeWidth="17"
                        />
                        {[
                            {
                                color: "#174d3b",
                                pct: preview.dealer_bps / 100,
                                offset: 0,
                            },
                            {
                                color: "#a8c98a",
                                pct: preview.company_bps / 100,
                                offset: preview.dealer_bps / 100,
                            },
                            {
                                color: "#e5d5ad",
                                pct: preview.pool_bps / 100,
                                offset:
                                    (preview.dealer_bps + preview.company_bps) /
                                    100,
                            },
                        ].map((seg) => (
                            <circle
                                key={seg.color}
                                cx="90"
                                cy="90"
                                r="71"
                                fill="none"
                                stroke={seg.color}
                                strokeWidth="17"
                                style={{
                                    visibility:
                                        seg.pct === 0 ? "hidden" : "visible",
                                }}
                                strokeDasharray={`${Math.max(0, seg.pct * 4.46 - 5)} ${446 - seg.pct * 4.46 + 5}`}
                                strokeDashoffset={-seg.offset * 4.46}
                                transform="rotate(-90 90 90)"
                                strokeLinecap="round"
                            />
                        ))}
                    </svg>
                    <div className="donut-label">
                        <span>PRODUCT MRP</span>
                        <strong>{money(preview.mrp_minor)}</strong>
                        <span>Total allocation · 100%</span>
                    </div>
                </div>
                <div className="allocation-legend">
                    {[
                        {
                            label: "Dealer commission",
                            pct: `${preview.dealer_bps / 100}%`,
                            value: preview.dealer_minor,
                            color: "forest",
                            hint: "Distribution awaiting approval",
                        },
                        {
                            label: "Company commission",
                            pct: `${preview.company_bps / 100}%`,
                            value: preview.company_minor,
                            color: "lime",
                            hint: "Of product MRP",
                        },
                        {
                            label: "Reward pool",
                            pct: `${preview.pool_bps / 100}%`,
                            value: preview.pool_minor,
                            color: "sand",
                            hint: "Included in MRP",
                        },
                    ].map((row) => (
                        <div className="legend-row" key={row.label}>
                            <span className={`legend-dot ${row.color}`} />
                            <div>
                                <span>
                                    {row.label} <small>{row.pct}</small>
                                </span>
                                <p>{row.hint}</p>
                            </div>
                            <strong>{money(row.value)}</strong>
                        </div>
                    ))}
                </div>
            </div>
            <div className="allocation-footer">
                <span>
                    <AlertCircle size={15} /> {money(preview.unallocated_minor)}{" "}
                    of the reward pool remains unallocated
                </span>
                {!detailed && (
                    <span className="field-hint">No ledger entries posted</span>
                )}
            </div>
            {detailed && (
                <>
                    <div className="preview-form">
                        <Field label="Product MRP (BDT)" error={error}>
                            <input
                                type="text"
                                inputMode="decimal"
                                value={mrp}
                                onChange={(e) => setMrp(e.target.value)}
                                aria-label="Product MRP (BDT)"
                            />
                        </Field>
                        <Button onClick={calculate} disabled={loading}>
                            <RefreshCw
                                size={16}
                                className={loading ? "spin" : ""}
                            />
                            {loading ? "Calculating..." : "Run preview"}
                        </Button>
                    </div>
                    <Notice>
                        Preview only. These amounts are calculated on the server
                        and do not create earnings or ledger entries.
                    </Notice>
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reward category</th>
                                    <th>% of pool</th>
                                    <th>Preview amount</th>
                                    <th>Posting status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {preview.rewards.map((r) => (
                                    <tr key={r.name}>
                                        <td>{r.name}</td>
                                        <td>{r.bps / 100}%</td>
                                        <td className="money-cell">
                                            {money(r.amount_minor)}
                                        </td>
                                        <td>
                                            <Badge status="pending">
                                                Needs definition
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                                <tr className="total-row">
                                    <td>Unallocated balance</td>
                                    <td>
                                        {(10000 - preview.allocated_bps) / 100}%
                                    </td>
                                    <td className="money-cell">
                                        {money(preview.unallocated_minor)}
                                    </td>
                                    <td>
                                        <Badge status="neutral">
                                            Held unallocated
                                        </Badge>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </Card>
    );
}
