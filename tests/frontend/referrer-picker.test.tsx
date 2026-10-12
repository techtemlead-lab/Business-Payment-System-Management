import { useState } from "react";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ReferrerPicker } from "@/components/ReferrerPicker";

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
function Form() {
    const [choice, setChoice] = useState("none");
    const [code, setCode] = useState("");
    const [verified, setVerified] = useState(false);
    return (
        <ReferrerPicker
            choice={choice}
            code={code}
            verified={verified}
            onChoice={setChoice}
            onCode={setCode}
            onVerified={setVerified}
        />
    );
}
describe("Manual referral verification", () => {
    it("clears the selected code and confirmation when the referring dealer search changes", async () => {
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    dealers: [
                        {
                            code: "REF-001",
                            name: "First dealer",
                            status: "active",
                        },
                    ],
                }),
            }),
        );
        render(<Form />);
        fireEvent.change(screen.getByLabelText("Referral option"), {
            target: { value: "referred" },
        });
        await screen.findByRole("option", { name: /REF-001 · First dealer/ });
        fireEvent.change(screen.getByLabelText("Choose referring dealer"), {
            target: { value: "REF-001" },
        });
        const checkbox = screen.getByRole("checkbox") as HTMLInputElement;
        fireEvent.click(checkbox);
        expect(checkbox.checked).toBe(true);
        fireEvent.change(screen.getByLabelText(/Find referring dealer/), {
            target: { value: "Different dealer" },
        });
        expect(checkbox.checked).toBe(false);
        expect(checkbox.disabled).toBe(true);
        expect(screen.getByText("Choose a dealer above")).toBeTruthy();
    });
    it("offers an explicit no-referral path and removes selected referral data on switching back", async () => {
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    dealers: [
                        {
                            code: "REF-001",
                            name: "First dealer",
                            status: "active",
                        },
                    ],
                }),
            }),
        );
        render(<Form />);
        expect(screen.queryByRole("checkbox")).toBeNull();
        expect(
            (screen.getByLabelText("Referral option") as HTMLSelectElement)
                .value,
        ).toBe("none");
        fireEvent.change(screen.getByLabelText("Referral option"), {
            target: { value: "referred" },
        });
        await screen.findByRole("option", { name: /REF-001 · First dealer/ });
        fireEvent.change(screen.getByLabelText("Choose referring dealer"), {
            target: { value: "REF-001" },
        });
        fireEvent.click(screen.getByRole("checkbox"));
        fireEvent.change(screen.getByLabelText("Referral option"), {
            target: { value: "none" },
        });
        expect(screen.queryByRole("checkbox")).toBeNull();
        fireEvent.change(screen.getByLabelText("Referral option"), {
            target: { value: "referred" },
        });
        expect(
            (screen.getByRole("checkbox") as HTMLInputElement).disabled,
        ).toBe(true);
        expect(screen.getByText("Choose a dealer above")).toBeTruthy();
    });
});
