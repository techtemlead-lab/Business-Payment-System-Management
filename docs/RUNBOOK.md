# Madina operations runbook

How to deploy, watch and recover the live system. Use it together with `docs/GO_LIVE_CHECKLIST.md`, which covers first launch. All commands run inside the `app` container:

```sh
docker compose --env-file .env.production -f compose.production.yaml exec app php artisan <command>
```

## 1. Deploying a new version

**Rule: only a commit whose whole test suite passed on `main` goes live.** Pull requests run only the related test files; the whole suite runs after each merge to `main`. Wait for that run to finish green.

From the operator's computer (Git Bash on Windows works), in an up-to-date checkout:

```sh
MADINA_DEPLOY_SSH=root@<server> MADINA_DEPLOY_KEY=~/.ssh/<deploy key> infra/deploy/deploy.sh <sha>
```

1. **Gate:** `infra/deploy/check-main-green.sh <sha>` runs first and refuses a commit that is not on `main` or whose whole-suite run is missing, still running or red. Emergency only: `--skip-gate "<reason>"`; the reason is written to the release log on the server.
2. The release (`git archive <sha>`) is copied to `/root/madina-predeploy/release-<sha>/` and its own `infra/deploy/server-release.sh` runs there in the background. The operator's window follows `deploy-<sha>.log` until it prints `DEPLOY DONE` or `DEPLOY FAILED`.
3. `server-release.sh` does: a `pg_dump` and a storage tar (`before-deploy-<sha>-<time>.dump`, `storage-<sha>-<time>.tar`) and `app:backup` → copies the release into the stack (keeping `.env*`, `acme/`, `storage/`) → builds `madina-application:<sha>` and `madina-web:<sha>` → maintenance mode → stops queue and scheduler (they finish their job first: up to 31 and 20 minutes) → migrates → starts everything on the new images → reloads nginx → leaves maintenance → checks `/up` → writes `DEPLOYED_COMMIT` and a line in `RELEASES` → keeps the images of the last 3 releases → prints `app:readiness`.
4. **If a step fails after maintenance mode started,** the script starts the previous images again and leaves maintenance mode before printing `DEPLOY FAILED`, so the site is not left down. Read the log above the failure line.
5. About 10 seconds of maintenance per deploy: deploy outside office hours.

Settings for the server scripts live in `/etc/madina-ops.env` (template: `infra/ops/madina-ops.env.example`). The server address and key are never stored in the repository.

The manual steps below are what the script does; use them only if the script cannot run.

1. Backup: `php artisan app:backup` (prints and verifies the file). Do not continue if it fails.
2. Build: `MADINA_IMAGE_TAG=<sha> docker compose --env-file .env.production -f compose.production.yaml build app web`.
3. `php artisan down`, `docker compose ... stop queue scheduler`, then migrate with the new image: `MADINA_IMAGE_TAG=<sha> docker compose ... run --rm --no-deps -T app php artisan migrate --force </dev/null`.
4. `MADINA_IMAGE_TAG=<sha> docker compose ... up -d --no-build`, `exec -T web nginx -s reload`, `exec -T app php artisan up`, then `docker tag madina-application:<sha> madina-application:release` (and the same for `madina-web`).
5. Every `docker compose exec -T` inside a piped script needs `</dev/null`, or it eats the rest of the script.

### Migrations without downtime

The database stays online during migrations. Follow these rules when writing them:

- **New indexes:** build with `CREATE INDEX CONCURRENTLY` in a migration with `public $withinTransaction = false;`. Example: `2026_11_05_061344_add_hot_path_foreign_key_indexes.php`. If a concurrent build is interrupted it leaves an *invalid* index. Roll that one migration back and run it again.
- **New columns:** add them nullable, or with a constant default. Never add a `NOT NULL` column without a default to a big table.
- **Renaming or removing a column:** do it in two releases. First stop using it in the code, then remove it in the next release.
- **Data backfills:** do them in a queued job or command in batches of about 1,000 rows, not inside the migration.
- **Never** run `migrate:fresh`, `db:wipe` or demo seeders against the business database.

### Rolling back

On the server (the end of every deploy log prints the exact command):

```sh
bash /root/madina-predeploy/release-<current sha>/infra/deploy/server-rollback.sh <previous sha>
```

- **Code only (usual):** switches app, queue, scheduler and web back to the kept images of `<previous sha>` in about a minute, without rebuilding. The images of the last 3 releases are kept (`docker image ls madina-application`); an older release needs a normal deploy of that commit. The script lists any migration the older code does not know. Additive migrations (new tables or columns, the rule in "Migrations without downtime") can stay.
- **Code and data (last resort, data entered since is lost):** `server-rollback.sh <previous sha> --restore-database /root/madina-predeploy/before-deploy-<sha>-<time>.dump`. It first dumps the current database (`before-rollback-*.dump`), asks you to type `RESTORE`, then replaces the database. Tell the owner which time the data went back to. Ledger and audit tables are append-only; never edit them by hand instead.
- Every deploy and rollback is a line in `/opt/madina-nhac/RELEASES` (time, commit, previous commit, any skipped gate).

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

All alerts are `CRITICAL` log lines. They reach a person once an alert path is set in `.env.production`:

- **Telegram (recommended):** create a bot with @BotFather, add it to the owner's or IT group, send one message in the group, read the group's chat id from `https://api.telegram.org/bot<token>/getUpdates`, then set `MADINA_ALERT_TELEGRAM_BOT_TOKEN` and `MADINA_ALERT_TELEGRAM_CHAT_ID`.
- **Email:** `MADINA_ALERT_EMAILS=owner@…,it@…` (needs a real mail provider, not `MAIL_MAILER=log`).
- Restart app, queue and scheduler (the config cache is rebuilt at start), then run `php artisan ops:alert-test`. The same problem is sent at most once an hour (`MADINA_ALERT_REPEAT_MINUTES`); phone numbers are masked.
- Slack still works: add `,slack` to `LOG_STACK` and set `LOG_SLACK_WEBHOOK_URL`.

**Outside checks (catch a dead server, which cannot alert by itself):**

- `ops:heartbeat` runs every 5 minutes. It checks the database, the queue worker, disk use (alert at `MADINA_DISK_ALERT_PERCENT`, default 85%) and that a backup was made last night, logs each problem as critical, and pings `MADINA_HEARTBEAT_URL` (a free 5-minute check at healthchecks.io; `/fail` is added on a problem). When pings stop, that service alerts by email, SMS or Telegram.
- An uptime monitor (UptimeRobot or Better Stack free plan) on `https://madinanhc.com/up` every 5 minutes.
- `infra/ops/host-backup.sh` and `infra/ops/restore-drill.sh` ping their own checks (`HOST_BACKUP_HEARTBEAT_URL`, `RESTORE_DRILL_HEARTBEAT_URL`).

Server health alerts:

| Alert text | Meaning | What to do |
|---|---|---|
| `The database does not answer.` | The app cannot reach PostgreSQL. | `docker compose ... ps db` and `logs db`; check disk space. |
| `The background job worker has not checked in for 5 minutes.` | The queue container stopped or hangs. | `docker compose ... ps queue`, `logs queue`; `up -d queue`. |
| `The server disk is almost full` | Uploads, logs, backups or Docker images filled the disk. | `infra/ops/prune-host.sh` (dry run), then `--apply`; see section 5a. |
| `No backup from the last night.` | The 02:00 backup did not run or failed. | Run `php artisan app:backup` and read the error. |
| `Backup restore drill failed.` | A backup did not come back cleanly. | Read `restore-drill-last.json` on the backup volume; run the drill by hand. |

Money and job alerts:

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
- **Second copy on the host:** `infra/ops/host-backup.sh` (root cron, 03:30) dumps the database straight from the `db` container, packs each upload volume, encrypts everything with its own passphrase file (`HOST_BACKUP_PASSPHRASE_FILE`, AES-256), opens it again to prove it, and keeps 14 days in `/var/backups/madina-host` (never fewer than 7). It does not depend on the app container working.
- **Off-site:** the same script copies its file **and** the app's nightly zips to `RCLONE_REMOTE` (any rclone remote: Backblaze B2, Cloudflare R2, Wasabi, S3, Google Drive, SFTP to another server). Daily copies are kept 35 days, a monthly copy (made on the 1st) 400 days. After a good copy it writes `offsite-copy-last.json` beside the app backups, which clears the `offsite_backup_not_configured` warning. Alternatively mount a volume from another machine at `MADINA_BACKUP_OFFSITE_PATH` and set `MADINA_BACKUP_OFFSITE_DISK=backups-offsite`. **Without either, losing the server loses the backups too.**
- **Retention on the server:** `app:backup-prune` runs daily at 04:00. It keeps `MADINA_BACKUP_RETENTION_DAYS` (default 14), never fewer than `MADINA_BACKUP_KEEP_MINIMUM` (default 7), and never deletes the last verified backup.
- **Keep safe, separately from the server (owner's password manager):** `APP_KEY`, `MADINA_BACKUP_PASSWORD`, the host backup passphrase, the rclone remote's access key and the database password. A backup cannot be read without its password, and encrypted fields (NID, payout account numbers) cannot be read without `APP_KEY`.

### Restore drill (monthly, never on the live database)

Two scripted drills; each writes `restore-drill-last.json` beside the app backups, and `app:readiness` warns `restore_drill_overdue` when none passed in 35 days:

- **App backup:** `php artisan app:backup-restore-drill [file] [--keep]` verifies the newest app backup, restores it into a scratch database on the same PostgreSQL (needs a database user allowed to create databases), checks migrations, missing tables and that the journal balances, prints key row counts next to live, and drops the scratch database. Set `MADINA_RESTORE_DRILL_MONTHLY=true` after a first manual pass to run it on the 2nd of every month at 05:00.
- **Host backup:** `infra/ops/restore-drill.sh [file]` decrypts a host backup, restores it into a throwaway `postgres:16` container with no network, runs the same checks and prints how long it took. Write that time down: it is the database part of the recovery time.

A full drill on a separate machine (once a quarter, and after big releases):

1. Copy a recent backup to a separate machine or container with PostgreSQL 16.
2. Unzip it with the backup password (7-Zip or `unzip` with AES support).
3. Create an empty database and run `pg_restore --no-owner --dbname=<scratch db> database.dump`.
4. Copy `private/` into `storage/app/private` and `public/` into `storage/app/public` of a scratch install.
5. Start that install with the **same `APP_KEY`**, run `php artisan migrate --force`, `php artisan finance:readiness` and `php artisan app:readiness`.
6. Log in as an owner and open a few orders, a payment screenshot and a partner's wallet. Write down the date and result.

### Real restore after losing the server

Follow the drill above on the new server, using the newest off-site backup (`rclone copy <remote>/host/daily/<newest> .`, then `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:<passphrase file> -in <file> | tar -xf -`; each upload volume is restored with `tar -C <volume folder> -xzf <volume>.tar.gz`), then point DNS at the new server. Everything after that backup's time must be re-entered from paper or bank records. Tell finance which date the restore went back to.

## 5. Security incidents

- **A staff account may be misused:** suspend it on Accounts (this signs it out everywhere). Then read the audit log for that person. Account edits now record old and new phone, email, sponsor and status, and money actions record amounts.
- **A partner says their phone or password was changed without them:** the old number receives an SMS when the phone changes. Check `account.updated` in the audit log for who did it, restore the right phone, and reset the password.
- **A secret leaked** (API key, SMTP password): change it at the provider, then in Service integrations (Super Admin only). For `APP_KEY`, contact the developers first: changing it without re-encrypting makes encrypted data unreadable.
- **Suspected database tampering:** run `php artisan audit:verify-integrity`. The ledger and audit tables reject edits and deletes at database level.

## 5a. Disk space, PHP workers and firewall (shared server)

The VPS also runs other companies' containers. Never run `docker system prune -a`, `docker volume prune` or anything that touches containers, images or volumes that are not Madina's.

- **Pruning:** `infra/ops/prune-host.sh` lists what it would remove; `--apply` removes it. It keeps the newest 10 pre-deploy dumps, storage tars and release tarballs and the last 3 release folders in `/root/madina-predeploy`, removes only Madina's dangling images, and Docker build cache unused for 30 days (7 days above 80% disk). Weekly root cron: `0 5 * * 0 root /opt/madina-nhac/infra/ops/prune-host.sh --apply >> /var/log/madina-prune.log 2>&1`. `ops:heartbeat` alerts at 85% disk use.
- **PHP workers:** the app container runs up to 20 PHP-FPM workers (`infra/php-fpm.production.conf`). Product photos, videos and the photo model are served by the app after its permission check. nginx-served media (`MADINA_NGINX_PROTECTED_FILES=true`) is OFF by default because private files are 0600 owned by www-data and nginx cannot read them (every picture returned 403); turn it on only after files are stored 0640 and nginx joins the www-data group. Raise `pm.max_children` only after `free -m` shows room (each worker uses about 60–120 MB).
- **Firewall tidy-up (manual, operator's decision; nothing here changes it):** `ufw status numbered` shows duplicate `80/tcp` and `443/tcp` rules next to `Nginx Full`, and `5432` allowed from `172.16.0.0/12`. Madina's database publishes no host port, so neither is an exposure today. To tidy: delete the duplicate 80/443 rules one at a time by number (`ufw delete <n>`, re-run `ufw status numbered` after each, since numbers shift), keep `Nginx Full` and the SSH rate limit, and remove the 5432 rule only after checking that no other project on the server needs it.

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
