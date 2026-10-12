import { Field } from "./Common";

export function DealerTypeField({
    value,
    onChange,
    error,
    application = false,
    disabled = false,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    application?: boolean;
    disabled?: boolean;
}) {
    return (
        <Field
            label={
                application
                    ? "Requested account type"
                    : "Company-approved account type"
            }
            error={error}
            hint={
                application
                    ? "Area dealer applications require company approval before area commission eligibility."
                    : "Only an area dealer qualifies for area dealer commission. Territory level does not grant this eligibility."
            }
        >
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                disabled={disabled}
                required
            >
                <option value="dealer">Dealer</option>
                <option value="area_dealer">Area dealer</option>
            </select>
        </Field>
    );
}
