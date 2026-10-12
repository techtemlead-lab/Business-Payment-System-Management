import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { PercentageField } from "@/components/PercentageField";
afterEach(cleanup);
describe("Percentage editing", () => {
    it("converts fractional percentages to exact integer basis points", () => {
        const change = vi.fn();
        render(
            <PercentageField
                label="Generation 1 (%)"
                value={0}
                onChange={change}
            />,
        );
        fireEvent.change(screen.getByLabelText("Generation 1 (%)"), {
            target: { value: "0.29" },
        });
        expect(change).toHaveBeenLastCalledWith(29);
        expect(
            (screen.getByLabelText("Generation 1 (%)") as HTMLInputElement)
                .value,
        ).toBe("0.29");
    });
    it("allows clearing the field without immediately replacing the input with zero", () => {
        render(
            <PercentageField
                label="Reward (%)"
                value={500}
                onChange={() => {}}
            />,
        );
        fireEvent.change(screen.getByLabelText("Reward (%)"), {
            target: { value: "" },
        });
        expect(
            (screen.getByLabelText("Reward (%)") as HTMLInputElement).value,
        ).toBe("");
    });
});
