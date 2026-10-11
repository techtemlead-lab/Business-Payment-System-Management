import { describe, it, expect, vi } from "vitest";
import {
    render,
    screen,
    fireEvent,
    waitFor,
    cleanup,
} from "@testing-library/react";
import { Allocation } from "@/components/Allocation";
import type { Preview } from "@/lib/types";
const initial: Preview = {
    mrp_minor: 20000,
    company_bps: 3000,
    dealer_bps: 5000,
    pool_bps: 2000,
    company_minor: 6000,
    dealer_minor: 10000,
    pool_minor: 4000,
    allocated_minor: 1600,
    unallocated_minor: 2400,
    allocated_bps: 4000,
    preview_only: true,
    rewards: [
        {
            name: "Referral bonus",
            bps: 1000,
            amount_minor: 400,
            status: "pending_definition",
        },
    ],
};
describe("Allocation preview", () => {
    it("renders pool percentages separately from MRP percentages and keeps residual unallocated", () => {
        render(<Allocation preview={initial} detailed />);
        expect(screen.getByText("Unallocated balance")).toBeTruthy();
        expect(screen.getByText("60%")).toBeTruthy();
        expect(screen.getByText("10%")).toBeTruthy();
        expect(screen.getByText("Needs definition")).toBeTruthy();
        cleanup();
    });
    it("shows server validation errors without fabricating successful results", async () => {
        vi.stubGlobal(
            "fetch",
            vi
                .fn()
                .mockResolvedValue({
                    ok: false,
                    json: async () => ({
                        errors: { mrp: ["Enter a valid MRP amount."] },
                    }),
                }),
        );
        render(<Allocation preview={initial} detailed />);
        fireEvent.change(screen.getByLabelText("Product MRP (BDT)"), {
            target: { value: "-1" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Run preview" }));
        await waitFor(() =>
            expect(screen.getByText("Enter a valid MRP amount.")).toBeTruthy(),
        );
        expect(screen.getByText("৳200.00")).toBeTruthy();
        cleanup();
        vi.unstubAllGlobals();
    });
});
