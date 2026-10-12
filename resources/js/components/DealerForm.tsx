import { useForm } from "@inertiajs/react";
import { DealerTypeField } from "./DealerTypeField";
import { ReferrerPicker } from "./ReferrerPicker";
import { Modal, Field, Button, Notice } from "./Common";
export function DealerForm({
    open,
    onOpenChange,
    initial,
    applicationId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    applicationId?: number;
    initial?: Partial<
        Record<
            | "name"
            | "email"
            | "dealer_type"
            | "level"
            | "division"
            | "district"
            | "upazila"
            | "union_name"
            | "business_name"
            | "mobile"
            | "referrer_code",
            string
        >
    >;
}) {
    const form = useForm({
        name: "",
        name_bn: "",
        email: "",
        code: "",
        dealer_type: "dealer",
        level: "District",
        division: "Dhaka",
        district: "",
        upazila: "",
        union_name: "",
        business_name: "",
        mobile: "",
        password: "",
        referrer_code: "",
        referral_choice: initial?.referrer_code ? "referred" : "none",
        referral_verified: false,
        application_id: applicationId ?? null,
        ...initial,
    });
    const field = (
        key: Exclude<
            keyof typeof form.data,
            "application_id" | "referral_verified" | "referral_choice"
        >,
        label: string,
        type = "text",
        placeholder = "",
    ) => (
        <Field label={label} error={form.errors[key]}>
            <input
                type={type}
                value={form.data[key]}
                onChange={(e) =>
                    form.setData(
                        key,
                        key === "code"
                            ? e.target.value.toUpperCase()
                            : e.target.value,
                    )
                }
                placeholder={placeholder}
                autoComplete={key === "password" ? "new-password" : undefined}
            />
        </Field>
    );
    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title="Add a new dealer"
            description="Create a company-approved account. New dealers stay pending until you activate them."
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/dealers", {
                        onSuccess: () => {
                            form.reset();
                            onOpenChange(false);
                        },
                    });
                }}
            >
                <div className="form-grid">
                    <DealerTypeField
                        value={form.data.dealer_type}
                        error={form.errors.dealer_type}
                        onChange={(value) => form.setData("dealer_type", value)}
                    />
                    {field(
                        "name",
                        "Full name (English)",
                        "text",
                        "e.g. Farhan Ahmed",
                    )}
                    {field("name_bn", "Full name (Bangla)")}
                    {field(
                        "email",
                        "Email address",
                        "email",
                        "dealer@company.com",
                    )}
                    {field(
                        "code",
                        "Dealer code / unique referral code",
                        "text",
                        "NX-DH-007",
                    )}
                    <Field label="Territory level" error={form.errors.level}>
                        <select
                            value={form.data.level}
                            onChange={(e) =>
                                form.setData("level", e.target.value)
                            }
                        >
                            {[
                                "Divisional",
                                "District",
                                "Upazila / Thana",
                                "Union",
                            ].map((x) => (
                                <option key={x}>{x}</option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Division" error={form.errors.division}>
                        <select
                            value={form.data.division}
                            onChange={(e) =>
                                form.setData("division", e.target.value)
                            }
                        >
                            {[
                                "Dhaka",
                                "Chattogram",
                                "Rajshahi",
                                "Khulna",
                                "Barishal",
                                "Sylhet",
                                "Rangpur",
                                "Mymensingh",
                            ].map((x) => (
                                <option key={x}>{x}</option>
                            ))}
                        </select>
                    </Field>
                    {field("district", "District")}
                    {["Upazila / Thana", "Union"].includes(form.data.level) &&
                        field("upazila", "Upazila / Thana")}
                    {form.data.level === "Union" &&
                        field("union_name", "Union")}
                    {field("business_name", "Business name")}
                    {field("mobile", "Mobile number", "tel", "+8801XXXXXXXXX")}
                    {field("password", "Initial password", "password")}
                </div>
                {open && (
                    <ReferrerPicker
                        choice={form.data.referral_choice}
                        code={form.data.referrer_code}
                        verified={form.data.referral_verified}
                        onChoice={(value) =>
                            form.setData("referral_choice", value)
                        }
                        onCode={(value) => form.setData("referrer_code", value)}
                        onVerified={(value) =>
                            form.setData("referral_verified", value)
                        }
                        errors={form.errors}
                    />
                )}
                <p className="field-hint">
                    This dealer’s code becomes their unique referral code after
                    account creation.
                </p>
                <p className="field-hint">
                    Use 12+ characters with uppercase, lowercase, a number, and
                    a symbol. Share initial credentials through an approved
                    secure channel.
                </p>
                <div className="modal-actions">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={
                            form.processing ||
                            (form.data.referral_choice === "referred" &&
                                (!form.data.referrer_code ||
                                    !form.data.referral_verified))
                        }
                    >
                        {form.processing ? "Creating..." : "Create dealer"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
