# Nexora — Business Payment System Management

A private company and dealer workspace built from the supplied master prompt. **Nexora is a placeholder brand**, editable in company settings. This is a working application foundation with persistent profiles and administration; financial posting and live payouts are deliberately inactive until the company supplies the missing business rules and provider integration.

## Stack

Laravel 13 / PHP 8.4, React 19 / TypeScript, Inertia 2, Tailwind CSS 4, Vite 7, PostgreSQL 17. Radix provides accessible dialogs; the button component follows shadcn/ui conventions. Laravel serves the frontend and owns authentication, authorization, validation, calculations, and database transactions.

## What works

- Responsive public website, private admin workspace, and scoped dealer portal. No public registration.
- Admin creation of pending dealers, territory and level validation, case-insensitive search, profile updates, audited activation/suspension, and session invalidation.
- Divisional, District, Upazila / Thana, and Union dealer levels; territory overview.
- Integer-poisha commission previews, immutable rule drafts, explicit unallocated reward balance, and no automatic earnings from previews.
- One central operating mode, revision checks and row locks, audited transitions, and blocking for pending payouts/reserved funds. Approved ranks are **status-only configuration**; eligibility evaluation, dealer rank assignment, and financial rank rewards are not implemented.
- Immutable ledger foundation, tested reversal service, balances derived from entries, and a provider interface that rejects payouts while unconfigured.
- Role and ownership policies, CSRF protection, password reset flow, TOTP enrollment and replay protection, one-use recovery codes, login throttling, production admin MFA enrollment, security headers, audit trail, minimal CSV exports, and persisted company settings.

The local demo contains six synthetic dealer profiles and **zero financial transactions**. Demo access requires both `APP_ENV=local` and `DEMO_ENABLED=true`. Default seeding creates only the confirmed reference rule; it does not create a public or production admin account.

## Confirmed calculation

For MRP ৳200.00: company ৳60.00 (30%), dealer bucket ৳100.00 (50%), reward pool ৳40.00 (20%). The nine supplied reward percentages total **40% of the pool**, or ৳16.00. The remaining **60% of the pool**, ৳24.00, stays unallocated. These percentages are not applied to MRP. Rounding preserves every poisha.

Do not enable financial posting before reviewing [the missing business decisions](docs/operations.md#business-decisions-required-for-financial-operation).

## Get a live preview with GitHub Codespaces

[Create a Codespace for this repository](https://codespaces.new/techtemlead-lab/Business-Payment-System-Management?ref=main).

1. Create a Codespace on `main` and wait for the container setup to finish. The included configuration installs PHP/Node dependencies, starts a separate PostgreSQL preview database, seeds synthetic profiles, and builds the frontend.
2. Open the **Ports** tab, find **8000 / Nexora website**, and click **Open in Browser**. This opens your live website through GitHub's forwarded URL.
3. To share it with someone else, right-click port 8000, choose **Port Visibility → Public**, and copy its **Forwarded Address**. Keep it private if you only need to preview it yourself.

The generated URL works while the Codespace is running. This is a development demo with sample accounts and inactive financial posting. Use synthetic data only, especially when making the port public. Codespaces usage is subject to your GitHub plan and included allowance; stop the Codespace when finished.

The app starts automatically after creation/restart. If needed, run `bash scripts/codespaces-start.sh` in its terminal. Setup preserves an existing `.env` and application key and requires the isolated `nexora_preview` database with `APP_ENV=local` before applying migrations. A forwarded HTTPS URL enables `APP_FORCE_HTTPS=true`, so generated asset and redirect URLs use HTTPS even though the internal PHP connection uses HTTP. Keep the forwarded port's backend protocol set to HTTP.

## Start in this cloud workspace

```bash
cd /workspace/Business-Payment-System-Management
bash scripts/cloud-bootstrap.sh
bash scripts/cloud-start.sh
```

Bootstrap installs PHP, Composer, and PostgreSQL without root into `/workspace/.runtime`, installs locked dependencies, prepares the local database, and builds the frontend. It preserves an existing `.env` and application key. On first setup it explicitly enables the isolated development demo. The start script serves the built application and checks its public response. Use the cloud environment's forwarded preview for access; startup is not a production deployment.

For hot reload, add `/workspace/.runtime/bin` to `PATH`, then run `composer run dev`. Set `npm_config_cache=/workspace/.runtime/npm` in this workspace if npm needs a writable cache. The development command starts Laravel, a database queue worker, and Vite; Ctrl+C stops its child processes. For a forwarded Vite endpoint, set `NEXORA_VITE_ORIGIN` to its full HTTPS origin and set `APP_URL` to the forwarded Laravel origin for CORS. Use the built-asset start script when only one preview port is forwarded.

## Install on a regular development machine

Requirements: PHP 8.4+ with ctype, curl, DOM/XML, fileinfo, mbstring, PDO PostgreSQL, and tokenizer; Composer 2; Node 22.12+; PostgreSQL 17; OpenSSL and PostgreSQL client tools for backups.

```bash
composer install
npm ci --ignore-scripts
cp .env.example .env
# Configure PostgreSQL connection and create dealer_management before continuing.
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan dealer:admin admin@your-company.example --name="Company Administrator"
composer run dev
```

The admin command prompts for the password without echoing it. Configure a real SMTP transport to deliver reset emails; `MAIL_MAILER=array` safely suppresses delivery in the local demo. See [operations](docs/operations.md) for HTTPS, backup/restore, workers, and deployment requirements.

## Verification

```bash
php vendor/bin/phpunit --exclude-group pgsql
npm test
npm run build
```

PostgreSQL concurrency tests need an **empty, dedicated test database** and Linux `pcntl`. They migrate and roll back that database:

```bash
DB_CONNECTION=pgsql DB_DATABASE=dealer_tests php vendor/bin/phpunit --group pgsql
```

Browser tests need a **separate `dealer_browser` database**, PostgreSQL connection values, and Chromium. They create and activate synthetic accounts there. Set `CHROMIUM_PATH` when Chromium is outside `/usr/bin/chromium`.

```bash
npm run test:e2e
```

Never point either test suite at production. The default unit/feature suite uses in-memory SQLite; PostgreSQL separately verifies locking and database-level history protection. See [category research](docs/category-research.md) and [third-party notices](docs/third-party-notices.md).
