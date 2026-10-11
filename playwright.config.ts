import { defineConfig, devices } from "@playwright/test";
export default defineConfig({
    testDir: "./tests/e2e",
    fullyParallel: false,
    workers: 1,
    timeout: 30000,
    reporter: "list",
    use: {
        baseURL: "http://127.0.0.1:8001",
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
        launchOptions: {
            executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
            args: ["--no-sandbox"],
        },
    },
    webServer: {
        command:
            "php artisan migrate --seed --force --no-interaction && php artisan db:seed --class=DemoSeeder --no-interaction && php artisan cache:clear --no-interaction && php artisan serve --host=127.0.0.1 --port=8001 --no-reload",
        url: "http://127.0.0.1:8001/up",
        reuseExistingServer: false,
        timeout: 60000,
        env: {
            APP_ENV: "local",
            APP_URL: "http://127.0.0.1:8001",
            DEMO_ENABLED: "true",
            DB_DATABASE: "dealer_browser",
            MAIL_MAILER: "array",
        },
    },
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
