import { useEffect, useState } from "react";
import { Field, Notice } from "./Common";

type Option = { code: string; name: string; status: string };
export function ReferrerPicker({
    choice,
    code,
    verified,
    onChoice,
    onCode,
    onVerified,
    errors = {},
    exclude,
}: {
    choice: string;
    code: string;
    verified: boolean;
    onChoice: (value: string) => void;
    onCode: (value: string) => void;
    onVerified: (value: boolean) => void;
    errors?: Record<string, string>;
    exclude?: number;
}) {
    const [search, setSearch] = useState(code);
    const [options, setOptions] = useState<Option[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [selected, setSelected] = useState<Option | null>(null);
    useEffect(() => {
        if (choice !== "referred") return;
        const controller = new AbortController();
        const timeout = setTimeout(() => {
            setLoading(true);
            setFailed(false);
            const params = new URLSearchParams({
                q: search,
                ...(exclude ? { exclude: String(exclude) } : {}),
            });
            void fetch(`/referrals/options?${params}`, {
                headers: { Accept: "application/json" },
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) throw new Error("Lookup unavailable");
                    return response.json();
                })
                .then((result: { dealers: Option[] }) => {
                    if (!controller.signal.aborted) {
                        setOptions(result.dealers);
                    }
                })
                .catch(() => {
                    if (!controller.signal.aborted) setFailed(true);
                })
                .finally(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 250);
        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [choice, search, exclude]);
    const choose = (option: Option) => {
        setSelected(option);
        onCode(option.code);
        onVerified(false);
    };
    const selectedOption =
        selected ?? options.find((option) => option.code === code);
    return (
        <div className="my-5 rounded-xl border border-gray-200 bg-gray-50 p-5">
            <h3 className="mb-3 font-semibold">
                Referral connection · company verification
            </h3>
            <Field label="Referral option" error={errors.referral_choice}>
                <select
                    value={choice}
                    onChange={(event) => {
                        onChoice(event.target.value);
                        onCode("");
                        onVerified(false);
                        setSelected(null);
                        setSearch("");
                    }}
                >
                    <option value="none">Continue without referral</option>
                    <option value="referred">Connect a referring dealer</option>
                </select>
            </Field>
            {choice === "referred" ? (
                <>
                    <Field
                        label="Find referring dealer"
                        hint="Search by unique referral code or dealer name."
                        error={errors.referrer_code}
                    >
                        <input
                            value={search}
                            maxLength={80}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setSelected(null);
                                onCode("");
                                onVerified(false);
                            }}
                            placeholder="e.g. NX-DH-001 or Farhan"
                        />
                    </Field>
                    {loading ? (
                        <p role="status">Finding dealers…</p>
                    ) : failed ? (
                        <Notice tone="warning">
                            Could not load referral codes. Change the search to
                            try again.
                        </Notice>
                    ) : (
                        <div>
                            <Field label="Choose referring dealer">
                                <select
                                    value={selectedOption ? code : ""}
                                    required
                                    onChange={(event) => {
                                        const option = options.find(
                                            (option) =>
                                                option.code ===
                                                event.target.value,
                                        );
                                        if (option) choose(option);
                                    }}
                                >
                                    <option value="" disabled>
                                        Choose a referral code
                                    </option>
                                    {options.map((option) => (
                                        <option
                                            key={option.code}
                                            value={option.code}
                                        >
                                            {option.code} · {option.name} ·{" "}
                                            {option.status}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {!options.length && (
                                <p className="field-hint">
                                    No matching dealer. Enter another code or
                                    name.
                                </p>
                            )}
                        </div>
                    )}
                    <p className="field-hint">
                        Selected referral code:{" "}
                        <strong>{code || "Choose a dealer above"}</strong>
                        {selectedOption && ` · ${selectedOption.name}`}
                    </p>
                    <label className="mt-3 flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={verified}
                            disabled={!code || !selectedOption}
                            required
                            onChange={(event) =>
                                onVerified(event.target.checked)
                            }
                        />
                        I have manually verified that this dealer referred the
                        account holder.
                    </label>
                    {errors.referral_verified && (
                        <p className="field-error">
                            {errors.referral_verified}
                        </p>
                    )}
                </>
            ) : (
                <p className="field-hint">
                    This account will have no direct referrer. The company can
                    add one later.
                </p>
            )}
        </div>
    );
}
