import { useState } from "react";
import { useForm } from "@inertiajs/react";
import { ReferrerPicker } from "./ReferrerPicker";
import { Button, Modal, Field } from "./Common";
import { date } from "@/lib/types";

export type ReferralDealer = {
    id: number;
    name: string;
    code: string;
    referrer_code?: string | null;
    referrer_name?: string | null;
    referral_verified_at?: string | null;
    referral_verified_by_name?: string | null;
};
export function ManageReferral({ dealer }: { dealer: ReferralDealer }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        referral_choice: dealer.referrer_code ? "referred" : "none",
        referrer_code: dealer.referrer_code ?? "",
        referral_verified: false,
        reason: "",
        password: "",
    });
    return (
        <>
            <Button
                size="sm"
                type="button"
                variant="outline"
                onClick={() => {
                    form.setData({
                        referral_choice: dealer.referrer_code
                            ? "referred"
                            : "none",
                        referrer_code: dealer.referrer_code ?? "",
                        referral_verified: false,
                        reason: "",
                        password: "",
                    });
                    form.clearErrors();
                    setOpen(true);
                }}
            >
                Manage referral
            </Button>
            <Modal
                open={open}
                onOpenChange={setOpen}
                title={`Referral · ${dealer.name}`}
                description="Add, verify, change, or remove the direct referrer. Past purchase referral snapshots remain unchanged."
            >
                <p>
                    <strong>Unique referral code:</strong> {dealer.code}
                </p>
                <p>
                    <strong>Current referrer:</strong>{" "}
                    {dealer.referrer_code
                        ? `${dealer.referrer_code}${dealer.referrer_name ? ` · ${dealer.referrer_name}` : ""}`
                        : "No referrer"}
                </p>
                {dealer.referrer_code && (
                    <p className="field-hint">
                        {dealer.referral_verified_at
                            ? `Verified ${date(dealer.referral_verified_at)}${dealer.referral_verified_by_name ? ` by ${dealer.referral_verified_by_name}` : ""}`
                            : "Existing connection · manual verification pending"}
                    </p>
                )}
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(`/dealers/${dealer.id}/referrer`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    {open && (
                        <ReferrerPicker
                            exclude={dealer.id}
                            choice={form.data.referral_choice}
                            code={form.data.referrer_code}
                            verified={form.data.referral_verified}
                            onChoice={(value) =>
                                form.setData("referral_choice", value)
                            }
                            onCode={(value) =>
                                form.setData("referrer_code", value)
                            }
                            onVerified={(value) =>
                                form.setData("referral_verified", value)
                            }
                            errors={form.errors}
                        />
                    )}
                    {dealer.referrer_code &&
                        form.data.referral_choice !== "none" && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    form.setData("referral_choice", "none");
                                    form.setData("referrer_code", "");
                                    form.setData("referral_verified", false);
                                }}
                            >
                                Remove referral connection
                            </Button>
                        )}
                    <Field
                        label="Reason for referral change"
                        error={form.errors.reason}
                    >
                        <textarea
                            required
                            minLength={5}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData("reason", event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Current password"
                        error={form.errors.password}
                        hint="Required outside the local demo."
                    >
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData("password", event.target.value)
                            }
                        />
                    </Field>
                    <div className="modal-actions">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
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
                            {form.data.referral_choice === "none"
                                ? dealer.referrer_code
                                    ? "Confirm removal"
                                    : "Save without referral"
                                : "Save verified referral"}
                        </Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
