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
        page.getByRole("heading", { name: /Good business/ }),
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
        "/dealership-requests",
        "/purchases",
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
    await page.goto("/ledger");
    const existingEntries = await page.locator("tbody tr").count();
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
    await expect(page.locator("tbody tr")).toHaveCount(existingEntries);
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

test("public dealership enquiry reaches the company and never opens a dealer account", async ({
    page,
}) => {
    const suffix = Date.now().toString();
    await page.goto("/request-dealership");
    await page.getByLabel("Full name", { exact: true }).fill("Enquiry Partner");
    await page
        .getByLabel("Email address")
        .fill(`enquiry-${suffix}@example.test`);
    await page.getByLabel("Mobile number").fill("+8801712345678");
    await page.getByLabel("Business name").fill(`Enquiry Trading ${suffix}`);
    await page.getByLabel("District", { exact: true }).fill("Dhaka");
    await page.getByLabel("Business address").fill("12 Test Road, Dhaka");
    await page.getByRole("checkbox").check();
    await page.getByRole("button", { name: "Send dealership request" }).click();
    await expect(page.getByRole("status")).toContainText(
        "request has reached the company",
    );
    await admin(page);
    await expect(page.locator(".request-inbox")).toContainText(
        `Enquiry Trading ${suffix}`,
    );
    await page.goto("/dealership-requests");
    await page
        .getByRole("textbox", { name: "Search requests" })
        .fill(`Enquiry Trading ${suffix}`);
    await page.getByRole("button", { name: "Search", exact: true }).click();
    await page.getByRole("button", { name: "View request" }).click();
    await expect(page.getByRole("dialog")).toContainText(
        `enquiry-${suffix}@example.test`,
    );
    await page
        .getByLabel("Review note")
        .fill("Contact information reviewed by company");
    await page.getByRole("button", { name: "Save review" }).click();
    await expect(page.getByRole("status")).toContainText(
        "Request review saved",
    );
    await page.goto(`/dealers?q=Enquiry%20Trading%20${suffix}`);
    await expect(page.locator("tbody tr")).toHaveCount(0);
});

test("company purchase credits dealer earnings and withdrawal review preserves funds", async ({
    page,
    browser,
}) => {
    await admin(page);
    await page.goto("/purchases");
    await page.getByRole("button", { name: "Record purchase" }).click();
    const dialog = page.getByRole("dialog");
    const reference = `E2E-INV-${Date.now()}`;
    await dialog.getByLabel("Dealer code").fill("NX-DH-001");
    await dialog.getByLabel("Invoice / receipt reference").fill(reference);
    await dialog.getByLabel("Product name").fill("Browser purchase product");
    await dialog.getByLabel("Quantity").fill("4");
    await dialog.getByLabel("Unit MRP (BDT)").fill("375.00");
    await dialog.getByLabel("Total amount actually paid (BDT)").fill("1200.00");
    await dialog
        .getByLabel("Purchase date")
        .fill(
            new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Dhaka" }).format(
                new Date(),
            ),
        );
    await dialog.getByRole("button", { name: "Save purchase" }).click();
    await expect(page.getByRole("status")).toContainText("Purchase recorded");
    const row = page.locator("tbody tr").filter({ hasText: reference });
    await row.getByRole("button", { name: "View breakdown" }).click();
    await expect(page.locator(".purchase-breakdown")).toContainText("750.00");
    await expect(page.locator(".purchase-breakdown")).toContainText("450.00");
    await expect(page.locator(".purchase-breakdown")).toContainText("300.00");
    await page.keyboard.press("Escape");
    const dealerContext = await browser.newContext();
    const dealer = await dealerContext.newPage();
    await dealer.goto("http://127.0.0.1:8001/");
    await dealer.getByRole("button", { name: "Explore dealer portal" }).click();
    await expect(dealer).toHaveURL(/\/dashboard$/);
    await dealer.goto("http://127.0.0.1:8001/purchases");
    await expect(dealer.locator("tbody")).toContainText(reference);
    await expect(
        dealer.getByRole("button", { name: "Record purchase" }),
    ).toHaveCount(0);
    await dealer.goto("http://127.0.0.1:8001/payouts");
    await dealer
        .getByRole("button", { name: "Request withdrawal", exact: true })
        .click();
    await dealer
        .getByRole("dialog")
        .getByLabel("Withdrawal amount (BDT)")
        .fill("200.00");
    await dealer
        .getByRole("button", { name: "Send withdrawal request" })
        .click();
    await expect(dealer.getByRole("status")).toContainText(
        "Withdrawal request sent",
    );
    await page.goto("/payouts");
    await page.getByRole("button", { name: "View request" }).first().click();
    await page
        .getByLabel("Review note")
        .fill("Company reviewed the withdrawal");
    await page.getByRole("button", { name: "Save withdrawal review" }).click();
    await expect(page.getByRole("status")).toContainText("Withdrawal approved");
    await page.getByRole("button", { name: "View request" }).first().click();
    await expect(
        page.getByRole("button", { name: "Release payment", exact: true }),
    ).toBeDisabled();
    await page.getByLabel("Review decision").selectOption("declined");
    await page
        .getByLabel("Review note")
        .fill("Company postpones payment until provider selection");
    await page.getByRole("button", { name: "Save withdrawal review" }).click();
    await expect(page.getByRole("status")).toContainText(
        "Reserved funds returned",
    );
    await page.goto("/purchases");
    await page
        .locator("tbody tr")
        .filter({ hasText: reference })
        .getByRole("button", { name: "View breakdown" })
        .click();
    await page
        .getByLabel("Cancellation reason")
        .fill("End-to-end test correction");
    await page
        .getByRole("button", { name: "Cancel purchase & reverse earning" })
        .click();
    await expect(page.getByRole("status")).toContainText("Purchase cancelled");
    await dealerContext.close();
});

test("interactive network and mobile navigation respect reduced motion", async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/");
    await page.getByRole("button", { name: "Website menu" }).click();
    await expect(
        page.getByRole("navigation", { name: "Mobile website navigation" }),
    ).toBeVisible();
    await page
        .getByRole("navigation", { name: "Mobile website navigation" })
        .getByRole("link", { name: /Request dealership/ })
        .focus();
    await page.keyboard.press("Escape");
    await expect(
        page.getByRole("button", { name: "Website menu" }),
    ).toBeFocused();
    const level = page.locator(".scene-select button").filter({ hasText: "District" });
    await level.click();
    await expect(level).toHaveAttribute("aria-pressed", "true");
    await expect(page.locator(".network-scene")).toHaveAttribute(
        "data-running",
        "false",
    );
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
});
