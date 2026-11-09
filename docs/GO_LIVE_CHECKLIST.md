# Madina go-live checklist

Use this before enabling financial posting on a production deployment. Keep `MADINA_FINANCIAL_ACTIVATION=false` until every gate below is verified.

## Environment

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY` — `app:readiness` must not warn `debug_mode_enabled` or `app_environment_not_production`
- [ ] `APP_URL` and `APP_DOMAIN` match the live public host over HTTPS; portal subdomains resolve (`dealer.`, `subdealer.`, `manage.`, `sadmin.`) — `app:readiness` must not warn `app_url_not_https` or `app_domain_mismatches_app_url`
- [ ] `SESSION_SECURE_COOKIE=true` and `SESSION_ENCRYPT=true` on the live HTTPS host — `app:readiness` must not warn `session_cookies_not_hardened`
- [ ] Strong `APP_KEY` present — `app:readiness` must not warn `app_key_missing_or_weak`
- [ ] Behind TLS termination, set `TRUSTED_PROXIES` to the load-balancer/proxy CIDRs — `app:readiness` must not warn `trusted_proxies_not_configured`
- [ ] PostgreSQL is dedicated (not shared with another app); backups scheduled and restore-tested — `MADINA_BACKUPS_ENABLED` + strong `MADINA_BACKUP_PASSWORD`; `app:readiness` must not warn `backups_not_configured` or `verified_backup_missing`
- [ ] Queue worker running: `php artisan queue:work --queue=imports,default --sleep=3 --tries=1 --timeout=1800` — durable driver (not `sync`/`null`); `app:readiness` must not warn `queue_driver_is_sync` or `queue_worker_heartbeat_missing`
- [ ] Scheduler running: `php artisan schedule:work` (or cron equivalent); `app:readiness` must not warn `scheduler_heartbeat_missing`
- [ ] `php artisan app:readiness` reports PASS for migrations, DB timezone UTC, cache, private storage, preview posting disabled
- [ ] `composer audit --locked` and `npm audit` reviewed; known issues accepted or remediated (local hardening verification: both reported 0 vulnerabilities)
- [ ] Frontend built: `npm ci --ignore-scripts && npm run build`
- [ ] Off-site backup copy configured: `MADINA_BACKUP_OFFSITE_DISK=backups-offsite` with `MADINA_BACKUP_OFFSITE_PATH` on a volume from another machine; one backup restored on a separate database (restore drill in `docs/RUNBOOK.md`)
- [ ] `TRUSTED_PROXIES` lists the real proxy network and is **not** `*`
- [ ] `example.com` replaced with the real domain in `infra/nginx.production.conf`
- [ ] Critical alerts reach a person: `,slack` added to `LOG_STACK` with `LOG_SLACK_WEBHOOK_URL`, or the hosting log service alerts on `CRITICAL`; send a test alert
- [ ] Error tracking on: create a free account at [sentry.io](https://sentry.io), add a **Laravel** project, copy its DSN and paste it as `SENTRY_LARAVEL_DSN` on the live server (keep `SENTRY_TRACES_SAMPLE_RATE=0`). Personal data is never sent. `app:readiness` must not warn `error_tracking_not_configured`
- [ ] Two-factor login (TOTP) enabled for every owner and finance account
- [ ] Every staff role reviewed: only the Super Admin changes Service integrations; only owners change auto-approval

## Policy and money (activation gate)

- [ ] Commission policy draft reviewed and dual-control published for the live schedule — `app:readiness` must not warn `commission_policy_unreviewed`
- [ ] Opening balances imported and reconciled against bank / cash books — `app:readiness` must not warn `opening_balances_unverified` or `opening_balances_stale`
- [ ] Payment accounts configured (bank / MFS) with correct labels — at least one active account with collections enabled and one with payouts enabled; `app:readiness` must not warn `payment_collection_accounts_missing` or `payment_payout_accounts_missing`
- [ ] Retained / owner benefits fund visibility confirmed for owners only
- [ ] Partner benefit overrides (unconditional salary / rank) audited — none unintended; `app:readiness` must not warn `partner_benefit_overrides_active` (or each remaining override is documented)
- [ ] Scheduler and queue worker are running (cron `* * * * * php artisan schedule:run` and a supervised `php artisan queue:work`). Automatic month-end salary, royalty and rank rewards (`finance:auto-approve`, hourly), the nightly audit check and the money reconciliation all depend on the scheduler
- [ ] Automatic release of area commission decided: with `MADINA_AUTO_RELEASE_QUOTA_HOLDS=true` a held area share is paid as soon as the area dealer's monthly quota for that month is met; without it Finance releases each one by hand. Set `MADINA_AUTO_RELEASE_MATURED=true` too if a cooling-off period is configured
- [ ] Owner decision recorded for area shares still held from months before November 2026: they stay held. From November 2026 a share whose target was missed goes to the company profit fund at month end
- [ ] Payout TDS set in the commission rules if the owner wants it (percentage and monthly threshold; the default is 0%). TDS is worked out on the partner's monthly payout total
- [ ] Only after the above: set `MADINA_FINANCIAL_ACTIVATION=true` on the production host and re-run `app:readiness`
- [ ] Confirm `MADINA_PREVIEW_MODE=false` on production — `app:readiness` must not warn `preview_mode_enabled`

## Access and portals

- [ ] Owner (`sadmin`) and company (`manage`) staff accounts created with least privilege
- [ ] Seed dealer / sub-dealer accounts use real place codes and unique refer codes — partner Excel import requires `place_code`; `app:readiness` must not warn `partners_missing_place_codes`, `area_dealer_assignments_missing`, or `area_dealer_coverage_incomplete`
- [ ] Public partnership page + `/join/{refer_code}` deep-link smoke-tested
- [ ] Partner home shows referral share URL; full-report PDF downloads for dealer and sub-dealer
- [ ] Locale (EN/BN) toggles on each portal without breaking forms
- [ ] Password reset emails land on the correct portal host
- [ ] Phone/browser notifications: run `php artisan webpush:vapid --show` once on the server, paste the two keys as `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` (plus `VAPID_SUBJECT=mailto:<owner email>`), keep them unchanged afterwards, and keep the queue worker running. Then on a phone open Notifications → "Turn on notifications" and check that a test notice arrives. With the keys empty the button stays hidden

## Operations smoke

- [ ] Create → approve → dispatch → deliver one dealer order on a non-production clone first
- [ ] Commission allocations appear under the expected heads after delivery (clone only until activation)
- [ ] Withdrawal request → staff review → payout receipt path works
- [ ] Manual payment claim verification works
- [ ] Data import templates download; a dry-run import succeeds
- [ ] Report exports (xlsx/pdf) succeed for permitted staff
- [ ] Every deploy runs `php artisan migrate --force`. The load-test speed fixes add three database indexes (refund debts, delivered-order totals, refunded batches); without them deliveries and wallet credits slow down as sales grow
- [ ] After the first month end: Settlements shows the closed month settled (salary per division, royalty per rank, sub-dealer salary), partners got one "salary credited" notice each, and `php artisan finance:readiness` passes
- [ ] Every partner, branch, area seat and staff area has a place code; `app:readiness` must not warn `records_missing_place_codes`. Set the head-office branch's place code by hand

## Bangladesh field readiness

- [ ] SMS: enable a Service integrations SMS provider (base URL + API key) and/or set a non-log `MADINA_SMS_DRIVER`; verify `app:readiness` clears SMS warnings
- [ ] Partner login OTP verified end-to-end (`MADINA_LOGIN_OTP=true`) with a real provider before go-live
- [ ] **Turn on SMS and every feature that was switched off for testing** (owner Security page → SMS sign-in code ON; SMS provider enabled; birthday SMS and other SMS notices confirmed) — `app:readiness` must not warn `sms_login_code_switched_off`
- [ ] Every account has its own valid Bangladeshi mobile number, the Super Admin included (there is no sign-in without one) — `app:readiness` must not warn `accounts_missing_mobile` or `accounts_sharing_mobile`
- [ ] Emergency only: if SMS breaks after go-live and nobody can sign in, run `php artisan madina:sms-login off` on the server, fix SMS, then turn the switch back on
- [ ] SMTP (or SES/Postmark) uses a real host — not Mailpit/`mail`/`localhost` — and `MAIL_FROM_ADDRESS` is not `@example.com`
- [ ] `php artisan app:readiness` lists no unresolved go-live mail/SMS warnings (or each warning has an accepted ops waiver)
- [ ] Password-reset and owner-contact mail verified on the live portal hosts
- [ ] Place-code catalog seeded (`php artisan db:seed --class=BangladeshPlaceCodeSeeder`) — `app:readiness` must not warn `place_code_catalog_incomplete` (8 divisions + 64 districts minimum)
- [ ] BDRIS / identity lookup credentials set only if the feature is contracted
- [ ] Public dealer directory GPS + district filters verified on mobile

## Rollback

- [ ] Document how to flip `MADINA_FINANCIAL_ACTIVATION=false` and drain queues (steps below kept with the runbook)
- [ ] Keep a known-good DB dump taken immediately before activation (`php artisan app:backup` with a strong `MADINA_BACKUP_PASSWORD`, then `php artisan app:backup-verify`)
- [ ] Owner contact for dual-control emergency freeze is listed offline

### Emergency freeze (financial posting)

1. Set `MADINA_FINANCIAL_ACTIVATION=false` (and keep `MADINA_PREVIEW_MODE=false`) on the production host / secret store.
2. Signal workers to exit after the current job: `php artisan queue:restart`, then stop `queue:work` / scheduler processes until config is reloaded.
3. Confirm the gate: `php artisan app:readiness` must show `financial activation verified` PASS while activation remains off; `php artisan finance:readiness` inspects reconciliation without posting.
4. Do **not** run `php artisan finance:release-matured`, `php artisan finance:release-quota-holds`, or `madina:demo` during a freeze.
5. Restore from the pre-activation backup only after owner dual-control approval; verify with `php artisan app:backup-verify` before bringing workers back.
