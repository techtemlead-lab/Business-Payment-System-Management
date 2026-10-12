import { useEffect, useState } from "react";
import { Field } from "./Common";

function basisPoints(text: string): number | null {
    const match = /^(\d{0,3})(?:\.(\d{0,2}))?$/.exec(text);
    if (!match) return null;
    return (
        Number(match[1] || 0) * 100 + Number((match[2] ?? "").padEnd(2, "0"))
    );
}
export function PercentageField({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: number;
    onChange: (bps: number) => void;
    error?: string;
}) {
    const [text, setText] = useState(String(value / 100));
    useEffect(() => {
        if (basisPoints(text) !== value) setText(String(value / 100));
    }, [value]);
    return (
        <Field label={label} error={error}>
            <input
                type="number"
                min="0"
                max="100"
                step="0.01"
                required
                value={text}
                onChange={(event) => {
                    const next = event.target.value;
                    setText(next);
                    const parsed = basisPoints(next);
                    if (parsed !== null) onChange(parsed);
                }}
            />
        </Field>
    );
}
