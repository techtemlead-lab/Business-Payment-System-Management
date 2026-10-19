# Madina Natural Health Care

Laravel 13 / PHP 8.4, PostgreSQL 16, Inertia 3 and React 19. The application provides company administration, employee/SR operations and dealer/sub-dealer workspaces. Keep the current branch separate from main until the final review is accepted.

## Client preview

Business installations start with no demonstration records. Use a separate database if a synthetic preview is needed. `MADINA_PREVIEW_MODE=true` labels the preview and blocks financial posting, even if activation is accidentally enabled. `MADINA_FINANCIAL_ACTIVATION=false` remains the deployment default. The commission simulator does not post earnings.

Local setup uses the existing `compose.yaml`. Install the locked dependencies with `composer install` and `npm ci --ignore-scripts`, configure `.env`, run `php artisan migrate`, then `npm run build`. Run `php artisan madina:demo` only against a designated local preview database. Its access details stay in the ignored `.local/demo-access.json`; never commit or publish them. Do not rerun the demo command against a business dataset.

Start the web application with the existing development command. Run a queue worker separately:

```sh
php artisan queue:work --queue=imports,default --sleep=3 --tries=1 --timeout=1800
php artisan schedule:work
```

The worker is required for workbook imports. Operations are logged and failed jobs remain available through Laravel's failed-jobs commands. Production workers need PCNTL, a timeout of 1800 seconds and a database queue retry interval greater than that timeout (the production compose file uses 2100).

## Excel migration and starting from zero

Open **Company → Excel data import**. A new business can start with no import. This choice never erases existing records. A fresh installation starts empty; existing business data can be imported through the validated workflow.

For existing records, download the appropriate `.xlsx` template from the application. Each workbook has a Data worksheet and instructions. Templates cover partner accounts, products with opening stock, and opening financial balances. Import sponsors before their referrals, and partners before opening balances. Limits are 5,000 rows, 10 MB compressed and 50 MB expanded per workbook. Paste values only; formulas, macros and external links are rejected. Preserve phone numbers and identifiers as Excel Text values.

The upload produces a row-by-row validation preview without changing accounts, stock or balances. Any invalid row blocks the entire import. Duplicate codes, emails, phones, references and previously applied source files are rejected. Existing records are never overwritten. The original workbook is retained privately with a SHA256 digest. Apply revalidates against current records and commits atomically in a background job. Correct failed imports by uploading a new preview.

Imported partners have no shared/default password and remain hidden from the public directory. Send password setup invitations through Accounts. Products are unpublished drafts with audited opening stock movements; review them before publication.

Opening balances require another authorized Finance reviewer. Import records brought-forward available/held commissions against opening equity and can create a historical delivered-order balance with its collected amount and outstanding dues. These historical orders do not generate new commissions, rank volume or inventory movements. Later collections and reviewed credit notes reconcile with the opening receivable ledger. Opening held commissions have a separate audited release action under Commission holds.

Opening imports are locked after verified opening balances or financial activation. They cannot overwrite a partner with existing financial activity. After importing, use **Opening balance review** to reconcile the ledger and independently verify the approved starting statement and policy evidence. This also supports an evidenced zero opening on a new business database. Ordinary adjustments after go-live use the existing reviewed workflows.

## Production deployment

Use `Dockerfile.production` and `compose.production.yaml`; the development PHP server is not the production runtime. The production image includes PHP-FPM, GD, PostgreSQL backup tools, PCNTL and built frontend assets. Nginx exposes only the public directory. PostgreSQL and FPM have no host-facing ports. The web port binds to localhost for a trusted TLS reverse proxy.

1. Copy `.env.production.example` to the ignored `.env.production`. Supply a unique APP_KEY, database password, real HTTPS domain, SMTP settings and an independent backup password of at least 32 characters through the deployment secret store. Match TRUSTED_PROXIES to the actual proxy network. Never commit populated environment files.
2. Build the release with `docker compose --env-file .env.production -f compose.production.yaml build`. Before upgrading a populated installation, create and verify an encrypted backup.
3. Run migrations once with `docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force --no-interaction`. Do not use migrate:fresh, database resets or demo seeding against the business database.
4. Start with `docker compose --env-file .env.production -f compose.production.yaml up -d`. Configure the host's TLS proxy to forward to `127.0.0.1:8095` and forward the original scheme and client IP. Start the queue and scheduler services with the application.
5. Create the initial administrator with `php artisan madina:admin` using its documented command options. Run `php artisan app:readiness --json` and `php artisan app:readiness --strict --json` inside the app container. Strict readiness checks secure configuration, migrations, worker/scheduler heartbeats and a recently verified backup. Confirm actual SMTP delivery and password recovery on the deployment.
6. Keep preview mode enabled and financial posting disabled for client preview. Complete client acceptance testing for each role and all agreed workflows. Confirm the company's public content and contact/location details with the client.
7. After handover data, policy and opening verification are accepted, change the explicit deployment configuration to disable preview mode and enable financial posting, rebuild cached configuration by restarting the app/worker/scheduler containers, and rerun readiness. Time-based maturity release is separately opt-in through MADINA_AUTO_RELEASE_MATURED. It never releases inactive/area holds or bypasses manual approvals.

`/up` checks application boot and database connectivity without publishing private diagnostics. Application service checks also appear on the Opening balance review screen. Errors and queue/backup failures are logged at critical severity; route production logs to the hosting provider's alerting system and monitor `/up`. Review failed jobs before retrying them.

## Backups and recovery

`php artisan app:backup` creates an AES-256 encrypted archive containing a PostgreSQL custom dump, private evidence/import uploads and public uploaded files. A shared PostgreSQL lock coordinates HTTP mutations and financial/background imports while the snapshot is captured. The command verifies every archived file hash before recording a successful backup. Concurrent writes wait; reads remain available. Do not run schema changes, demo setup or unrelated direct database/file mutations during backup.

```sh
php artisan app:backup
php artisan app:backup-verify madina-TIMESTAMP-ID.zip
```

Daily backups run at 02:00 Asia/Dhaka when MADINA_BACKUPS_ENABLED is enabled. Backup storage is a private, separate mount. Mount or replicate encrypted archives to storage outside the application host and establish retention there; a volume on the same host alone does not protect against host loss. The backup password and APP_KEY must be kept in a separate secret store. No application secrets are included in the archive. Monitor backup age and failures.

Before go-live, perform a restore drill on a separate PostgreSQL database: decrypt a verified archive in a private temporary directory, restore database.dump with pg_restore, restore the private/public file trees to their configured storage roots, supply the original APP_KEY, then run migrations, financial reconciliation and readiness against that isolated restore. Never rehearse by overwriting the live database. Hash verification is an integrity check; the restore drill validates the hosting environment and recovery procedure.

## Verification

All database tests must use the isolated `madina_testing` instance. The base test case refuses a different database name. Never point tests at a shared development, preview or production database.

```sh
composer validate --strict
composer audit --locked
npm audit
node node_modules/typescript/bin/tsc --noEmit
node --test tests/Unit/business-date.test.mjs
npm run build
php artisan test --compact
php vendor/bin/pint --dirty --format agent
```

Backup integration tests need pg_dump and pg_restore on PATH. CI runs PHP 8.4, Node 24 and an isolated PostgreSQL 16 service. Composer/JS lockfiles are preserved. Tests, build checks, dependency audits and production-image build checks belong to the final review; successful code checks do not replace deployment-specific SMTP, TLS, recovery and client acceptance tests.
