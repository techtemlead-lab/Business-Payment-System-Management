import { useForm } from "@inertiajs/react";
import { Modal, Field, Button, Notice } from "./Common";
export function DealerForm({
    open,
    onOpenChange,
    initial,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initial?: Partial<
        Record<
            | "name"
            | "email"
            | "level"
            | "division"
            | "district"
            | "upazila"
            | "union_name"
            | "business_name"
            | "mobile",
            string
        >
    >;
}) {
    const form = useForm({
        name: "",
        name_bn: "",
        email: "",
        code: "",
        level: "District",
        division: "Dhaka",
        district: "",
        upazila: "",
        union_name: "",
        business_name: "",
        mobile: "",
        password: "",
        ...initial,
    });
    const field = (
        key: keyof typeof form.data,
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
                    {field("code", "Dealer code", "text", "NX-DH-007")}
                    <Field label="Dealer level" error={form.errors.level}>
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
                    <Button disabled={form.processing}>
                        {form.processing ? "Creating..." : "Create dealer"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
