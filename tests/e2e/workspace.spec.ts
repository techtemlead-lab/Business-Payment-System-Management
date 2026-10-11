import { test, expect, type Page } from "@playwright/test";
async function admin(page: Page) {
    await page.goto("/");
    await page.getByRole("button", { name: "Explore admin workspace" }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
test("public website is responsive and has no public registration", async ({
    page,
}) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto("/");
    await expect(
        page.getByRole("heading", { name: /A stronger network/ }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", { name: /register|apply now|sign up/i }),
    ).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(
        page.getByRole("link", { name: "Enter your workspace" }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});
test("admin pages render with server data and accessible mobile navigation", async ({
    page,
}) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await admin(page);
    await expect(
        page.getByRole("heading", { name: "Welcome back, Alex." }),
    ).toBeVisible();
    for (const path of [
        "/dealers",
        "/hierarchy",
        "/rules",
        "/operating-mode",
        "/ledger",
        "/payouts",
        "/reports",
        "/audit",
        "/settings",
        "/security",
    ]) {
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.locator("main h1")).toBeVisible();
    }
    await page.goto("/dashboard");
    await page.setViewportSize({ width: 390, height: 844 });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.getByRole("button", { name: "Open navigation" }).click();
    await expect(
        page.getByRole("dialog", { name: "Workspace navigation" }),
    ).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(
        page.getByRole("button", { name: "Open navigation" }),
    ).toBeFocused();
    await page.getByRole("button", { name: "Open navigation" }).click();
    await page
        .locator(".mobile-sidebar")
        .getByRole("link", { name: "Dealer management" })
        .click();
    await expect(page).toHaveURL(/\/dealers$/);
    expect(errors).toEqual([]);
});
test("admin creates a pending dealer, searches, and activates with audited confirmation", async ({
    page,
}) => {
    await admin(page);
    await page.getByRole("button", { name: "Create dealer" }).click();
    const dialog = page.getByRole("dialog");
    const suffix = Date.now().toString().slice(-8);
    const name = `Browser Partner ${suffix}`;
    await dialog.getByLabel("Full name (English)").fill(name);
    await dialog
        .getByLabel("Email address")
        .fill(`browser-${suffix}@example.test`);
    await dialog.getByLabel("Dealer code").fill(`NX-E2E-${suffix}`);
    await dialog.getByLabel("District", { exact: true }).fill("Dhaka");
    await dialog.getByLabel("Initial password").fill("Browser-Partner-2026!");
    await dialog
        .getByRole("button", { name: "Create dealer", exact: true })
        .click();
    await expect(page.getByRole("status")).toContainText("Dealer created");
    await page.getByRole("textbox", { name: "Search dealers" }).fill(name);
    await page.getByRole("button", { name: "Search", exact: true }).click();
    await expect(page.locator("tbody tr")).toHaveCount(1);
    await page.getByRole("link", { name: `View ${name}` }).click();
    await page.getByRole("button", { name: "Activate", exact: true }).click();
    await page
        .getByRole("dialog")
        .getByLabel("Reason")
        .fill("Company eligibility reviewed");
    await page.getByRole("button", { name: "Confirm change" }).click();
    await expect(page.getByRole("status")).toContainText(
        "Dealer status updated",
    );
    await expect(page.locator(".page-heading .badge")).toHaveText("active");
    await page.goto("/audit?q=dealer.status_changed");
    await expect(page.locator("tbody")).toContainText(
        "Company eligibility reviewed",
    );
});
test("allocation preview handles CSRF after Inertia login and preserves unallocated money", async ({
    page,
}) => {
    await admin(page);
    await page
        .getByRole("link", { name: "Commission & rewards", exact: true })
        .click();
    await page.getByLabel("Product MRP (BDT)").fill("400.00");
    const response = page.waitForResponse((r) =>
        r.url().endsWith("/rules/preview"),
    );
    await page.getByRole("button", { name: "Run preview" }).click();
    const result = await response;
    expect(result.status()).toBe(200);
    const data = await result.json();
    expect(data.company_minor).toBe(12000);
    expect(data.dealer_minor).toBe(20000);
    expect(data.pool_minor).toBe(8000);
    expect(data.unallocated_minor).toBe(4800);
    await expect(page.locator(".donut-label strong")).toContainText("400.00");
    await expect(page.locator(".total-row")).toContainText("48.00");
    await page.goto("/ledger");
    await expect(
        page.getByRole("heading", { name: "A clean starting point" }),
    ).toBeVisible();
});
test("invalid preview shows a useful error and incomplete rank mode stays inactive", async ({
    page,
}) => {
    await admin(page);
    await page.goto("/rules");
    await page.getByLabel("Product MRP (BDT)").fill("-100");
    await page.getByRole("button", { name: "Run preview" }).click();
    await expect(page.locator(".field-error")).toContainText(
        "non-negative BDT amount",
    );
    await page.goto("/operating-mode");
    await expect(
        page.getByRole("button", { name: "Configure ranks first" }),
    ).toBeDisabled();
    await expect(page.locator(".mode-selected")).toContainText(
        "Commission-based",
    );
});
test("dealer portal hides admin navigation and blocks other dealer profiles and reports", async ({
    page,
}) => {
    await page.goto("/");
    await page.getByRole("button", { name: "Explore dealer portal" }).click();
    await expect(
        page.getByRole("heading", { name: "Welcome back, Farhan." }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", { name: "Dealer management", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("link", { name: "View my profile" }).click();
    await expect(
        page.getByRole("heading", { name: "Farhan Ahmed" }),
    ).toBeVisible();
    const denied = await page.goto("/dealers/2");
    expect(denied?.status()).toBe(404);
    await expect(
        page.getByRole("heading", { name: "A little off the path." }),
    ).toBeVisible();
    const report = await page.goto("/reports");
    expect(report?.status()).toBe(403);
    await expect(
        page.getByRole("heading", { name: "This area is private." }),
    ).toBeVisible();
});
