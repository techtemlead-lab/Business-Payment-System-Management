# Madina operations runbook

How to deploy, watch and recover the live system. Use it together with `docs/GO_LIVE_CHECKLIST.md`, which covers first launch. All commands run inside the `app` container:

```sh
docker compose --env-file .env.production -f compose.production.yaml exec app php artisan <command>
```

## 1. Deploying a new version

1. **Read the release notes** (the merged pull requests). Note any migration or new setting.
2. **Take a backup first:** `php artisan app:backup`. It prints the file name and verifies it. Do not continue if it fails.
3. **Build:** `docker compose --env-file .env.production -f compose.production.yaml build`.
4. **Migrate:** `docker compose ... run --rm app php artisan migrate --force --no-interaction`.
5. **Restart:** `docker compose ... up -d`. The queue worker may take up to 31 minutes to stop if an import is running; the scheduler up to 20 minutes if a backup or settlement is running. Do not force-kill them.
6. **Check:** `php artisan app:readiness --json` must report no new warnings, and `/up` must answer 200 on the main domain.

### Migrations without downtime

The database stays online during migrations. Follow these rules when writing them:

- **New indexes:** build with `CREATE INDEX CONCURRENTLY` in a migration with `public $withinTransaction = false;`. Example: `2026_11_05_061344_add_hot_path_foreign_key_indexes.php`. If a concurrent build is interrupted it leaves an *invalid* index. Roll that one migration back and run it again.
- **New columns:** add them nullable, or with a constant default. Never add a `NOT NULL` column without a default to a big table.
- **Renaming or removing a column:** do it in two releases. First stop using it in the code, then remove it in the next release.
- **Data backfills:** do them in a queued job or command in batches of about 1,000 rows, not inside the migration.
- **Never** run `migrate:fresh`, `db:wipe` or demo seeders against the business database.

### Rolling back

- **Code only:** redeploy the previous image tag.
- **Code and migration:** take a backup, run `php artisan migrate:rollback --step=1`, then redeploy the previous image. Ledger and audit tables are append-only, so a rollback never deletes their rows.

## 2. Daily and weekly checks

| When | What | Healthy |
|---|---|---|
| Daily | Alert channel / `storage/logs` for `CRITICAL` lines | None, or each one explained |
| Daily | `php artisan app:readiness --json` | No warnings |
| Daily | Backup volume has a backup from last night, and the off-site copy exists | Yes |
| Weekly | `php artisan queue:failed` | Empty, or each failure understood |
| Weekly | Free disk space on the database and backup volumes | Over 30% free |
| Monthly | Restore drill (section 4) | Restored copy reconciles |

## 3. Alerts: what they mean and what to do

All alerts are `CRITICAL` log lines. To receive them in Slack, add `,slack` to `LOG_STACK` and set `LOG_SLACK_WEBHOOK_URL`.

| Alert text | Meaning | What to do |
|---|---|---|
| `Scheduled application backup failed` | Last night's backup did not finish or did not verify. | Run `php artisan app:backup` by hand and read the error. Common causes: disk full, wrong `MADINA_BACKUP_PASSWORD`, database unreachable. |
| `Scheduled backup clean-up failed` | Old backups were not deleted. | Check free space on the backup volume. Run `php artisan app:backup-prune --dry-run` to see what it would delete. |
| `Automatic payout approval failed for some payouts` | A salary, royalty or rank reward payout threw an error. It retries every hour. | The log line lists which month or partner failed. Settle it by hand on the Settlements page if it keeps failing, and keep the error for the developers. |
| `commission.maturity_release_skipped` | One partner's held commission does not match their wallet, so their commission was not released. Everyone else's was. | Finance checks that partner's wallet against the ledger (`php artisan finance:readiness`). Do not edit the database by hand; report it. |
| `Daily money reconciliation failed` | Journals, wallets or receipts no longer add up. | Freeze money movement (section 6) and contact the developers with the `finance:readiness` output. |
| `Daily audit-trail integrity check failed` | Audit records may have been changed. | Treat as a security incident (section 5). |
| `Queue backlog exceeded its threshold` | Over 1,000 jobs are waiting. The worker is stopped or stuck. | `docker compose ... ps queue`, then `logs queue`. Restart the queue service. |
| `Background job failed` | An import or notification job failed. | `php artisan queue:failed`. Retry with `queue:retry <id>` only after fixing the cause. |
| `Scheduled commission maturity release failed` / `area-quota hold release failed` | The 15-minute release crashed. | Run the command by hand (`finance:release-matured`) and read the error. |

Also watch for:

- `payment.gateway_lookup_failed` (warning): bKash/Nagad/Rocket could not be reached. Finance can still check the payment by hand from the screenshot.
- `sms.gateway_failed` (warning): an SMS provider failed. If every login code fails, check the SMS balance on Service integrations.

Logs never contain API keys, full phone numbers or login codes. Each request has an `X-Correlation-ID`. Search the logs for it to follow one request, including the background jobs it started.

## 4. Backups and restore

- **Automatic:** every night at 02:00 (Dhaka). Writes pause for a few seconds while the database is dumped. Encrypted with `MADINA_BACKUP_PASSWORD` (AES-256), then checked with `app:backup-verify`, which includes a `pg_restore --list` check.
- **Off-site:** set `MADINA_BACKUP_OFFSITE_DISK=backups-offsite` and mount a volume from another machine at `MADINA_BACKUP_OFFSITE_PATH`. Every verified backup is copied there. **Without this, losing the server loses the backups too.** Set retention on the off-site side as well (for example, keep 30 daily and 12 monthly).
- **Retention on the server:** `app:backup-prune` runs daily at 04:00. It keeps `MADINA_BACKUP_RETENTION_DAYS` (default 14), never fewer than `MADINA_BACKUP_KEEP_MINIMUM` (default 7), and never deletes the last verified backup.
- **Keep safe, separately from the server:** `APP_KEY`, `MADINA_BACKUP_PASSWORD` and the database password. A backup cannot be read without the password, and encrypted fields (NID, payout account numbers) cannot be read without `APP_KEY`.

### Restore drill (monthly, never on the live database)

1. Copy a recent backup to a separate machine or container with PostgreSQL 16.
2. Unzip it with the backup password (7-Zip or `unzip` with AES support).
3. Create an empty database and run `pg_restore --no-owner --dbname=<scratch db> database.dump`.
4. Copy `private/` into `storage/app/private` and `public/` into `storage/app/public` of a scratch install.
5. Start that install with the **same `APP_KEY`**, run `php artisan migrate --force`, `php artisan finance:readiness` and `php artisan app:readiness`.
6. Log in as an owner and open a few orders, a payment screenshot and a partner's wallet. Write down the date and result.

### Real restore after losing the server

Follow the drill above on the new server, using the newest off-site backup, then point DNS at the new server. Everything after that backup's time must be re-entered from paper or bank records. Tell finance which date the restore went back to.

## 5. Security incidents

- **A staff account may be misused:** suspend it on Accounts (this signs it out everywhere). Then read the audit log for that person. Account edits now record old and new phone, email, sponsor and status, and money actions record amounts.
- **A partner says their phone or password was changed without them:** the old number receives an SMS when the phone changes. Check `account.updated` in the audit log for who did it, restore the right phone, and reset the password.
- **A secret leaked** (API key, SMTP password): change it at the provider, then in Service integrations (Super Admin only). For `APP_KEY`, contact the developers first: changing it without re-encrypting makes encrypted data unreadable.
- **Suspected database tampering:** run `php artisan audit:verify-integrity`. The ledger and audit tables reject edits and deletes at database level.

## 6. Emergency freeze of money movement

See "Emergency freeze" in `docs/GO_LIVE_CHECKLIST.md`. In short: set `MADINA_FINANCIAL_ACTIVATION=false`, restart app/queue/scheduler, and confirm with `php artisan app:readiness`. Orders and payment claims can still be recorded; nothing is posted or paid out until it is switched back on.

## 7. Settings that matter in production

| Setting | Production value | Why |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Debug pages leak data. |
| `APP_DOMAIN` | real domain | Also needed by the compose health check. Replace `example.com` in `infra/nginx.production.conf`. |
| `TRUSTED_PROXIES` | the proxy's network, **never `*`** | `*` lets anyone fake their IP address and get around login limits and the admin IP allow-list. |
| `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` | `true` / `true` | Cookies only over HTTPS. |
| `LOG_LEVEL` | `warning` | Fewer, more useful lines. |
| `LOG_STDERR_FORMATTER` | `Monolog\Formatter\JsonFormatter` | Searchable logs. |
| `MADINA_BACKUPS_ENABLED` | `true` | Nightly backups. |
| `MADINA_BACKUP_OFFSITE_DISK` | `backups-offsite` | Survive server loss. |
| `MADINA_PREVIEW_MODE` | `false` | |
| `MADINA_FINANCIAL_ACTIVATION` | `true` only after the go-live checklist | |
