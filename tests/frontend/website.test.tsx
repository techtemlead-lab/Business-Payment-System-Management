import { describe, expect, it } from "vitest";
import {
    remapTranslations,
    localized,
    contrastRatio,
    activeQuestion,
    type SiteDocument,
} from "@/lib/website";
describe("Website customization", () => {
    it("moves translations with reordered sections and preserves unrelated content", () => {
        expect(
            remapTranslations(
                {
                    "sections.0.title": "First",
                    "sections.1.title": "Second",
                    "hero.title": "Hero",
                },
                "sections",
                (index) => (index === 0 ? 1 : index === 1 ? 0 : index),
            ),
        ).toEqual({
            "sections.1.title": "First",
            "sections.0.title": "Second",
            "hero.title": "Hero",
        });
    });
    it("removes only translations belonging to deleted content and shifts remaining indices", () => {
        expect(
            remapTranslations(
                {
                    "sections.0.title": "First",
                    "sections.1.items.0.body": "Deleted",
                    "sections.2.title": "Third",
                    "footer.text": "Footer",
                },
                "sections",
                (index) => (index === 1 ? null : index > 1 ? index - 1 : index),
            ),
        ).toEqual({
            "sections.0.title": "First",
            "sections.1.title": "Third",
            "footer.text": "Footer",
        });
    });
    it("uses Bengali translations without mutating published English content", () => {
        const document = {
            hero: { title: "Welcome", body: "English fallback" },
            translations: { "hero.title": "স্বাগতম", "hero.body": "" },
        } as unknown as SiteDocument;
        expect(localized(document, "bn").hero).toEqual({
            title: "স্বাগতম",
            body: "English fallback",
        });
        expect(document.hero.title).toBe("Welcome");
    });
    it("shows conditional questions only for the configured territory or level", () => {
        const question = {
            id: "q",
            label: "Experience",
            type: "text",
            required: true,
            options: [],
            when_field: "level",
            when_value: "District",
        };
        expect(activeQuestion(question, { level: "District" })).toBe(true);
        expect(activeQuestion(question, { level: "Union" })).toBe(false);
    });
    it("reports readable and unreadable color combinations", () => {
        expect(contrastRatio("#000000", "#ffffff")).toBe(21);
        expect(contrastRatio("#ffffff", "#ffffff")).toBe(1);
        expect(contrastRatio("#253d31", "#f7f6f0")).toBeGreaterThan(4.5);
    });
});
