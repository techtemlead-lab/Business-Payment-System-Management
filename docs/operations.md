# Operation and remaining business configuration

## Business decisions required for financial operation

The supplied prompt defines the top-level 30/50/20 split but leaves executable payment rules incomplete. This application preserves those gaps visibly.

| Decision | Current behavior | Company input required |
| --- | --- | --- |
| Sales/source events | Company records dealer purchases with unique references and dates; commission mode posts linked earnings once. Cancellation reverses earnings only when funds are available. | Company verifies its invoice/receipt records; external order/import integrations are not configured. Partial refunds require an additional approved workflow. |
| Dealer distribution | Owner confirmed 50% of unit MRP × quantity goes to the specific purchasing dealer; 30% company and 20% pool are preserved in the purchase snapshot. Actual paid amount is recorded separately. | Purchases require an active dealer; drafts do not change the confirmed purchase model. |
| Reward categories | Nine percentages are previewed; no recipients paid | Each trigger, eligibility, beneficiary, referral and dealer-to-dealer rules, accumulation/expiry/cap rules |
| Remaining reward pool | 60% of pool stays unallocated | Explicit ownership and permitted use; no silent redistribution |
| Rank system | Approved definitions with explicit eligibility text; status-only mode | Evaluation criteria, assignment workflow, transition treatment, monetary effects, maintenance rules |
| Live payouts | Withdrawal requests reserve earned funds and support company review. The unconfigured provider rejects actual payment execution. | Provider, account verification, authorization policy, fees/minimums, callback authentication, idempotency, reconciliation, timeout and refund behavior |
| Territory constraints | Required coverage fields and four levels validated | Approved geographical catalog, dealer exclusivity/overlap, transfer history, parent relationships |
| Legal identity and retention | No NID collection, synthetic demo records | Required fields, consent, access and retention policy, approved records and branding |

Adding a rule draft does not activate it. A mode change does not recalculate history or create rewards. The provider interface is a foundation, not a tested live payment integration. Do not substitute a fake successful provider to enable financial functions.

## Production deployment

Use a private PostgreSQL database and a managed PHP-FPM/web server deployment serving **only `public/`**. Laravel's development server and the rootless cloud PostgreSQL cluster are development tools. The local cluster uses trust authentication on its workspace Unix socket and must not be exposed publicly.

Configure `APP_ENV=production`, `APP_DEBUG=false`, `DEMO_ENABLED=false`, the real HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, database credentials, a real mail transport, and the company contact information. Protect `.env`, database credentials and the application key; never commit them. Keep the application key stable because TOTP secrets and sessions depend on it. Provision secrets through your deployment's secret store.

Install locked dependencies with `composer install --no-dev --optimize-autoloader` and `npm ci --ignore-scripts`; run `npm run build`, then reviewed migrations and `php artisan optimize`. Keep `storage/` and `bootstrap/cache/` writable by the application account and all other source paths read-only. Restart workers after releases with `php artisan queue:restart`. Bootstrap the administrator with the hidden-password CLI command and complete TOTP enrollment at first login.

The application applies production security headers and enforces admin MFA enrollment before workspace access. Validate the Content Security Policy against the actual production origin. Configure HTTPS and trusted proxy handling for your hosting topology; do not blindly trust arbitrary proxy headers. Prevent server/CDN logs and monitoring tools from recording password-reset tokens, authenticator secrets, credentials, or private request bodies. Session pages are not cacheable.

The database queue driver is configured. Supervise `php artisan queue:work --tries=1 --timeout=90` when queued work is introduced, monitor failed jobs, and run `php artisan schedule:run` from the host scheduler when scheduled application jobs are introduced. Purchase earnings are posted synchronously in a database transaction. No provider settlement or automatic reward-posting jobs are implemented.

Monitor readiness (`/up`), errors, failed authentication, and rejected transitions. Audit details intentionally omit financial account secrets and credentials. PostgreSQL triggers forbid updating or deleting historical ledger, rule, and audit rows; protect database administration separately because privileged administrators can alter triggers. An independent production security and business review remains necessary.

## Encrypted backup

`scripts/backup.sh` streams a custom-format PostgreSQL dump directly into authenticated AES-256-GCM CMS encryption. It creates owner-only files atomically and never writes a plaintext dump. Supply the recipient's **public certificate** in `BACKUP_CERT_PATH`; keep its private key offline and outside the application host. Supply PostgreSQL environment variables securely; the script does not source `.env`.

```bash
BACKUP_CERT_PATH=/secure/backup-recipient.crt \
DB_HOST=database.internal DB_DATABASE=dealer_management DB_USERNAME=backup_reader \
BACKUP_DIRECTORY=/secure/backups bash scripts/backup.sh
```

Inject `DB_PASSWORD` through the scheduler's secret mechanism rather than a command argument. Use a least-privilege backup account able to read all application tables and sequences. Schedule the script with a monitored cron/systemd or managed backup job; copy encrypted files to private off-site storage and apply the company's retention policy. Keep encrypted application-key escrow separate from the database backup. The repository does not invent a production recipient, storage destination, or schedule.

## Restore drill

Use an isolated restore machine and a new database. Access the offline private key only during an authorized restore; make any temporary plaintext dump owner-readable and remove it afterwards. Restore is destructive to the destination: never use the production database as a drill target.

```bash
umask 077
openssl cms -decrypt -binary -inform DER -in backup.dump.cms \
  -recip /secure/backup-recipient.crt -inkey /secure/offline-recipient.key \
  -out /secure/restore.dump
createdb -h database.internal -U restore_admin dealer_restore_drill
pg_restore -h database.internal -U restore_admin -d dealer_restore_drill \
  --no-owner --no-acl /secure/restore.dump
```

Check dealer, rule, ledger and audit counts; verify balances against ledger sums, preserved rules and history triggers; then exercise authenticated read access with the matching application key. Record recovery duration and integrity results. Review failures before changing backup retention. A development dump was encrypted, decrypted and restored into a separate PostgreSQL database: six synthetic dealer profiles, zero ledger entries, and one reference rule were preserved. This verifies the script format; it does not establish production recovery objectives or an off-site schedule.

## Dependency verification and cloud restoration

Both dependency lockfiles are included. `npm audit --omit=dev` returned no known vulnerabilities during implementation. After the network-access review, the live `composer audit --locked` also completed successfully with no advisories or abandoned packages. These results describe the advisory datasets at the time of checking; keep dependency checks current in development and deployment.

The cloud bootstrap was run successfully against the installed runtime, and startup was checked repeatedly. The initial non-root package installation was verified in this workspace. Restoration into a fresh cloud machine has not been independently verified. The original remote repository had no commits. The project now includes the application source, lockfiles, tests, and reusable setup scripts for source control. Runtime credentials, installed dependency caches, and databases are excluded. Preserve the environment snapshot for this cloud runtime; a fresh checkout still requires the documented setup.
