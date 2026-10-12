import { Field } from "./Common";
import { DealerTypeField } from "./DealerTypeField";
import { activeQuestion, type SiteDocument } from "@/lib/website";
type Props = {
    document: SiteDocument;
    values?: Record<string, any>;
    errors?: Record<string, string>;
    onChange?: (key: string, value: any) => void;
    preview?: boolean;
};
export function ApplicationFields({
    document: d,
    values = {},
    errors = {},
    onChange = () => {},
    preview = false,
}: Props) {
    const app = d.application;
    const options: Record<string, string[]> = {
        level: ["Divisional", "District", "Upazila / Thana", "Union"],
        division: [
            "Dhaka",
            "Chattogram",
            "Rajshahi",
            "Khulna",
            "Barishal",
            "Sylhet",
            "Rangpur",
            "Mymensingh",
        ],
    };
    return (
        <>
            <div className="form-grid">
                <DealerTypeField
                    application
                    disabled={preview}
                    value={values.dealer_type ?? "dealer"}
                    error={errors.dealer_type}
                    onChange={(value) => onChange("dealer_type", value)}
                />
                {app.field_order.map((key) => {
                    if (key === "district" && values.level === "Divisional")
                        return null;
                    if (
                        key === "upazila" &&
                        !["Upazila / Thana", "Union"].includes(
                            values.level ?? "District",
                        )
                    )
                        return null;
                    if (key === "union_name" && values.level !== "Union")
                        return null;
                    const common = {
                        disabled: preview,
                        required: key !== "message",
                        value: values[key] ?? "",
                        onChange: (
                            event: React.ChangeEvent<
                                | HTMLInputElement
                                | HTMLTextAreaElement
                                | HTMLSelectElement
                            >,
                        ) => onChange(key, event.target.value),
                    };
                    return (
                        <Field
                            key={key}
                            label={
                                key === "level" &&
                                app.labels[key] === "Dealer level"
                                    ? "Territory level"
                                    : app.labels[key] || key
                            }
                            hint={
                                key === "level"
                                    ? [
                                          app.hints[key],
                                          "Geographical territory only; separate from Dealer or Area dealer account type.",
                                      ]
                                          .filter(Boolean)
                                          .join(" ")
                                    : app.hints[key] || undefined
                            }
                            error={errors[key]}
                        >
                            {options[key] ? (
                                <select {...common}>
                                    {options[key].map((option) => (
                                        <option key={option}>{option}</option>
                                    ))}
                                </select>
                            ) : ["message", "business_address"].includes(
                                  key,
                              ) ? (
                                <textarea
                                    {...common}
                                    maxLength={key === "message" ? 1500 : 500}
                                    placeholder={app.placeholders[key] || ""}
                                />
                            ) : (
                                <input
                                    {...common}
                                    type={
                                        key === "email"
                                            ? "email"
                                            : key === "mobile"
                                              ? "tel"
                                              : "text"
                                    }
                                    maxLength={
                                        key === "email" ||
                                        key === "business_name"
                                            ? 160
                                            : key === "name"
                                              ? 120
                                              : 100
                                    }
                                    placeholder={
                                        app.placeholders[key] ||
                                        (key === "mobile"
                                            ? "+8801XXXXXXXXX"
                                            : "")
                                    }
                                    autoComplete={
                                        key === "name"
                                            ? "name"
                                            : key === "email"
                                              ? "email"
                                              : key === "mobile"
                                                ? "tel"
                                                : "off"
                                    }
                                />
                            )}
                        </Field>
                    );
                })}
                {app.questions
                    .filter((question) => activeQuestion(question, values))
                    .map((question) => {
                        const common = {
                            disabled: preview,
                            required: question.required,
                            value:
                                values.additional_answers?.[question.id] ?? "",
                            onChange: (
                                event: React.ChangeEvent<
                                    | HTMLInputElement
                                    | HTMLTextAreaElement
                                    | HTMLSelectElement
                                >,
                            ) =>
                                onChange("additional_answers", {
                                    ...values.additional_answers,
                                    [question.id]: event.target.value,
                                }),
                        };
                        return (
                            <Field
                                key={question.id}
                                label={question.label}
                                error={
                                    errors[`additional_answers.${question.id}`]
                                }
                            >
                                {question.type === "select" ? (
                                    <select {...common}>
                                        <option value="">
                                            Choose an option
                                        </option>
                                        {question.options.map((option) => (
                                            <option key={option}>
                                                {option}
                                            </option>
                                        ))}
                                    </select>
                                ) : question.type === "textarea" ? (
                                    <textarea {...common} maxLength={1500} />
                                ) : (
                                    <input {...common} maxLength={1500} />
                                )}
                            </Field>
                        );
                    })}
            </div>
            <label className="request-consent">
                <input
                    type="checkbox"
                    disabled={preview}
                    required
                    checked={values.consent ?? false}
                    onChange={(event) =>
                        onChange("consent", event.target.checked)
                    }
                />
                {app.consent}
            </label>
            {errors.consent && <p className="field-error">{errors.consent}</p>}
        </>
    );
}
